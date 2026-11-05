<?php

// OpenRiC Workspace - what an archivist decides about a mention (roadmap phase 17b2), after Heratio's DecisionRecorder,
// AuthorityCreator and FieldProvenanceWriter: link it to a ranked candidate or another record, make a new authority
// record or place (perhaps filled from outside authorities, each field with where it came from), park it with a
// reason, or reject it. Linking and making go through the review queue's accept, so the access point relation is made
// as any other (a model's proposal keeping the model's assertion, which the reviewer accepts); rejecting goes through
// its reject, and a name a model found is kept as feedback for the model. Every decision is kept as it was seen: every
// candidate and its evidence at that moment.
// Changed from Heratio: the decision is the review queue's, so a mention and its proposal never disagree; a new
// record is a RiC agent or place, its sources written in its ISAAR(CPF) sources too; provenance is the decision row,
// the relation's assertions and the field sources, exported with the RiC-O, rather than a separate Fuseki graph.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Authority;

use App\Audit\AuditLog;
use App\Description\AgentService;
use App\Description\PlaceService;
use App\Models\Agent;
use App\Models\AuthorityCandidate;
use App\Models\AuthorityDecision;
use App\Models\AuthorityMention;
use App\Models\AuthorityPark;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\Place;
use App\Models\User;
use App\Proposals\ReviewService;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class Decisions
{
    /** The ISAAR(CPF) elements a new authority record must have, as Heratio's AuthorityCreator asks. */
    private const ISAAR_REQUIRED = ['authorized_form_of_name', 'dates_of_existence', 'history'];

    private const AGENT_FIELDS = ['dates_of_existence' => 'agent-dates-of-existence', 'history' => 'agent-history', 'places' => 'agent-places'];

    public function __construct(private ReviewService $review, private NerFeedback $feedback) {}

    public function link(AuthorityMention $mention, User $user, AuthorityCandidate $candidate): AuthorityDecision
    {
        $candidate->mention_id === $mention->id || throw new InvalidArgumentException(__('That candidate is not one of this mention\'s.'));

        return $this->accept($mention, $user, 'link', $candidate->entity() ?? throw new InvalidArgumentException(__('That candidate no longer exists.')), $candidate);
    }

    public function linkDifferent(AuthorityMention $mention, User $user, string $entityId): AuthorityDecision
    {
        $class = $mention->isPlace() ? Place::class : Agent::class;
        $entity = $class::where('institution_id', $mention->institution_id)->whereNull('deleted_at')->find($entityId)
            ?? throw new InvalidArgumentException($mention->isPlace() ? __('That place does not exist.') : __('That authority record does not exist.'));

        return $this->accept($mention, $user, 'link-different', $entity);
    }

    /**
     * Make a new authority record or place and link the mention to it. $fields: for an agent authorized_form_of_name,
     * dates_of_existence, history, places; for a place name, latitude, longitude. $provenance: field => {origin, source,
     * uri, licence, licence_url, retrieved_at}, as Prefill gives it.
     */
    public function createNew(AuthorityMention $mention, User $user, array $fields, array $provenance = []): AuthorityDecision
    {
        $institution = Institution::findOrFail($mention->institution_id);
        $language = $mention->record->originalLanguage('title');
        $fields = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $fields);
        if ($mention->isPlace()) {
            $user->may('create', 'vocabulary') || throw new InvalidArgumentException(__('you may not create places: choose an existing one'));
            ($fields['name'] ?? '') !== '' || throw new InvalidArgumentException(__('A place has a name.'));
            [$lat, $lng] = [$fields['latitude'] ?? null, $fields['longitude'] ?? null];
            (($lat === null || $lat === '') === ($lng === null || $lng === '')) || throw new InvalidArgumentException(__('Give the latitude and longitude together, or neither.'));
        } else {
            $user->may('create', 'authority') || throw new InvalidArgumentException(__('you may not create authority records: choose an existing one'));
            $missing = array_filter(self::ISAAR_REQUIRED, fn ($k) => ($fields[$k] ?? '') === '');
            $missing === [] || throw new InvalidArgumentException(__('An authority record needs its authorised name, dates of existence and history (ISAAR(CPF)).'));
        }

        return DB::transaction(function () use ($mention, $user, $fields, $provenance, $institution, $language) {
            if ($mention->isPlace()) {
                $entity = app(PlaceService::class)->create($institution, $fields['name'], $language, null, $user);
                if (($fields['latitude'] ?? '') !== '') {
                    AuditLog::actingAs($user, fn () => $entity->update(['latitude' => (float) $fields['latitude'], 'longitude' => (float) $fields['longitude']]));
                }
            } else {
                $type = $mention->proposal->agentType?->notation ?? 'person';
                $entity = app(AgentService::class)->create($institution, $type, $fields['authorized_form_of_name'], $language, null, $user);
                $values = array_filter(array_map(fn ($k) => $fields[$k] ?? null, array_flip(self::AGENT_FIELDS)));
                $sources = $this->sourcesText($provenance);
                app(AgentService::class)->describe($entity, $values + ($sources !== '' ? ['agent-sources' => $sources] : []), $language, $user);
            }
            $decision = $this->accept($mention, $user, 'create-new', $entity);
            $this->recordSources($institution, $entity, $fields, $provenance, $decision);

            return $decision;
        });
    }

    public function park(AuthorityMention $mention, User $user, string $reason): AuthorityDecision
    {
        ($reason = trim($reason)) !== '' || throw new InvalidArgumentException(__('Say why it is parked, so it can be taken up again.'));
        $this->pending($mention);

        return DB::transaction(function () use ($mention, $user, $reason) {
            $decision = $this->decide($mention, $user, 'park', null, null, $reason);
            $mention->update(['state_id' => Mentions::state('parked')]);
            AuthorityPark::updateOrCreate(['mention_id' => $mention->id], ['parked_by_id' => $user->id, 'parked_at' => now(), 'reason' => $reason,
                'new_candidate_available' => false, 'checked_at' => now(), 'fingerprint' => ParkQueue::fingerprint($mention)]);

            return $decision;
        });
    }

    public function reject(AuthorityMention $mention, User $user, ?string $reason = null): AuthorityDecision
    {
        $this->pending($mention);

        return DB::transaction(function () use ($mention, $user, $reason) {
            $this->review->reject($mention->proposal, $user, $reason);
            $decision = $this->decide($mention, $user, 'reject', null, null, $reason);
            $mention->update(['state_id' => Mentions::state('rejected')]);
            AuthorityPark::where('mention_id', $mention->id)->delete();
            $this->feedback->capture($mention, $decision);

            return $decision;
        });
    }

    private function accept(AuthorityMention $mention, User $user, string $type, Model $entity, ?AuthorityCandidate $candidate = null): AuthorityDecision
    {
        $this->pending($mention);

        return DB::transaction(function () use ($mention, $user, $type, $entity, $candidate) {
            $this->review->accept($mention->proposal, $user, ['link' => $entity->getKey()]);
            $decision = $this->decide($mention, $user, $type, $entity, $candidate);
            $mention->update(['state_id' => Mentions::state($type === 'create-new' ? 'new-record-created' : 'linked')]);
            AuthorityPark::where('mention_id', $mention->id)->delete();

            return $decision;
        });
    }

    /** The decision, with every candidate and its evidence as the archivist saw them. */
    private function decide(AuthorityMention $mention, User $user, string $type, ?Model $entity, ?AuthorityCandidate $candidate = null, ?string $reason = null): AuthorityDecision
    {
        $candidates = $mention->candidates()->get();

        return AuditLog::actingAs($user, fn () => AuthorityDecision::create(['institution_id' => $mention->institution_id, 'mention_id' => $mention->id,
            'decision_id' => Concept::where('iri', Vocab::BASE.'authority-decision/'.$type)->valueOrFail('id'),
            'chosen_table' => $entity?->getTable(), 'chosen_id' => $entity?->getKey(), 'top_score' => $candidate?->composite ?? $candidates->first()?->composite,
            'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null, 'user_id' => $user->id, 'decided_at' => now(),
            'evidence_snapshot' => $candidates->mapWithKeys(fn ($c) => [$c->id => ['signals' => $c->signals, 'data' => $c->evidence, 'composite' => $c->composite]])->all() ?: (object) [],
            'candidates_snapshot' => $candidates->map(fn ($c) => ['candidate_id' => $c->id, 'rank' => $c->rank, 'table' => $c->candidate_table, 'id' => $c->candidate_id,
                'display_name' => $c->display_name, 'name_similarity' => $c->name_similarity, 'composite' => $c->composite, 'chosen' => $c->id === $candidate?->id])->all()]));
    }

    private function pending(AuthorityMention $mention): void
    {
        in_array($mention->state->notation, ['pending', 'parked'], true) || throw new InvalidArgumentException(__('This mention was decided already.'));
    }

    /** One row a filled field, with where it came from (Heratio's FieldProvenanceWriter). */
    private function recordSources(Institution $institution, Model $entity, array $fields, array $provenance, AuthorityDecision $decision): void
    {
        foreach ($provenance as $field => $p) {
            if (($fields[$field] ?? '') === '' || ! is_array($p)) {
                continue;
            }
            DB::table('authority_field_sources')->insert(['id' => (string) Str::uuid7(), 'institution_id' => $institution->id,
                'subject_table' => $entity->getTable(), 'subject_id' => $entity->getKey(), 'field' => mb_substr((string) $field, 0, 100), 'value' => (string) $fields[$field],
                'source_id' => ($p['source'] ?? null) ? Concept::where('iri', Vocab::BASE.'lookup-source/'.$p['source'])->value('id') : null,
                'origin' => mb_substr((string) ($p['origin'] ?? $p['source'] ?? 'mention'), 0, 30), 'source_iri' => $p['uri'] ?? null,
                'licence' => isset($p['licence']) ? mb_substr((string) $p['licence'], 0, 500) : null, 'licence_url' => $p['licence_url'] ?? null,
                'retrieved_at' => $p['retrieved_at'] ?? now(), 'decision_id' => $decision->id]);
        }
    }

    /** The outside authorities a new record was filled from, for its ISAAR(CPF) sources. */
    private function sourcesText(array $provenance): string
    {
        return collect($provenance)->filter(fn ($p) => is_array($p) && ($p['uri'] ?? null))->unique('uri')
            ->map(fn ($p) => trim(strtoupper((string) $p['source']).' '.$p['uri'].' ('.trim(($p['licence'] ?? '').', '.__('retrieved :date', ['date' => substr((string) ($p['retrieved_at'] ?? ''), 0, 10)]), ', ').')'))
            ->join("\n");
    }
}
