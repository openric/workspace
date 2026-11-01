<?php

// OpenRiC Workspace - researchers' judgement of sources, proposals that two entities are one, and searches across
// several fonds (roadmap phase 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's research_source_assessment (read by its evidence viewer and source triage), EntityResolutionService
// (proposeMatch, getProposals, resolveMatch, getConflictingAssertions, deleteResolution) and CrossFondsQueryService
// (availableFonds, query). A researcher assesses a record as a source: primary, secondary or tertiary; its form; how
// complete it is; how far to trust it (0 to 100), why, and its bias. A researcher proposes that two entities of the
// catalogue are one, with a confidence, the method and evidence; who may change both accepts or rejects it, seeing the
// claims that relate the two first. Accepted, two authority records are offered for merging, which stays the
// catalogue's own act. A search runs across the fonds chosen, each result named with the fonds it is in, and is
// logged. Changed: Heratio searches each fonds in Elasticsearch; here the Workspace's own index, with the searcher's
// access, kept to what lies inside the fonds chosen.

namespace App\Research;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\ResearchClaim;
use App\Models\ResearchCrossFondsQuery;
use App\Models\ResearchEntityResolution;
use App\Models\ResearchSourceAssessment;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Search\SearchService;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Sources
{
    /** Kinds that may be proposed as one. */
    private const RESOLVABLE = ['agent', 'record', 'repository', 'place', 'activity'];

    public function __construct(private SearchService $search) {}

    // Source assessments.

    public function assess(User $user, RecordResource $record, array $data): ResearchSourceAssessment
    {
        if ($record->institution_id !== $user->institution_id || ! EntityKinds::readable($user, $record)) {
            throw new InvalidArgumentException(__('You may not assess that.'));
        }
        $trust = ($data['trust_score'] ?? '') === '' || $data['trust_score'] === null ? null : (int) $data['trust_score'];
        if ($trust !== null && ($trust < 0 || $trust > 100)) {
            throw new InvalidArgumentException(__('Give the trust as a number from 0 to 100.'));
        }

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => ResearchSourceAssessment::create(['institution_id' => $record->institution_id, 'record_id' => $record->id, 'user_id' => $user->id,
            'source_kind_id' => $this->concept('source-kind', (string) ($data['source_kind'] ?? 'primary')), 'source_form_id' => $this->concept('source-form', (string) ($data['source_form'] ?? 'original')),
            'completeness_id' => $this->concept('source-completeness', (string) ($data['completeness'] ?? 'complete')), 'trust_score' => $trust,
            'rationale' => trim((string) ($data['rationale'] ?? '')) ?: null, 'bias_context' => trim((string) ($data['bias_context'] ?? '')) ?: null, 'assessed_at' => now()])));
    }

    /** The latest assessment of a record by each researcher, newest first. */
    public function assessments(RecordResource $record): Collection
    {
        return ResearchSourceAssessment::where('record_id', $record->id)->with('user', 'sourceKind', 'sourceForm', 'completeness')->orderByDesc('assessed_at')->get()->unique('user_id')->values();
    }

    // Entity resolution.

    public function propose(User $user, Model $a, Model $b, array $data = []): ResearchEntityResolution
    {
        foreach ([$a, $b] as $entity) {
            if (! in_array(EntityKinds::of($entity), self::RESOLVABLE, true) || $entity->getAttribute('institution_id') !== $user->institution_id || ! EntityKinds::readable($user, $entity)) {
                throw new InvalidArgumentException(__('Choose two entities of the catalogue you may read.'));
            }
        }
        if ($a->is($b) || EntityKinds::of($a) !== EntityKinds::of($b)) {
            throw new InvalidArgumentException(__('Choose two different entities of one kind.'));
        }
        $confidence = ($data['confidence'] ?? '') === '' || ($data['confidence'] ?? null) === null ? null : max(0, min(1, (float) $data['confidence']));
        $open = ResearchEntityResolution::where('institution_id', $user->institution_id)->whereHas('status', fn ($s) => $s->where('iri', Vocab::BASE.'resolution-status/proposed'))
            ->where(fn ($w) => $w->where(['entity_a_id' => $a->getKey(), 'entity_b_id' => $b->getKey()])->orWhere(['entity_a_id' => $b->getKey(), 'entity_b_id' => $a->getKey()]))->exists();
        if ($open) {
            throw new InvalidArgumentException(__('Those two are proposed as one already.'));
        }

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => ResearchEntityResolution::create(['institution_id' => $user->institution_id, 'project_id' => $data['project_id'] ?? null,
            'entity_a_table' => $a->getTable(), 'entity_a_id' => $a->getKey(), 'entity_b_table' => $b->getTable(), 'entity_b_id' => $b->getKey(), 'confidence' => $confidence,
            'match_method' => trim((string) ($data['match_method'] ?? '')) ?: null, 'status_id' => $this->concept('resolution-status', 'proposed'), 'proposer_id' => $user->id,
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null, 'evidence' => array_values(array_filter(array_map('trim', explode("\n", (string) ($data['evidence'] ?? ''))))) ?: null])));
    }

    /** Accept or reject: who may change both. */
    public function resolve(User $user, ResearchEntityResolution $resolution, bool $accept, ?string $notes = null): void
    {
        [$a, $b] = $this->entities($resolution);
        if (! $a || ! $b || ! $this->mayResolve($user, $resolution)) {
            throw new InvalidArgumentException(__('Only who may change both may decide.'));
        }
        if ($resolution->status->notation !== 'proposed') {
            throw new InvalidArgumentException(__('It is decided already.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $resolution->update(['status_id' => $this->concept('resolution-status', $accept ? 'accepted' : 'rejected'),
            'resolver_id' => $user->id, 'resolved_at' => now(), 'notes' => trim(($resolution->notes ? $resolution->notes."\n" : '').(string) $notes) ?: null])));
    }

    public function withdraw(User $user, ResearchEntityResolution $resolution): void
    {
        if ($resolution->proposer_id !== $user->id || $resolution->status->notation !== 'proposed') {
            throw new InvalidArgumentException(__('Only its proposer may withdraw it, while it is proposed.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $resolution->delete()));
    }

    public function mayResolve(User $user, ResearchEntityResolution $resolution): bool
    {
        [$a, $b] = $this->entities($resolution);

        return $a && $b && collect([$a, $b])->every(fn ($e) => $user->may('update', EntityKinds::permission($e), $e));
    }

    /** @return array{0: ?Model, 1: ?Model} */
    public function entities(ResearchEntityResolution $resolution): array
    {
        $find = function (string $table, string $id) {
            $kind = EntityKinds::byTable($table);

            return $kind ? EntityKinds::get($kind)['class']::find($id) : null;
        };

        return [$find($resolution->entity_a_table, $resolution->entity_a_id), $find($resolution->entity_b_table, $resolution->entity_b_id)];
    }

    /** Heratio's getConflictingAssertions: the claims that relate the two, for the resolver to weigh. */
    public function claimsBetween(ResearchEntityResolution $resolution): Collection
    {
        $pair = [$resolution->entity_a_id, $resolution->entity_b_id];

        return ResearchClaim::whereIn('subject_id', $pair)->whereIn('object_id', $pair)->whereColumn('subject_id', '!=', 'object_id')->with('status')->get();
    }

    // Searching across fonds.

    /** Heratio's availableFonds: the top-level descriptions the account may read. */
    public function fonds(User $user): Collection
    {
        return RecordResource::where('institution_id', $user->institution_id)->whereNull('parent_id')->whereNull('deleted_at')->get()
            ->filter(fn ($r) => EntityKinds::readable($user, $r))->sortBy(fn ($r) => mb_strtolower(EntityKinds::label($r)))->values();
    }

    /**
     * The results of a search inside the fonds chosen, each with the fonds it is in; logged.
     *
     * @return list<array{fonds: string, title: string, snippet: mixed, record: RecordResource}>
     */
    public function across(User $user, string $query, array $fondsIds): array
    {
        $started = hrtime(true);
        if (trim($query) === '' || $fondsIds === []) {
            throw new InvalidArgumentException(__('Give the words and choose at least one fonds.'));
        }
        $fonds = $this->fonds($user)->whereIn('id', $fondsIds)->values();
        $inside = [];
        foreach ($fonds as $f) {
            foreach ([$f->id, ...$f->descendantIds()] as $id) {
                $inside[$id] = $f;
            }
        }
        // ponytail: kept to the fonds after the index's first 500 records; a fonds filter in SearchService would lift the ceiling.
        $found = $this->search->search(Institution::find($user->institution_id), $query, ! $user->mayAnywhere('view-draft', 'description'), ['kind' => 'records'], 1, 500);
        $out = [];
        foreach ($found['results'] as $row) {
            if (! isset($inside[$row['id']])) {
                continue;
            }
            $record = RecordResource::find($row['id']);
            if ($record && EntityKinds::readable($user, $record)) {
                $out[] = ['fonds' => EntityKinds::label($inside[$row['id']]), 'title' => (string) $row['title'], 'snippet' => $row['snippet'], 'record' => $record];
            }
        }
        ResearchCrossFondsQuery::create(['institution_id' => $user->institution_id, 'user_id' => $user->id, 'query_text' => mb_substr($query, 0, 1000), 'fonds_ids' => $fonds->pluck('id')->all(),
            'results_count' => count($out), 'elapsed_ms' => (int) ((hrtime(true) - $started) / 1e6), 'created_at' => now()]);

        return $out;
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
