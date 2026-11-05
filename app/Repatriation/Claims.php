<?php

/*
 * OpenRiC Workspace - copied from Heratio, packages/ahg-semantic-search/src/Services/RepatriationClaimService.php at
 * commit bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md, roadmap phase 17d2): register,
 * update and updateStatus (each change of status kept with who made it and why, and told), list, statusCounts,
 * dashboard and topGroup, virtualReturn and the disclaimer. Changed: a claim is a model of its institution on one of
 * its descriptions, the status a vocabulary row (Heratio's VARCHAR with a canonical list), the claimant and the place
 * of origin perhaps an authority record and a place; the first status is kept too; the item's public context is the
 * public catalogue's own view of it, so an unpublished description shows nothing; the namespace and code style. Not
 * copied: the probes of tables that may not exist, and the trace in the displaced-heritage register, which is 17d3.
 * Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Repatriation;

use App\Api\PublicCatalogue;
use App\Audit\AuditLog;
use App\Models\Agent;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\Place;
use App\Models\RecordResource;
use App\Models\RepatriationClaim;
use App\Models\RepatriationStatusChange;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Claims
{
    public const STATUSES = Vocab::BASE.'repatriation-claim-status/';

    /** The statuses that end a claim's dialogue in a return, physical or virtual. */
    public const RETURNS = ['virtual-return', 'returned'];

    /** Shown wherever claims are: a claim record is a documented request and its status, never a legal determination. */
    public const DISCLAIMER = 'A repatriation claim record documents a request and where the dialogue around it currently stands, recorded in a spirit of transparency and care. A claim, and any status it carries, is not a legal determination, not a finding of wrongful removal, and not advice. Origin, ownership and lawful-transfer history are matters for the relevant communities, holding institutions and qualified staff to assess together, case by case, under the applicable law.';

    private const FIELDS = ['claimant_community', 'origin_place', 'current_holder', 'evidence_summary', 'contact', 'contact_email', 'notes'];

    public function __construct(private Notifier $notifier) {}

    public static function status(string $notation): string
    {
        return Concept::where('iri', self::STATUSES.$notation)->valueOrFail('id');
    }

    /**
     * Register a claim about one of the institution's descriptions. $data: claimant_community, claimant_agent_id,
     * origin_place, origin_place_id, current_holder (the description's holder when not given), evidence_summary,
     * contact, contact_email, notes, status (a notation, registered when not given).
     */
    public function register(RecordResource $record, array $data, ?User $actor, bool $public = false): RepatriationClaim
    {
        $values = $this->values(Institution::findOrFail($record->institution_id), $data);
        $values['current_holder'] ??= $record->repository ? EntityKinds::label($record->repository, Vocab::languages()) : null;
        $status = $this->statusId($data['status'] ?? 'registered');

        $claim = DB::transaction(function () use ($record, $values, $status, $actor, $public) {
            $claim = AuditLog::actingAs($actor, fn () => RepatriationClaim::create([...$values, 'institution_id' => $record->institution_id,
                'record_resource_id' => $record->id, 'status_id' => $status, 'lodged_publicly' => $public, 'created_by_id' => $actor?->id]));
            $this->logStatus($claim, null, $status, null, $actor, $public ? ($values['claimant_community'] ?? null) : null);

            return $claim;
        });
        $this->notifier->registered($claim, $actor);

        return $claim;
    }

    public function update(RepatriationClaim $claim, array $data, User $actor): RepatriationClaim
    {
        $values = $this->values(Institution::findOrFail($claim->institution_id), $data);
        $to = $this->statusId($data['status'] ?? $claim->status->notation);
        $from = $claim->status_id;
        DB::transaction(function () use ($claim, $values, $to, $from, $actor) {
            AuditLog::actingAs($actor, fn () => $claim->update([...$values, 'status_id' => $to]));
            if ($from !== $to) {
                $this->logStatus($claim, $from, $to, null, $actor);
            }
        });
        if ($from !== $to) {
            $this->notifier->statusChanged($claim->fresh(), $from, $to, $actor);
        }

        return $claim->fresh();
    }

    /** Move a claim on, with a note; the same status again with a note is kept as a note. */
    public function setStatus(RepatriationClaim $claim, string $notation, ?string $note, User $actor): void
    {
        $to = $this->statusId($notation);
        $from = $claim->status_id;
        $note = ($note = trim((string) $note)) === '' ? null : mb_substr($note, 0, 1024);
        if ($from === $to && $note === null) {
            return;
        }
        DB::transaction(function () use ($claim, $from, $to, $note, $actor) {
            AuditLog::actingAs($actor, fn () => $claim->update(['status_id' => $to]));
            $this->logStatus($claim, $from, $to, $note, $actor);
        });
        if ($from !== $to) {
            $this->notifier->statusChanged($claim->fresh(), $from, $to, $actor);
        }
    }

    public function list(Institution $institution, ?string $status = null): Collection
    {
        return RepatriationClaim::where('institution_id', $institution->id)->with('status.labels', 'record')
            ->when($status, fn ($q) => $q->whereHas('status', fn ($s) => $s->where('iri', self::STATUSES.$status)))
            ->orderByDesc('updated_at')->limit(500)->get();
    }

    /** @return array<string,int> claims by status notation */
    public function statusCounts(Institution $institution): array
    {
        return RepatriationClaim::where('repatriation_claims.institution_id', $institution->id)->join('concepts', 'concepts.id', '=', 'repatriation_claims.status_id')
            ->groupBy('concepts.iri')->selectRaw('concepts.iri, count(*) AS c')->pluck('c', 'iri')
            ->mapWithKeys(fn ($c, $iri) => [substr($iri, strlen(self::STATUSES)) => (int) $c])->all();
    }

    /**
     * The register at a glance: counts by status in the vocabulary's order, the places and communities claims come
     * from, returns against claims still in dialogue, and the latest. $catalogue limits the latest to the published
     * descriptions, for the public catalogue.
     */
    public function dashboard(Institution $institution, ?PublicCatalogue $catalogue = null, int $top = 12, int $recent = 8): array
    {
        $counts = $this->statusCounts($institution);
        $total = array_sum($counts);
        $byStatus = Concept::choices(self::STATUSES)->get()->map(fn (Concept $c) => ['concept' => $c, 'count' => $counts[$c->notation] ?? 0])->all();
        $returns = array_sum(array_map(fn ($n) => $counts[$n] ?? 0, self::RETURNS));
        $latest = RepatriationClaim::where('institution_id', $institution->id)->with('status.labels', 'record')->orderByDesc('updated_at')
            ->limit($catalogue ? 200 : $recent)->get();
        if ($catalogue) {
            $latest = $latest->filter(fn (RepatriationClaim $c) => $catalogue->find('records', $c->record_resource_id) !== null)->take($recent)->values();
        }

        return ['total' => $total, 'by_status' => $byStatus, 'virtual_return' => $counts['virtual-return'] ?? 0, 'returned' => $counts['returned'] ?? 0,
            'in_dialogue' => max(0, $total - $returns), 'by_origin' => $this->topGroup($institution, 'origin_place', $top),
            'by_community' => $this->topGroup($institution, 'claimant_community', $top), 'recent' => $latest];
    }

    /** The claim with its description as the public catalogue shows it, or null for a description it does not show. */
    public function virtualReturn(RepatriationClaim $claim, PublicCatalogue $catalogue): ?array
    {
        $unit = $catalogue->find('records', $claim->record_resource_id);

        return $unit === null ? null : ['claim' => $claim, 'unit' => $unit, 'disclaimer' => self::DISCLAIMER];
    }

    /** @return list<array{value:string,count:int}> */
    private function topGroup(Institution $institution, string $column, int $limit): array
    {
        return RepatriationClaim::where('institution_id', $institution->id)->whereNotNull($column)->where($column, '!=', '')
            ->groupBy($column)->selectRaw("$column AS value, count(*) AS c")->orderByDesc('c')->orderBy('value')->limit($limit)->get()
            ->map(fn ($r) => ['value' => (string) $r->value, 'count' => (int) $r->c])->all();
    }

    private function logStatus(RepatriationClaim $claim, ?string $from, string $to, ?string $note, ?User $actor, ?string $name = null): void
    {
        RepatriationStatusChange::create(['claim_id' => $claim->id, 'from_status_id' => $from, 'to_status_id' => $to, 'note' => $note,
            'changed_by_id' => $actor?->id, 'changed_by_name' => $actor?->name ?? ($name !== null ? mb_substr($name, 0, 255) : null)]);
    }

    private function statusId(string $notation): string
    {
        return Concept::where('iri', self::STATUSES.$notation)->value('id') ?? throw new InvalidArgumentException(__('That is not a status of a repatriation claim.'));
    }

    private function values(Institution $institution, array $data): array
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $v = trim((string) ($data[$field] ?? ''));
            $values[$field] = $v === '' ? null : (in_array($field, ['evidence_summary', 'notes'], true) ? $v : mb_substr($v, 0, $field === 'contact_email' ? 255 : 512));
        }
        if ($values['contact_email'] !== null && filter_var($values['contact_email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException(__('Give the claimant\'s email as an address, such as name@example.org.'));
        }
        foreach (['claimant_agent_id' => Agent::class, 'origin_place_id' => Place::class] as $field => $class) {
            $id = $data[$field] ?? null;
            $values[$field] = $id ? ($class::where('institution_id', $institution->id)->whereNull('deleted_at')->find($id)?->id
                ?? throw new InvalidArgumentException($class === Place::class ? __('That place does not exist.') : __('That authority record does not exist.'))) : null;
        }

        return $values;
    }
}
