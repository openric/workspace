<?php

// OpenRiC Workspace - the steps PSIS's ingest wizard has beyond the import's upload, dry run and commit (roadmap phase
// 22a): mapping a spreadsheet's headings to the columns the import reads, with mappings kept by name; entering rows in
// a grid under a record set; undoing a committed import; and the import as a BagIt bag.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Exchange\Import\ImportService;
use App\Ingest\ColumnMapping;
use App\Ingest\ImportBag;
use App\Ingest\ImportUndo;
use App\Models\Concept;
use App\Models\Import;
use App\Models\ImportMapping;
use App\Models\RecordResource;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class IngestController extends Controller
{
    /** The columns the grid offers, in AtoM's names (PSIS's grid entry), the title first. */
    public const GRID = ['title', 'identifier', 'levelOfDescription', 'eventDates', 'extentAndMedium', 'scopeAndContent', 'subjectAccessPoints'];

    public function mapping(Request $request, Import $import): View
    {
        $this->own($request, $import);
        $headings = ColumnMapping::headings($import->content);
        $profile = $request->query('profile') ? ImportMapping::where(['institution_id' => $import->institution_id, 'format' => $import->format])->find($request->query('profile')) : null;
        $suggested = ColumnMapping::suggest($import->format, $headings);

        return view('imports.mapping', ['import' => $import, 'headings' => $headings, 'suggested' => $suggested, 'profile' => $profile,
            'samples' => $this->samples($import->content), 'targets' => ColumnMapping::targets($import->format),
            'profiles' => ImportMapping::where(['institution_id' => $import->institution_id, 'format' => $import->format])->orderBy('name')->get()]);
    }

    public function saveMapping(Request $request, Import $import): RedirectResponse
    {
        $this->own($request, $import);
        abort_if($import->committed_at !== null, 404);
        $data = $request->validate(['columns' => ['required', 'array'], 'columns.*.target' => ['nullable', 'string', 'max:64'], 'columns.*.default' => ['nullable', 'string', 'max:500'],
            'columns.*.transform' => ['nullable', 'string', 'max:20'], 'keep_as' => ['nullable', 'string', 'max:255']]);
        $headings = ColumnMapping::headings($import->content);
        // The form is keyed by each heading's position, so any heading text survives the round trip.
        $mapping = [];
        foreach ($headings as $i => $heading) {
            $mapping[$heading] = $data['columns'][$i] ?? [];
        }
        try {
            $content = ColumnMapping::apply($import->format, $import->content, $mapping);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['mapping' => $e->getMessage()]);
        }
        if ($name = trim((string) ($data['keep_as'] ?? ''))) {
            ImportMapping::updateOrCreate(['institution_id' => $import->institution_id, 'name' => $name],
                ['format' => $import->format, 'columns' => $mapping, 'created_by_id' => $request->user()->id]);
        }
        $import->update(['content' => $content, 'options' => [...$import->options, 'unmapped' => $import->content, 'mapping' => $mapping]]);
        app(ImportController::class)->queue($import, false);

        return redirect()->route('imports.show', $import)->with('status', __('Mapped. Dry run done: nothing has been written yet. Read the report, then import.'));
    }

    public function undo(Request $request, Import $import): RedirectResponse
    {
        $this->own($request, $import);
        try {
            $done = app(ImportUndo::class)->run($import, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['import' => $e->getMessage()]);
        }

        return back()->with('status', trans_choice(':count entry deleted.|:count entries deleted.', $done['deleted'], ['count' => $done['deleted']])
            .($done['kept'] ? ' '.trans_choice(':count kept, as the report says.|:count kept, as the report says.', count($done['kept']), ['count' => count($done['kept'])]) : ''));
    }

    public function bag(Request $request, Import $import): BinaryFileResponse
    {
        $this->own($request, $import);
        [$path, $name] = app(ImportBag::class)->build($import);

        return response()->download($path, $name, ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    public function grid(Request $request): View
    {
        $parent = $this->parent($request, (string) $request->query('parent'));

        return view('imports.grid', ['parent' => $parent, 'columns' => self::GRID, 'levels' => Concept::choices(Vocab::BASE.'level-of-description/')->get()]);
    }

    /** The grid's rows as an AtoM CSV, imported under the record set through the same dry run and report (PSIS's Validate & save). */
    public function saveGrid(Request $request): RedirectResponse
    {
        $parent = $this->parent($request, (string) $request->input('parent'));
        $data = $request->validate(['rows' => ['required', 'array', 'max:500'], 'rows.*' => ['array'], 'rows.*.*' => ['nullable', 'string', 'max:20000'],
            'language' => ['required', 'string', 'max:35']]);
        $rows = array_values(array_filter($data['rows'], fn ($row) => trim(implode('', array_map('strval', $row))) !== ''));
        if ($rows === []) {
            return back()->withInput()->withErrors(['grid' => __('Fill in at least one row.')]);
        }
        $out = fopen('php://memory', 'r+');
        fputcsv($out, ['legacyId', ...self::GRID], escape: '');
        foreach ($rows as $n => $row) {
            fputcsv($out, ['grid-'.($n + 1), ...array_map(fn ($c) => trim((string) ($row[$c] ?? '')), self::GRID)], escape: '');
        }
        rewind($out);
        $content = (string) stream_get_contents($out);
        fclose($out);
        $user = $request->user();
        $name = __('Grid entry under :title', ['title' => mb_substr((string) EntityKinds::label($parent), 0, 120)]).'.csv';
        $import = Import::create(['institution_id' => $user->institution_id, 'user_id' => $user->id, 'format' => 'csv', 'source' => 'grid '.now()->format('Y-m-d H:i:s'),
            'file_name' => $name, 'file_sha256' => hash('sha256', $content), 'content' => $content, 'language' => $data['language'], 'repository_id' => $parent->repository_id
                ?? collect($parent->ancestors())->first()?->repository_id, 'parent_id' => $parent->id,
            'options' => ['agent_type' => null, 'create_terms' => true, 'match_identifier' => false, 'default_level' => 'item']]);
        app(ImportController::class)->queue($import, false);

        return redirect()->route('imports.show', $import)->with('status', __('Dry run done: nothing has been written yet. Read the report, then import.'));
    }

    /** First rows of each column, to recognise it by. @return array<int, list<string>> */
    private function samples(string $content): array
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $content));
        rewind($stream);
        fgetcsv($stream, escape: '');
        $samples = [];
        for ($n = 0; $n < 3 && ($row = fgetcsv($stream, escape: '')) !== false; $n++) {
            foreach ($row as $i => $cell) {
                if (trim((string) $cell) !== '') {
                    $samples[$i][] = mb_substr(trim((string) $cell), 0, 60);
                }
            }
        }
        fclose($stream);

        return $samples;
    }

    private function parent(Request $request, string $id): RecordResource
    {
        $parent = RecordResource::where('institution_id', $request->user()->institution_id)->whereNull('deleted_at')->find($id) ?? abort(404);
        abort_unless($request->user()->may('create', 'description', $parent), 403);

        return $parent;
    }

    private function own(Request $request, Import $import): void
    {
        abort_unless($import->institution_id === $request->user()->institution_id, 404);
        abort_unless($request->user()->mayAnywhere('create', ImportService::permission($import->format)), 403);
    }
}
