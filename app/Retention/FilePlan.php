<?php

// OpenRiC Workspace - the institution's file plan, a record's place in it, and file plans imported from a spreadsheet,
// a listing of folders or XML (roadmap phase 13c, ADR 0015).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's FilePlanService and FilePlanImportService. A node is a function, activity, series, sub-series or
// class, with its code (unique in the institution), title, the disposal class its records take, and, as an imported
// plan writes them, a retention period and disposal action. Decided by Dr Johan Pieterse on 2 November 2026: a node
// may be one of the institution's functions or activities, and a record is placed in one node explicitly, where
// Heratio found a node's records by disposal class or identifier prefix. A record placed in a node that is a function
// or activity is exported as documenting it (rico:documents); otherwise its place is the Workspace's own and is listed
// among what an export does not carry. A node with a disposal class gives it to a record placed in it that has none, once the class's schedule is approved.
// Imports: a spreadsheet (CSV or Excel), its columns found by their headings as Heratio finds them, the parent read
// from the code (A/1/2 under A/1); a listing of folders, one path a line, each folder a node (Heratio read a folder
// of the server; here the listing is uploaded); XML, either EAD (each c or c01 to c12 with its unitid and unittitle)
// or any nesting of elements with a code and title. Placing records by code is a choice at import: a record whose
// reference code begins with a node's code, and has no place yet, is placed in the longest such node.

namespace App\Retention;

use App\Audit\AuditLog;
use App\Models\Activity;
use App\Models\Concept;
use App\Models\DisposalClass;
use App\Models\FilePlanImport;
use App\Models\FilePlanNode;
use App\Models\FilePlanPlacement;
use App\Models\RecordResource;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class FilePlan
{
    /** Heratio's detectColumnMapping: a heading containing one of these words names the field. */
    private const HEADINGS = ['code' => ['code', 'ref', 'reference', 'number', 'classification', 'class', 'no', 'num'], 'title' => ['title', 'name', 'series', 'heading', 'subject'],
        'description' => ['desc', 'description', 'scope', 'note', 'notes', 'content'], 'retention_period' => ['retention', 'period', 'keep', 'duration', 'years'],
        'disposal_action' => ['disposal', 'action', 'dispose', 'disposition', 'fate']];

    /** Words for a disposal action in an imported plan, to the disposal-action-type vocabulary. */
    private const ACTIONS = ['destroy' => 'destroy', 'destruction' => 'destroy', 'dispose' => 'destroy', 'transfer' => 'transfer', 'archive' => 'transfer', 'permanent' => 'transfer',
        'review' => 'review', 'retain' => 'retain', 'keep' => 'retain'];

    public function tree(string $institutionId): Collection
    {
        return FilePlanNode::where('institution_id', $institutionId)->with('nodeType', 'status', 'activity', 'disposalClass')->withCount('placements')
            ->orderBy('position')->orderBy('code')->get();
    }

    public function save(User $actor, array $data, ?FilePlanNode $node = null): FilePlanNode
    {
        $institution = $node?->institution_id ?? $actor->institution_id;
        foreach (['code' => __('Give the node a code.'), 'title' => __('Give the node a title.')] as $k => $message) {
            if (trim((string) ($data[$k] ?? '')) === '') {
                throw new InvalidArgumentException($message);
            }
        }
        $code = trim($data['code']);
        if (FilePlanNode::where(['institution_id' => $institution, 'code' => $code])->when($node, fn ($q) => $q->whereKeyNot($node->id))->exists()) {
            throw new InvalidArgumentException(__('Another node has the code :code.', ['code' => $code]));
        }
        $parent = ($data['parent_id'] ?? '') ? FilePlanNode::where('institution_id', $institution)->find($data['parent_id']) ?? throw new InvalidArgumentException(__('Choose a node of this file plan.')) : null;
        if ($parent && $node && ($parent->id === $node->id || in_array($parent->id, $this->descendantIds($node), true))) {
            throw new InvalidArgumentException(__('A node cannot be placed inside itself.'));
        }
        $activity = ($data['activity_id'] ?? '') ? Activity::where('institution_id', $institution)->find($data['activity_id']) ?? throw new InvalidArgumentException(__('Choose a function or activity of the institution.')) : null;
        $class = ($data['disposal_class_id'] ?? '') ? $this->disposalClass($institution, (string) $data['disposal_class_id']) : null;
        $fields = ['parent_id' => $parent?->id, 'activity_id' => $activity?->id, 'node_type_id' => $this->concept('file-plan-node-type', (string) ($data['node_type'] ?? 'series')),
            'code' => mb_substr($code, 0, 50), 'title' => mb_substr(trim($data['title']), 0, 255), 'description' => trim((string) ($data['description'] ?? '')) ?: null, 'disposal_class_id' => $class?->id,
            'retention_period' => trim((string) ($data['retention_period'] ?? '')) ?: null,
            'disposal_action_id' => ($data['disposal_action'] ?? '') !== '' ? $this->concept('disposal-action-type', (string) $data['disposal_action']) : null,
            'status_id' => $this->concept('file-plan-status', (string) ($data['status'] ?? 'active')), 'source_department' => trim((string) ($data['source_department'] ?? '')) ?: null,
            'source_agency_code' => trim((string) ($data['source_agency_code'] ?? '')) ?: null, 'position' => (int) ($data['position'] ?? $node?->position ?? 0)];

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $node ? tap($node)->update($fields)
            : FilePlanNode::create($fields + ['institution_id' => $institution, 'created_by_id' => $actor->id])));
    }

    /** Remove a node with no nodes under it and no records in it. */
    public function delete(User $actor, FilePlanNode $node): void
    {
        if ($node->children()->exists() || $node->placements()->exists()) {
            throw new InvalidArgumentException(__('Move its nodes and records first.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $node->delete()));
    }

    /** Place the record in the node, in place of any earlier place; a node's disposal class goes to a record with none. */
    public function place(User $actor, RecordResource $record, FilePlanNode $node, ?string $ruleId = null): FilePlanPlacement
    {
        if ($node->institution_id !== $record->institution_id) {
            throw new InvalidArgumentException(__('Choose a node of this file plan.'));
        }
        if ($node->status->notation !== 'active') {
            throw new InvalidArgumentException(__('That node is closed; it takes no more records.'));
        }

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $record, $node, $ruleId) {
            $placement = FilePlanPlacement::updateOrCreate(['record_resource_id' => $record->id], ['file_plan_node_id' => $node->id, 'rule_id' => $ruleId, 'placed_by_id' => $actor->id, 'placed_at' => now()]);
            self::giveClass($actor, $record, $node->disposalClass);

            return $placement;
        }));
    }

    /** Give the record the class when it has none and the class's schedule is approved (only such a class is given to records). */
    public static function giveClass(User $actor, RecordResource $record, ?DisposalClass $class, ?string $eventDate = null): void
    {
        if ($class && $class->schedule->status->notation === 'approved' && ! DB::table('record_disposal_classes')->where('record_resource_id', $record->id)->exists()) {
            app(RetentionService::class)->assign($record, $class, $eventDate, $actor);
        }
    }

    public function unplace(User $actor, RecordResource $record): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => FilePlanPlacement::where('record_resource_id', $record->id)->delete()));
    }

    public function descendantIds(FilePlanNode $node): array
    {
        $ids = [];
        $frontier = [$node->id];
        while ($frontier) {
            $frontier = FilePlanNode::whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }

    /** The node's parents, from the top. */
    public function breadcrumb(FilePlanNode $node): array
    {
        $trail = [];
        for ($n = $node->parent; $n && count($trail) < 50; $n = $n->parent) {
            array_unshift($trail, $n);
        }

        return $trail;
    }

    // Imports.

    public function importSpreadsheet(User $actor, UploadedFile $file, array $options): FilePlanImport
    {
        $sheet = IOFactory::load($file->getRealPath())->getActiveSheet()->toArray(null, true, true, false);
        $headings = array_map(fn ($h) => mb_strtolower(trim((string) $h)), array_shift($sheet) ?? []);
        $map = [];
        foreach (self::HEADINGS as $field => $words) {
            foreach ($headings as $i => $h) {
                if ($h !== '' && ! in_array($i, $map, true) && collect($words)->contains(fn ($w) => str_contains($h, $w))) {
                    $map[$field] = $i;
                    break;
                }
            }
        }
        if (! isset($map['code'], $map['title'])) {
            throw new InvalidArgumentException(__('The sheet needs a column for the code and one for the title.'));
        }
        $rows = collect($sheet)->map(fn ($r) => array_map(fn ($i) => trim((string) ($r[$i] ?? '')), $map))->filter(fn ($r) => $r['code'] !== '' || $r['title'] !== '')->values()->all();
        $separator = $this->separator(array_column($rows, 'code'));
        $nodes = array_map(fn ($r) => $r + ['parent_code' => str_contains($r['code'], $separator) ? substr($r['code'], 0, strrpos($r['code'], $separator)) : null], $rows);

        return $this->import($actor, 'spreadsheet', $file->getClientOriginalName(), $nodes, $options, array_map(fn ($i) => $headings[$i], $map));
    }

    /** One folder path a line (Finance/Budgets/2026); each folder a node, its code its path. */
    public function importListing(User $actor, UploadedFile $file, array $options): FilePlanImport
    {
        $nodes = [];
        foreach (preg_split('/\R/', (string) file_get_contents($file->getRealPath())) as $line) {
            $parts = array_values(array_filter(array_map('trim', preg_split('#[/\\\\]#', trim($line))), fn ($p) => $p !== ''));
            for ($i = 0; $i < count($parts); $i++) {
                $code = implode('/', array_slice($parts, 0, $i + 1));
                $nodes[$code] ??= ['code' => $code, 'title' => $parts[$i], 'parent_code' => $i ? implode('/', array_slice($parts, 0, $i)) : null];
            }
        }

        return $this->import($actor, 'directory', $file->getClientOriginalName(), array_values($nodes), $options);
    }

    public function importXml(User $actor, UploadedFile $file, array $options): FilePlanImport
    {
        $dom = new \DOMDocument;
        if (! @$dom->loadXML((string) file_get_contents($file->getRealPath()), LIBXML_NONET)) {
            throw new InvalidArgumentException(__('That is not well-formed XML.'));
        }
        $nodes = [];
        $ead = $dom->getElementsByTagName('dsc')->length > 0;
        $walk = function (\DOMElement $el, ?string $parent) use (&$walk, &$nodes, $ead) {
            foreach ($el->childNodes as $child) {
                if (! $child instanceof \DOMElement) {
                    continue;
                }
                $isNode = $ead ? (bool) preg_match('/^c(0[1-9]|1[0-2])?$/', $child->localName) : ($child->hasAttribute('code') || $child->hasAttribute('id') || $child->hasAttribute('ref'));
                if (! $isNode) {
                    $walk($child, $parent);

                    continue;
                }
                $first = fn (string $tag) => trim((string) ($child->getElementsByTagName($tag)->item(0)?->textContent ?? ''));
                $code = $ead ? $first('unitid') : ($child->getAttribute('code') ?: $child->getAttribute('id') ?: $child->getAttribute('ref'));
                $title = $ead ? $first('unittitle') : ($child->getAttribute('title') ?: $first('title') ?: $code);
                $nodes[] = ['code' => $code, 'title' => $title, 'parent_code' => $parent, 'description' => $ead ? $first('scopecontent') : $first('description')];
                $walk($child, $code !== '' ? $code : $parent);
            }
        };
        $walk($dom->documentElement, null);

        return $this->import($actor, 'xml', $file->getClientOriginalName(), $nodes, $options);
    }

    /** Create the nodes, parents first; a row whose code is taken, or whose parent is missing, is an error, not a guess. */
    private function import(User $actor, string $source, string $filename, array $rows, array $options, ?array $mapping = null): FilePlanImport
    {
        $errors = [];
        $created = 0;
        $codes = array_column($rows, 'code');
        $institution = $actor->institution_id;
        $import = DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $source, $filename, $rows, $options, $mapping, $codes, $institution, &$errors, &$created) {
            $import = FilePlanImport::create(['institution_id' => $institution, 'source_id' => $this->concept('file-plan-import-source', $source), 'source_filename' => mb_substr($filename, 0, 255),
                'department' => trim((string) ($options['department'] ?? '')) ?: null, 'agency_code' => trim((string) ($options['agency_code'] ?? '')) ?: null,
                'status_id' => $this->concept('file-plan-import-status', 'completed'), 'total_nodes' => count($rows), 'column_mapping' => $mapping, 'imported_by_id' => $actor->id, 'created_at' => now()]);
            $ids = FilePlanNode::where('institution_id', $institution)->pluck('id', 'code')->all();
            $type = $this->concept('file-plan-node-type', (string) ($options['node_type'] ?? 'series'));
            $active = $this->concept('file-plan-status', 'active');
            usort($rows, fn ($a, $b) => substr_count($a['code'], '/') <=> substr_count($b['code'], '/') ?: strlen($a['code']) <=> strlen($b['code']));
            foreach ($rows as $i => $r) {
                $problem = match (true) {
                    $r['code'] === '' => __('A row has no code.'),
                    $r['title'] === '' => __(':code has no title.', ['code' => $r['code']]),
                    isset($ids[$r['code']]) => __(':code is in the file plan already.', ['code' => $r['code']]),
                    count(array_keys($codes, $r['code'], true)) > 1 => __(':code appears more than once.', ['code' => $r['code']]),
                    $r['parent_code'] !== null && ! isset($ids[$r['parent_code']]) => __(':code is under :parent, which is not in the file plan.', ['code' => $r['code'], 'parent' => $r['parent_code']]),
                    default => null,
                };
                if ($problem) {
                    $errors[] = $problem;

                    continue;
                }
                $action = $this->action($r['disposal_action'] ?? '');
                $ids[$r['code']] = FilePlanNode::create(['institution_id' => $institution, 'parent_id' => $r['parent_code'] ? $ids[$r['parent_code']] : null, 'node_type_id' => $type,
                    'code' => mb_substr($r['code'], 0, 50), 'title' => mb_substr($r['title'], 0, 255), 'description' => ($r['description'] ?? '') ?: null, 'retention_period' => ($r['retention_period'] ?? '') ?: null,
                    'disposal_action_id' => $action ? $this->concept('disposal-action-type', $action) : null, 'status_id' => $active, 'source_department' => $import->department,
                    'source_agency_code' => $import->agency_code, 'import_id' => $import->id, 'position' => $i, 'created_by_id' => $actor->id])->id;
                $created++;
            }
            $import->update(['imported_nodes' => $created, 'errors' => $errors ?: null, 'completed_at' => now(),
                'status_id' => $this->concept('file-plan-import-status', $created === 0 && $rows ? 'failed' : ($errors ? 'partial' : 'completed'))]);

            return $import;
        }));
        if (! empty($options['place_records']) && $import->imported_nodes > 0) {
            $import->update(['linked_records' => $this->placeByCode($actor, $import)]);
        }

        return $import;
    }

    /** Place records with no place yet whose reference code (without the repository's code) begins with an imported node's code. */
    private function placeByCode(User $actor, FilePlanImport $import): int
    {
        $nodes = FilePlanNode::where('import_id', $import->id)->with('status')->get()->sortByDesc(fn ($n) => strlen($n->code));
        $placed = 0;
        RecordResource::where('institution_id', $import->institution_id)->whereNull('deleted_at')->whereDoesntHave('filePlanPlacement')->with('repository')->chunk(500, function ($records) use ($actor, $nodes, &$placed) {
            foreach ($records as $record) {
                $code = (string) $record->referenceCode();
                $code = ltrim(preg_replace('/^'.preg_quote((string) $record->repository?->identifier, '/').'\s+/', '', $code));
                $node = $nodes->first(fn ($n) => $code === $n->code || str_starts_with($code, $n->code.'/') || str_starts_with($code, $n->code.'.') || str_starts_with($code, $n->code.'-'));
                if ($node) {
                    $this->place($actor, $record, $node);
                    $placed++;
                }
            }
        });

        return $placed;
    }

    private function separator(array $codes): string
    {
        $counts = ['/' => 0, '-' => 0, '.' => 0];
        foreach ($codes as $c) {
            foreach ($counts as $sep => $n) {
                $counts[$sep] = $n + substr_count($c, $sep);
            }
        }
        arsort($counts);

        return reset($counts) > 0 ? array_key_first($counts) : '/';
    }

    private function action(string $words): ?string
    {
        $words = mb_strtolower($words);
        foreach (self::ACTIONS as $word => $notation) {
            if ($words !== '' && str_contains($words, $word)) {
                return $notation;
            }
        }

        return null;
    }

    private function disposalClass(string $institution, string $id): DisposalClass
    {
        return DisposalClass::whereKey($id)->whereHas('schedule', fn ($q) => $q->where('institution_id', $institution))->first()
            ?? throw new InvalidArgumentException(__('Choose a disposal class of this institution.'));
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
