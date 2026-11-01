<?php

// OpenRiC Workspace - the claims and hypotheses of a research project (roadmap phase 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ClaimLedgerService and ClaimLedgerController, and the assertions, assertion batch review, knowledge
// graph and hypotheses pages of ResearchProjectOutputsController: a project's claims, each in words or as an assertion
// about an entity (its subject, a predicate and what is asserted, of a kind: biographical, chronological, spatial,
// relational, attributive), with a status, a confidence, and the claim ledger's own fields (the kind of evidence, the
// level of confidence, where the claim comes from, sources for and against, quotations, the link to method and
// theory, notes, weaknesses not yet resolved and ethical concerns). Evidence for or against a claim is a record of
// the catalogue, an item of one of the project's collections, an annotation, a bibliography or a work in it, or
// another claim. "No unsupported claim passes silently": the ledger lists the claims with no evidence and those that
// rest on one source alone. Proposed assertions are reviewed in bulk. The knowledge graph is the claims as nodes and
// what they assert as edges. A hypothesis has its evidence too, supporting, refuting or neutral, and its count.
//
// Changed: Heratio keeps a claim in research_assertion and its ledger fields in research_claim_meta; here they are one
// table. A claim's version goes up each time its words or status change.

namespace App\Research;

use App\Audit\AuditLog;
use App\Models\Activity;
use App\Models\Agent;
use App\Models\Bibliography;
use App\Models\BibliographyEntry;
use App\Models\Concept;
use App\Models\Place;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\ResearchAnnotation;
use App\Models\ResearchClaim;
use App\Models\ResearchClaimEvidence;
use App\Models\ResearchCollection;
use App\Models\ResearchCollectionItem;
use App\Models\ResearchHypothesis;
use App\Models\ResearchHypothesisEvidence;
use App\Models\ResearchProject;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Claims
{
    public const STATUSES = Vocab::BASE.'claim-status/';

    public const SOURCES = Vocab::BASE.'claim-source-type/';

    /** The fields of the claim ledger a researcher writes as text. */
    public const LEDGER = ['supporting_sources', 'opposing_sources', 'quotations', 'method_theory_link', 'researcher_notes', 'unresolved_weaknesses', 'ethical_concerns'];

    /** Heratio's confidenceToDecimal: a level as a number, the middle of its band. */
    private const LEVEL_CONFIDENCE = ['high' => 0.85, 'medium' => 0.6, 'low' => 0.3, 'tentative' => 0.15];

    public function __construct(private Projects $projects) {}

    // Claims.

    public function save(User $user, ResearchProject $project, array $data, ?ResearchClaim $claim = null): ResearchClaim
    {
        $this->projects->must($user, $project, 'contributor');
        if ($claim && $claim->project_id !== $project->id) {
            throw new InvalidArgumentException(__('That is not of this project.'));
        }
        $text = trim((string) ($data['object_value'] ?? $data['text'] ?? ''));
        if ($text === '') {
            throw new InvalidArgumentException(__('Write the claim.'));
        }
        $subject = ($data['subject_iri'] ?? '') !== '' ? $this->entity($user, (string) $data['subject_iri']) : null;
        $object = ($data['object_iri'] ?? '') !== '' ? $this->entity($user, (string) $data['object_iri']) : null;
        $level = ($data['confidence_level'] ?? '') !== '' ? (string) $data['confidence_level'] : null;
        $confidence = ($data['confidence'] ?? '') !== '' && $data['confidence'] !== null ? max(0, min(1, (float) $data['confidence'])) : ($level ? self::LEVEL_CONFIDENCE[$level] ?? null : $claim?->confidence);
        $fields = ['claim_type_id' => $this->concept('claim-type', (string) ($data['claim_type'] ?? $claim?->claimType->notation ?? 'claim')),
            'subject_table' => $subject?->getTable(), 'subject_id' => $subject?->getKey(),
            'subject_label' => $subject ? EntityKinds::label($subject) : (trim((string) ($data['subject_label'] ?? '')) ?: null),
            'predicate' => trim((string) ($data['predicate'] ?? '')) ?: null, 'object_value' => $text, 'object_table' => $object?->getTable(), 'object_id' => $object?->getKey(),
            'object_label' => $object ? EntityKinds::label($object) : (trim((string) ($data['object_label'] ?? '')) ?: null),
            'status_id' => $this->concept('claim-status', (string) ($data['status'] ?? $claim?->status->notation ?? 'idea')), 'confidence' => $confidence,
            'evidence_type_id' => ($data['evidence_type'] ?? '') !== '' ? $this->concept('claim-evidence-type', (string) $data['evidence_type']) : null,
            'confidence_level_id' => $level ? $this->concept('confidence-level', $level) : null,
            'provenance_kind_id' => $this->concept('claim-provenance', (string) (($data['provenance_kind'] ?? '') ?: 'original'))]
            + array_map(fn ($k) => trim((string) ($data[$k] ?? '')) ?: null, array_combine(self::LEDGER, self::LEDGER));

        // Edited without naming the entities again, a claim keeps those it names, while their names are unchanged.
        foreach (['subject', 'object'] as $side) {
            if ($claim && $fields[$side.'_table'] === null && $claim->{$side.'_table'} && $fields[$side.'_label'] === $claim->{$side.'_label'}) {
                [$fields[$side.'_table'], $fields[$side.'_id']] = [$claim->{$side.'_table'}, $claim->{$side.'_id'}];
            }
        }

        return DB::transaction(fn () => AuditLog::actingAs($user, function () use ($user, $project, $claim, $fields) {
            if ($claim) {
                $changed = $claim->object_value !== $fields['object_value'] || $claim->status_id !== $fields['status_id'];
                $claim->update($fields + ['version' => $claim->version + ($changed ? 1 : 0)]);
            } else {
                $claim = ResearchClaim::create($fields + ['institution_id' => $project->institution_id, 'project_id' => $project->id, 'user_id' => $user->id]);
            }

            return $claim;
        }));
    }

    public function setStatus(User $user, ResearchClaim $claim, string $status): void
    {
        $this->projects->must($user, $claim->project, 'contributor');
        $id = $this->concept('claim-status', $status);
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $claim->status_id === $id ?: $claim->update(['status_id' => $id, 'version' => $claim->version + 1])));
    }

    /** Heratio's assertion batch review: proposed assertions verified, disputed or retracted together. @return int how many */
    public function review(User $user, ResearchProject $project, array $ids, string $status): int
    {
        $this->projects->must($user, $project, 'editor');
        in_array($status, ['verified', 'disputed', 'retracted', 'supported', 'rejected'], true) || throw new InvalidArgumentException(__('Choose a status from the list.'));
        $claims = ResearchClaim::where('project_id', $project->id)->whereIn('id', $ids)->whereHas('status', fn ($s) => $s->where('iri', self::STATUSES.'proposed'))->get();
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $claims->each(fn ($c) => $c->update(['status_id' => $this->concept('claim-status', $status), 'version' => $c->version + 1]))));

        return $claims->count();
    }

    public function delete(User $user, ResearchClaim $claim): void
    {
        $this->projects->must($user, $claim->project, $claim->user_id === $user->id ? 'contributor' : 'editor');
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $claim->delete()));
    }

    public function claims(ResearchProject $project, array $filters = []): Collection
    {
        $q = trim((string) ($filters['q'] ?? ''));

        return ResearchClaim::where('project_id', $project->id)->with('claimType', 'status', 'confidenceLevel', 'user')->withCount('evidence')
            ->when($filters['status'] ?? null, fn ($w, $s) => $w->whereHas('status', fn ($c) => $c->where('iri', self::STATUSES.$s)))
            ->when($filters['type'] ?? null, fn ($w, $t) => $w->whereHas('claimType', fn ($c) => $c->where('iri', Vocab::BASE.'claim-type/'.$t)))
            ->when($q !== '', fn ($w) => $w->where(fn ($o) => $o->where('object_value', 'ilike', '%'.addcslashes($q, '%_\\').'%')->orWhere('subject_label', 'ilike', '%'.addcslashes($q, '%_\\').'%')))
            ->orderByDesc('updated_at')->get();
    }

    /** @return array<string, int> claims by status */
    public function statusCounts(ResearchProject $project): array
    {
        return ResearchClaim::where('project_id', $project->id)->join('concepts', 'concepts.id', '=', 'research_claims.status_id')
            ->selectRaw('concepts.notation, count(*) as n')->groupBy('concepts.notation')->pluck('n', 'notation')->map(fn ($n) => (int) $n)->all();
    }

    /** Heratio's claimsWithoutCitation. */
    public function withoutEvidence(ResearchProject $project): Collection
    {
        return ResearchClaim::where('project_id', $project->id)->doesntHave('evidence')->orderByDesc('updated_at')->get();
    }

    /** Heratio's claimsOverDependent: two or more pieces of evidence, all from one source. */
    public function overDependent(ResearchProject $project): Collection
    {
        return ResearchClaim::where('project_id', $project->id)->with('evidence')->get()
            ->filter(fn ($c) => $c->evidence->count() >= 2 && $c->evidence->map(fn ($e) => $e->source_type_id.':'.$e->source_id)->unique()->count() === 1)->values();
    }

    // Evidence.

    /** Add a piece of evidence: what it is, which one, and whether it supports or refutes the claim. */
    public function attach(User $user, ResearchClaim $claim, array $data): ResearchClaimEvidence
    {
        $project = $claim->project;
        $this->projects->must($user, $project, 'contributor');
        [$type, $source] = $this->source($user, $project, (string) ($data['source_type'] ?? ''), $data);
        if ($type === 'claim' && $source->getKey() === $claim->id) {
            throw new InvalidArgumentException(__('A claim is not evidence for itself.'));
        }
        $relationship = $this->concept('evidence-relationship', (string) ($data['relationship'] ?? 'supports'));
        if ($claim->evidence()->where(['source_type_id' => $this->concept('claim-source-type', $type), 'source_id' => $source->getKey(), 'relationship_id' => $relationship])->exists()) {
            throw new InvalidArgumentException(__('That evidence is there already.'));
        }

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => $claim->evidence()->create(['source_type_id' => $this->concept('claim-source-type', $type),
            'source_id' => $source->getKey(), 'relationship_id' => $relationship, 'note' => trim((string) ($data['note'] ?? '')) ?: null,
            'selector' => is_array($data['selector'] ?? null) ? $data['selector'] : null, 'added_by_id' => $user->id, 'created_at' => now()])));
    }

    public function detach(User $user, ResearchClaimEvidence $evidence): void
    {
        $this->projects->must($user, $evidence->claim->project, $evidence->added_by_id === $user->id ? 'contributor' : 'editor');
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $evidence->delete()));
    }

    /** What a piece of evidence is, to show: its label and where to open it. @return array{label: string, url: ?string} */
    public function describe(ResearchClaimEvidence|ResearchHypothesisEvidence $evidence, User $user): array
    {
        $type = $evidence->sourceType->notation;
        $model = $this->find($type, $evidence->source_id);
        if ($model === null) {
            return ['label' => __('No longer there'), 'url' => null];
        }

        return match ($type) {
            'record' => EntityKinds::readable($user, $model) ? ['label' => EntityKinds::label($model), 'url' => route('records.show', $model)] : ['label' => __('Not available to you'), 'url' => null],
            'collection-item' => ['label' => ($model->reference_code ? $model->reference_code.' ' : '').($model->title ?? ($model->subject_table ? EntityKinds::label(app(Favourites::class)->entity($model) ?? $model) : __('Item'))), 'url' => null],
            'annotation' => ['label' => $model->title ?: mb_substr((string) $model->content, 0, 80), 'url' => null],
            'bibliography' => ['label' => $model->name, 'url' => $model->user_id === $user->id ? route('research.bibliographies.show', $model) : null],
            'bibliography-entry' => ['label' => trim($model->authors.' '.$model->title), 'url' => null],
            'claim' => ['label' => mb_substr($model->object_value, 0, 120), 'url' => null],
        };
    }

    /** Heratio's availableEvidence: what of the project may be cited. @return array<string, Collection> */
    public function available(User $user, ResearchProject $project): array
    {
        $collections = ResearchCollection::where('project_id', $project->id)->pluck('id');

        return ['collection-item' => ResearchCollectionItem::whereIn('collection_id', $collections)->orderBy('sort_order')->limit(500)->get(),
            'annotation' => ResearchAnnotation::where('project_id', $project->id)->latest()->limit(500)->get(),
            'bibliography' => Bibliography::where('project_id', $project->id)->orderBy('name')->get(),
            'claim' => ResearchClaim::where('project_id', $project->id)->latest()->limit(500)->get()];
    }

    /** The knowledge graph: each claim a node, what it asserts an edge to its object. @return array{nodes: list<array>, edges: list<array>} */
    public function graph(ResearchProject $project): array
    {
        $claims = ResearchClaim::where('project_id', $project->id)->with('claimType', 'status')->withCount('evidence')->get();
        $nodes = [];
        $edges = [];
        foreach ($claims as $c) {
            $subject = $c->subject_label ?: mb_substr($c->object_value, 0, 80);
            $key = $c->subject_id ?? 'label:'.mb_strtolower($subject);
            $nodes[$key] ??= ['id' => $key, 'label' => $subject, 'type' => $c->claimType->notation, 'status' => $c->status->notation, 'evidence' => 0];
            $nodes[$key]['evidence'] += $c->evidence_count;
            if ($c->object_label || $c->object_id) {
                $target = $c->object_id ?? 'label:'.mb_strtolower((string) $c->object_label);
                $nodes[$target] ??= ['id' => $target, 'label' => (string) $c->object_label, 'type' => 'object', 'status' => null, 'evidence' => 0];
                $edges[] = ['source' => $key, 'target' => $target, 'label' => (string) $c->predicate, 'claim' => $c->id, 'status' => $c->status->notation];
            }
        }

        return ['nodes' => array_values($nodes), 'edges' => $edges];
    }

    // Hypotheses.

    public function saveHypothesis(User $user, ResearchProject $project, array $data, ?ResearchHypothesis $hypothesis = null): ResearchHypothesis
    {
        $this->projects->must($user, $project, 'contributor');
        if (trim((string) ($data['statement'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Write the hypothesis.'));
        }
        $fields = ['statement' => trim($data['statement']), 'status_id' => $this->concept('hypothesis-status', (string) ($data['status'] ?? $hypothesis?->status->notation ?? 'proposed')),
            'tags' => Diary::tags($data['tags'] ?? null)];

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => $hypothesis ? tap($hypothesis)->update($fields)
            : $project->hypotheses()->create($fields + ['user_id' => $user->id])));
    }

    public function deleteHypothesis(User $user, ResearchHypothesis $hypothesis): void
    {
        $this->projects->must($user, $hypothesis->project, $hypothesis->user_id === $user->id ? 'contributor' : 'editor');
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $hypothesis->delete()));
    }

    public function attachToHypothesis(User $user, ResearchHypothesis $hypothesis, array $data): ResearchHypothesisEvidence
    {
        $project = $hypothesis->project;
        $this->projects->must($user, $project, 'contributor');
        [$type, $source] = $this->source($user, $project, (string) ($data['source_type'] ?? ''), $data);
        $confidence = ($data['confidence'] ?? '') !== '' && $data['confidence'] !== null ? max(0, min(1, (float) $data['confidence'])) : null;

        return DB::transaction(fn () => AuditLog::actingAs($user, function () use ($user, $hypothesis, $type, $source, $data, $confidence) {
            $evidence = $hypothesis->evidence()->create(['source_type_id' => $this->concept('claim-source-type', $type), 'source_id' => $source->getKey(),
                'relationship_id' => $this->concept('hypothesis-evidence-relationship', (string) ($data['relationship'] ?? 'supports')), 'confidence' => $confidence,
                'note' => trim((string) ($data['note'] ?? '')) ?: null, 'added_by_id' => $user->id, 'created_at' => now()]);
            $hypothesis->update(['evidence_count' => $hypothesis->evidence()->count()]);

            return $evidence;
        }));
    }

    public function detachFromHypothesis(User $user, ResearchHypothesisEvidence $evidence): void
    {
        $hypothesis = $evidence->hypothesis;
        $this->projects->must($user, $hypothesis->project, $evidence->added_by_id === $user->id ? 'contributor' : 'editor');
        DB::transaction(fn () => AuditLog::actingAs($user, function () use ($hypothesis, $evidence) {
            $evidence->delete();
            $hypothesis->update(['evidence_count' => $hypothesis->evidence()->count()]);
        }));
    }

    /**
     * The source a form names, checked: a record by IRI the account may read, or something of the project.
     *
     * @return array{0: string, 1: Model}
     */
    private function source(User $user, ResearchProject $project, string $type, array $data): array
    {
        $id = $data['source_id'] ?? null;
        $available = fn (string $kind) => $this->available($user, $project)[$kind]->firstWhere('id', $id) ?? throw new InvalidArgumentException(__('Choose one of the project\'s.'));
        $source = match ($type) {
            'record' => $this->entity($user, (string) ($data['iri'] ?? ''), RecordResource::class),
            'collection-item', 'annotation', 'bibliography', 'claim' => $available($type),
            'bibliography-entry' => BibliographyEntry::where('id', $id)->whereIn('bibliography_id', Bibliography::where('project_id', $project->id)->select('id'))->first()
                ?? throw new InvalidArgumentException(__('Choose one of the project\'s.')),
            default => throw new InvalidArgumentException(__('Choose a kind from the list.')),
        };

        return [$type, $source];
    }

    private function find(string $type, string $id): ?Model
    {
        return match ($type) {
            'record' => RecordResource::find($id),
            'collection-item' => ResearchCollectionItem::find($id),
            'annotation' => ResearchAnnotation::find($id),
            'bibliography' => Bibliography::find($id),
            'bibliography-entry' => BibliographyEntry::find($id),
            'claim' => ResearchClaim::find($id),
            default => null,
        };
    }

    /** An entity of the Workspace the account may read, by its persistent IRI. */
    private function entity(User $user, string $iri, ?string $class = null): Model
    {
        $iri = trim($iri);
        // The kinds a relation may reach, as the import finds them by IRI.
        foreach ($class ? [$class] : [Agent::class, Repository::class, RecordResource::class, Activity::class, Place::class] as $c) {
            $found = $c::where(['institution_id' => $user->institution_id, 'iri' => $iri])->first();
            if ($found && $found->getAttribute('deleted_at') === null && EntityKinds::readable($user, $found)) {
                return $found;
            }
        }

        throw new InvalidArgumentException(__('Nothing you may read has that IRI.'));
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
