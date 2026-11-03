<?php

// OpenRiC Workspace - duplicate archival descriptions: found by rules, reviewed, and merged (roadmap phase 22b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ahg-dedupe (its rules, the scan, the review queue and RecordMergeService). Rules are rows: similar
// titles and the same or similar identifiers within a repository, the same date of creation and creator, the same
// master file; each with a threshold, and Heratio's defaults made where an institution has none. A scan writes each
// pair found once, as waiting for review, with its score and why; a pair dismissed stays dismissed. Merging keeps one
// unit and moves into it what the other has: its units inside (after the kept unit's own), an instantiation of its own for
// each of the other's from the same master file (an instantiation stays with the unit it instantiates), relations
// (dropping those between the two and those the kept unit has already), the names it lacks (an authorised name
// becoming another form), the values it lacks, dates and identifiers (a local identifier kept as a previous one).
// The other unit is then deleted as a merge: its IRI answers that it was merged into the unit kept (ADR 0013), as a
// merged agent's does. A declared record is not merged away. Changed: Heratio compared titles across the catalogue;
// here within each repository, where a duplicate is made.

namespace App\Dedupe;

use App\Audit\AuditLog;
use App\Description\DeprecationService;
use App\Description\InstantiationService;
use App\Files\FileStore;
use App\Models\Concept;
use App\Models\DescriptionValue;
use App\Models\DuplicateDetection;
use App\Models\DuplicateRule;
use App\Models\Identifier;
use App\Models\Instantiation;
use App\Models\Name;
use App\Models\RecordResource;
use App\Models\Relation;
use App\Models\RicDate;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Relations\RelationService;
use App\Retention\Declarations;
use App\Support\Vocab;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Duplicates
{
    /** Heratio's default rules: type, name, threshold. */
    private const DEFAULT_RULES = [['identifier-exact', 'Same identifier', 1.0], ['title-similarity', 'Similar titles', 0.9], ['identifier-fuzzy', 'Similar identifiers', 0.95],
        ['date-creator', 'Same date and creator', 1.0], ['checksum', 'Same master file', 1.0]];

    public function __construct(private RelationService $relations) {}

    public function rules(string $institutionId): Collection
    {
        if (! DuplicateRule::where('institution_id', $institutionId)->exists()) {
            foreach (self::DEFAULT_RULES as $n => [$type, $name, $threshold]) {
                DuplicateRule::create(['institution_id' => $institutionId, 'name' => $name, 'rule_type_id' => $this->concept('duplicate-rule-type', $type),
                    'threshold' => $threshold, 'priority' => 100 - $n * 10]);
            }
        }

        return DuplicateRule::where('institution_id', $institutionId)->with('ruleType', 'repository')->orderByDesc('priority')->get();
    }

    public function saveRule(string $institutionId, array $data, ?DuplicateRule $rule = null): DuplicateRule
    {
        $threshold = (float) ($data['threshold'] ?? 0.8);
        ($threshold > 0 && $threshold <= 1) || throw new InvalidArgumentException(__('A threshold is more than 0 and at most 1.'));
        $fields = ['name' => trim((string) ($data['name'] ?? '')) ?: throw new InvalidArgumentException(__('Name the rule.')),
            'rule_type_id' => $this->concept('duplicate-rule-type', (string) ($data['rule_type'] ?? '')), 'threshold' => $threshold,
            'repository_id' => ($data['repository_id'] ?? '') ?: null, 'is_enabled' => ! empty($data['is_enabled']), 'priority' => (int) ($data['priority'] ?? 100)];

        return $rule ? tap($rule)->update($fields) : DuplicateRule::create($fields + ['institution_id' => $institutionId]);
    }

    /** Run every enabled rule; the pairs found that were not found before. */
    public function scan(string $institutionId): int
    {
        $records = RecordResource::where('institution_id', $institutionId)->whereNull('deleted_at')->with('names.nameType', 'identifiers.identifierType', 'dates.property')->get();
        // A unit's repository is its own, or its nearest ancestor's.
        $byId = $records->keyBy('id');
        $repositoryOf = [];
        $top = function (RecordResource $r) use (&$repositoryOf, $byId) {
            if (! array_key_exists($r->id, $repositoryOf)) {
                for ($p = $r; $p && $p->repository_id === null; $p = $p->parent_id ? $byId[$p->parent_id] ?? null : null);
                $repositoryOf[$r->id] = $p?->repository_id;
            }

            return $repositoryOf[$r->id];
        };
        $found = 0;
        foreach ($this->rules($institutionId)->where('is_enabled', true) as $rule) {
            $scope = $records->filter(fn ($r) => $rule->repository_id === null || $top($r) === $rule->repository_id);
            foreach ($this->pairs($rule, $scope, $top) as [$a, $b, $score, $details]) {
                $found += $this->record($institutionId, $a, $b, $score, $rule->ruleType, $details) ? 1 : 0;
            }
        }

        return $found;
    }

    /** @return iterable<array{0: string, 1: string, 2: float, 3: array}> */
    private function pairs(DuplicateRule $rule, Collection $records, callable $top): iterable
    {
        $threshold = $rule->threshold;
        switch ($rule->ruleType->notation) {
            case 'identifier-exact':
                foreach ($records->filter(fn ($r) => $r->identifier !== null)->groupBy(fn ($r) => $top($r).'|'.$r->parent_id.'|'.$r->identifier) as $group) {
                    yield from $this->combinations($group, fn () => [1.0, ['identifier' => $group->first()->identifier]]);
                }
                break;
            case 'identifier-fuzzy':
                $fuzzy = fn ($r) => $r->identifier === null ? null : ltrim(preg_replace('/[^a-z0-9]+/', '', mb_strtolower($r->identifier)), '0');
                foreach ($records->filter(fn ($r) => $fuzzy($r))->groupBy(fn ($r) => $top($r).'|'.$fuzzy($r)) as $group) {
                    yield from $this->combinations($group, fn ($a, $b) => $a->identifier === $b->identifier ? null : [max($threshold, 0.95), ['identifiers' => [$a->identifier, $b->identifier]]]);
                }
                break;
            case 'title-similarity':
                // ponytail: titles compared pairwise within a repository and a first letter; a large catalogue wants trigram search.
                $title = fn ($r) => self::normalise((string) $r->namesOfType('authorised')->first()?->value);
                foreach ($records->groupBy(fn ($r) => $top($r).'|'.mb_substr($title($r), 0, 1)) as $group) {
                    yield from $this->combinations($group, function ($a, $b) use ($title, $threshold) {
                        [$x, $y] = [$title($a), $title($b)];
                        if ($x === '' || $y === '') {
                            return null;
                        }
                        similar_text($x, $y, $percent);

                        return $percent / 100 >= $threshold ? [round($percent / 100, 4), ['titles' => [$a->namesOfType('authorised')->first()?->value, $b->namesOfType('authorised')->first()?->value]]] : null;
                    });
                }
                break;
            case 'date-creator':
                $creators = Relation::where('source_table', 'record_resources')->whereIn('source_id', $records->pluck('id'))
                    ->whereHas('relationType', fn ($q) => $q->where('notation', 'creation'))->get()->groupBy('source_id')->map(fn ($g) => $g->pluck('target_id')->sort()->implode(','));
                $created = fn ($r) => $r->dates->filter(fn ($d) => $d->property?->notation === 'creation')->pluck('normalized_value')->filter()->sort()->implode(',');
                foreach ($records->filter(fn ($r) => $created($r) !== '' && isset($creators[$r->id]))->groupBy(fn ($r) => $top($r).'|'.$created($r).'|'.$creators[$r->id]) as $group) {
                    yield from $this->combinations($group, fn ($a) => [1.0, ['date' => $created($a)]]);
                }
                break;
            case 'checksum':
                $shared = DB::table('digital_files')->join('instantiations', 'instantiations.id', '=', 'digital_files.instantiation_id')
                    ->join('concepts', 'concepts.id', '=', 'digital_files.use_id')->where('concepts.iri', Vocab::BASE.'file-use/master')
                    ->whereNull('instantiations.deleted_at')->whereIn('instantiations.record_resource_id', $records->pluck('id'))
                    ->select('digital_files.sha256', 'instantiations.record_resource_id')->get()->groupBy('sha256')->filter(fn ($g) => $g->pluck('record_resource_id')->unique()->count() > 1);
                foreach ($shared as $sha => $rows) {
                    yield from $this->combinations($records->whereIn('id', $rows->pluck('record_resource_id')->unique())->values(), fn () => [1.0, ['sha256' => $sha]]);
                }
                break;
        }
    }

    private function combinations(Collection $group, callable $score): iterable
    {
        $items = $group->values();
        for ($i = 0; $i < $items->count(); $i++) {
            for ($j = $i + 1; $j < $items->count(); $j++) {
                [$a, $b] = [$items[$i], $items[$j]];
                if ($a->id === $b->id || $a->parent_id === $b->id || $b->parent_id === $a->id) {
                    continue;
                }
                if ($result = $score($a, $b)) {
                    yield [$a->id, $b->id, ...$result];
                }
            }
        }
    }

    private function record(string $institutionId, string $a, string $b, float $score, Concept $type, array $details): bool
    {
        [$a, $b] = strcmp($a, $b) < 0 ? [$a, $b] : [$b, $a];
        if (DuplicateDetection::where(['record_a_id' => $a, 'record_b_id' => $b])->exists()) {
            return false;
        }
        DuplicateDetection::create(['institution_id' => $institutionId, 'record_a_id' => $a, 'record_b_id' => $b, 'similarity_score' => $score, 'rule_type_id' => $type->id,
            'details' => $details, 'status_id' => $this->concept('duplicate-status', 'pending'), 'detected_at' => now()]);

        return true;
    }

    /** dismissed (not duplicates) or confirmed (to be merged). */
    public function decide(User $actor, DuplicateDetection $pair, string $status, ?string $notes = null): void
    {
        in_array($status, ['dismissed', 'confirmed'], true) || throw new InvalidArgumentException(__(':value is not in the list', ['value' => $status]));
        $pair->status->notation === 'merged' && throw new InvalidArgumentException(__('They were merged already.'));
        AuditLog::actingAs($actor, fn () => $pair->update(['status_id' => $this->concept('duplicate-status', $status), 'reviewed_by_id' => $actor->id, 'reviewed_at' => now(),
            'review_notes' => trim((string) $notes) ?: null]));
    }

    /** Merge the pair: one unit kept, the other's contents moved into it and the other deleted as merged. @return array<string, int> what moved */
    public function merge(User $actor, DuplicateDetection $pair, string $keptId): array
    {
        $pair->status->notation === 'merged' && throw new InvalidArgumentException(__('They were merged already.'));
        in_array($keptId, [$pair->record_a_id, $pair->record_b_id], true) || throw new InvalidArgumentException(__('Keep one of the two.'));
        [$kept, $other] = $keptId === $pair->record_a_id ? [$pair->recordA, $pair->recordB] : [$pair->recordB, $pair->recordA];
        if ($kept->deleted_at || $other->deleted_at) {
            throw new InvalidArgumentException(__('One of them was deleted since.'));
        }
        if (! $actor->may('update', 'description', $kept) || ! $actor->may('delete', 'description', $other)) {
            throw new InvalidArgumentException(__('Merging needs who may change the unit kept and delete the other.'));
        }
        if (Declarations::locked($other)) {
            throw new InvalidArgumentException(__('It is declared a record; only its disposal, once cleared, may remove it.'));
        }
        $moved = DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $kept, $other) {
            app(DeprecationService::class)->record($other, 'merged into '.$kept->iri, $kept, $actor);
            $moved = [];
            $position = (int) RecordResource::where('parent_id', $kept->id)->max('position');
            $children = RecordResource::where('parent_id', $other->id)->whereNull('deleted_at')->orderBy('position')->get();
            foreach ($children as $child) {
                $child->update(['parent_id' => $kept->id, 'position' => ++$position]);
            }
            $moved['units'] = $children->count();
            // An instantiation stays with the unit it instantiates (the database holds to it), so the kept unit is given
            // its own of each, from the same master file; the store keeps one copy of the bytes. The other's stay with it.
            $moved['instantiations'] = 0;
            foreach (Instantiation::where('record_resource_id', $other->id)->whereNull('deleted_at')->with('files.use', 'availability')->get() as $carrier) {
                $master = $carrier->files->first(fn ($f) => $f->use->notation === 'master');
                if ($master && is_file($stored = app(FileStore::class)->path($master->storage_path))) {
                    $copy = tempnam(sys_get_temp_dir(), 'merge');
                    copy($stored, $copy);
                    try {
                        app(InstantiationService::class)->upload($kept, $copy, $master->original_name, $carrier->names->first()?->language ?? 'en',
                            (string) EntityKinds::label($carrier), $master->sha256, $carrier->availability?->notation ?? 'online', $actor);
                        $moved['instantiations']++;
                    } finally {
                        @unlink($copy);
                    }
                }
            }
            $moved['relations'] = 0;
            foreach (['source', 'target', 'context'] as $side) {
                foreach (Relation::where([$side.'_table' => 'record_resources', $side.'_id' => $other->id])->get() as $relation) {
                    $ends = array_replace($relation->only(['relation_type_id', 'source_table', 'source_id', 'target_table', 'target_id']), $side === 'context' ? [] : [$side.'_id' => $kept->id]);
                    $between = $relation->source_id === $kept->id || $relation->target_id === $kept->id;
                    if ($between || ($side !== 'context' && Relation::where($ends)->whereKeyNot($relation->id)->exists())) {
                        $this->relations->remove($relation, $actor);
                    } else {
                        $relation->update([$side.'_id' => $kept->id]);
                        $moved['relations']++;
                    }
                }
            }
            $where = fn ($r) => ['subject_table' => 'record_resources', 'subject_id' => $r->id];
            $other_ = Concept::where('iri', Vocab::NAME_TYPES.'other')->value('id');
            $keptNames = Name::where($where($kept))->get()->map(fn ($n) => $n->language.'|'.$n->value)->all();
            $moved['names'] = 0;
            foreach (Name::where($where($other))->with('nameType')->get() as $name) {
                if (! in_array($name->language.'|'.$name->value, $keptNames, true)) {
                    $name->update(['subject_id' => $kept->id, 'name_type_id' => $name->nameType->notation === 'authorised' ? $other_ : $name->name_type_id, 'is_original' => false]);
                    $moved['names']++;
                }
            }
            $keptValues = DescriptionValue::where($where($kept))->get()->map(fn ($v) => $v->element_id.'|'.$v->language)->all();
            $moved['values'] = 0;
            foreach (DescriptionValue::where($where($other))->get() as $value) {
                if (! in_array($value->element_id.'|'.$value->language, $keptValues, true)) {
                    $value->update(['subject_id' => $kept->id]);
                    $moved['values']++;
                }
            }
            $keptDates = RicDate::where($where($kept))->get()->map(fn ($d) => $d->property_id.'|'.$d->normalized_value.'|'.$d->expressed)->all();
            $moved['dates'] = 0;
            foreach (RicDate::where($where($other))->get() as $date) {
                if (! in_array($date->property_id.'|'.$date->normalized_value.'|'.$date->expressed, $keptDates, true)) {
                    $date->update(['subject_id' => $kept->id]);
                    $moved['dates']++;
                }
            }
            $local = Concept::where('iri', Vocab::IDENTIFIER_TYPES.'local')->value('id');
            $previous = Concept::where('iri', Vocab::IDENTIFIER_TYPES.'previous')->value('id');
            foreach (Identifier::where($where($other))->get() as $identifier) {
                $type = $identifier->identifier_type_id === $local ? $previous : $identifier->identifier_type_id;
                Identifier::where($where($kept) + ['identifier_type_id' => $type, 'value' => $identifier->value])->exists()
                    ? $identifier->delete() : $identifier->update(['subject_id' => $kept->id, 'identifier_type_id' => $type]);
            }
            $other->forceFill(['deleted_at' => now(), 'merged_into_id' => $kept->id])->save();

            return $moved;
        }));
        $pair->update(['status_id' => $this->concept('duplicate-status', 'merged'), 'reviewed_by_id' => $actor->id, 'reviewed_at' => now(), 'kept_id' => $kept->id, 'moved' => $moved]);
        // Any other pair naming the unit merged away is moot (Heratio's merge did the same).
        DuplicateDetection::where(fn ($q) => $q->where('record_a_id', $other->id)->orWhere('record_b_id', $other->id))->whereKeyNot($pair->id)
            ->update(['status_id' => $this->concept('duplicate-status', 'dismissed'), 'reviewed_by_id' => $actor->id, 'reviewed_at' => now(),
                'review_notes' => __('One was merged into :iri', ['iri' => $kept->iri])]);

        return $moved;
    }

    public static function normalise(string $text): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text)));
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
