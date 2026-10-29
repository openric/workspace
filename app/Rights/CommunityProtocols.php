<?php

// OpenRiC Workspace - community protocols and the gate that withholds material from the public (Heratio's ahg-core
// TermProtocolService and TermProtocolGate, ahg-icip).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A term of the institution's own vocabularies may carry a protocol: an access condition, with the Label, the owning
// community and the region (setProtocol). A record's condition is the strictest of its tagged terms' protocols and its
// Labels' conditions, a Label's own on the record or else its default. Where Heratio lists the withholding conditions
// in a constant, here they are the concepts under "Withheld from the public" in the community-access-condition
// vocabulary. A withheld term or record is hidden from the public and from accounts that may not change it; those
// who may change it always see it, as Heratio's staff bypass. Not copied yet: the AI fence (isModelEligible), which
// comes with AI assistance in phase 17, and a protocol's owning community seeing its own material, Heratio's phase 3.

namespace App\Rights;

use App\Audit\AuditLog;
use App\Models\Agent;
use App\Models\CommunityLabel;
use App\Models\Concept;
use App\Models\ConceptProtocol;
use App\Models\RecordResource;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CommunityProtocols
{
    public const CONDITIONS = Vocab::BASE.'community-access-condition/';

    /** @var \WeakMap<object, array<string, array{concepts: array<string, true>, records: array<string, true>}>>|null per request, by institution */
    private static ?\WeakMap $withheld = null;

    /** Forget what was worked out, after a protocol or Label changed. */
    public static function forget(): void
    {
        self::$withheld = null;
    }

    /** A condition by its notation; not one of the two groups. */
    public static function condition(string $notation): Concept
    {
        return Concept::choices(self::CONDITIONS)->whereNotNull('broader_id')->where('notation', $notation)->first()
            ?? throw new InvalidArgumentException(__('choose an access condition'));
    }

    /**
     * Set the protocol of a term of the institution's own vocabularies, or remove it with an open condition and no Label,
     * as Heratio's TermProtocolService::set. Fields: access_condition, label (notation), community (an authority record's key), region.
     */
    public function setProtocol(Concept $concept, array $fields, ?User $actor = null): ?ConceptProtocol
    {
        if ($concept->institution_id === null) {
            throw new InvalidArgumentException(__('a protocol is set on a term of the institution\'s own vocabularies'));
        }
        $condition = self::condition((string) ($fields['access_condition'] ?? 'open'));
        $label = ($fields['label'] ?? '') === '' ? null : (Concept::choices(CommunityLabelService::LABELS)->whereNotNull('broader_id')->where('notation', $fields['label'])->first()
            ?? throw new InvalidArgumentException(__('choose a Traditional Knowledge or Biocultural Label')));
        $community = ($fields['community'] ?? '') === '' ? null : (Agent::where('institution_id', $concept->institution_id)->whereNull('deleted_at')->find($fields['community'])
            ?? throw new InvalidArgumentException(__('the community is an authority record of the institution')));
        $existing = ConceptProtocol::where('concept_id', $concept->id)->first();

        $protocol = DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($concept, $condition, $label, $community, $fields, $existing) {
            if ($condition->notation === 'open' && $label === null) {
                $existing?->delete();

                return null;
            }
            $values = ['access_condition_id' => $condition->id, 'label_id' => $label?->id, 'community_id' => $community?->id,
                'region' => trim((string) ($fields['region'] ?? '')) ?: null];

            return $existing ? tap($existing)->update($values) : ConceptProtocol::create($values + ['institution_id' => $concept->institution_id, 'concept_id' => $concept->id]);
        }));
        self::forget();

        return $protocol;
    }

    /** The condition a Label brings by default (Heratio's default_access_condition), open when none is set. */
    public function labelDefault(Concept $label): Concept
    {
        $id = DB::table('community_label_conditions')->where('label_id', $label->id)->value('access_condition_id');

        return $id ? Concept::with('labels', 'broader')->find($id) : self::condition('open');
    }

    /** The condition a Label applied to a record sets: its own on the record, or the Label's default. */
    public function conditionOf(CommunityLabel $applied): Concept
    {
        return $applied->accessCondition ?? $this->labelDefault($applied->label);
    }

    /** Is the term or record withheld from the public by a community protocol? Other kinds never are. */
    public function withholds(Model $entity): bool
    {
        $kind = match (true) {
            $entity instanceof Concept => 'concepts',
            $entity instanceof RecordResource => 'records',
            default => null,
        };
        $institution = $entity->getAttribute('institution_id');

        return $kind !== null && $institution !== null && isset($this->withheld($institution)[$kind][$entity->getKey()]);
    }

    /** May the account, or the public with none, see the entity, as far as community protocols go? Who may change it always may. */
    public function allows(?User $user, Model $entity, string $permission): bool
    {
        return ! $this->withholds($entity) || ($user !== null && $user->may('update', $permission, $entity));
    }

    /** The withheld terms and records of an institution, worked out once a request. */
    private function withheld(string $institutionId): array
    {
        self::$withheld ??= new \WeakMap;
        $request = app('request');
        if (isset(self::$withheld[$request][$institutionId])) {
            return self::$withheld[$request][$institutionId];
        }
        $withholding = DB::table('concepts as c')->join('concepts as g', 'g.id', '=', 'c.broader_id')->where('g.iri', self::CONDITIONS.'withheld')->pluck('c.id');
        $concepts = DB::table('concept_protocols')->where('institution_id', $institutionId)->whereIn('access_condition_id', $withholding)->pluck('concept_id');
        $tagged = DB::table('relations')->where(['institution_id' => $institutionId, 'source_table' => 'record_resources', 'target_table' => 'concepts'])
            ->whereIn('target_id', $concepts)->pluck('source_id');
        $labelled = DB::table('community_labels as l')->leftJoin('community_label_conditions as d', 'd.label_id', '=', 'l.label_id')
            ->where(['l.institution_id' => $institutionId, 'l.subject_table' => 'record_resources'])
            ->whereIn(DB::raw('COALESCE(l.access_condition_id, d.access_condition_id)'), $withholding)->pluck('l.subject_id');

        $sets = self::$withheld[$request] ?? [];
        $sets[$institutionId] = ['concepts' => array_fill_keys($concepts->all(), true), 'records' => array_fill_keys([...$tagged, ...$labelled], true)];
        self::$withheld[$request] = $sets;

        return $sets[$institutionId];
    }
}
