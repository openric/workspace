<?php

// OpenRiC Workspace - loans between members of an institution's union (roadmap phase 16d), after Heratio's ahg-federation
// LoanRequestService and LoanAnalyticsService: a request to borrow an item, from one member of the union to another,
// for a purpose and a window of dates; moved only along Heratio's transitions (requested to approved, declined or
// cancelled; approved to in transit or cancelled; in transit to returned), each move recorded with who made it and when;
// and the figures of them: by status, out and in from the institution's side, the share approved, the days to a
// decision, and the members and items most asked for.
// Changed from Heratio: an item the institution holds itself is its own unit of description, not a free reference; and
// only who administers the institution moves a loan.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Federation;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\FederationLoanRequest;
use App\Models\FederationMember;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use InvalidArgumentException;

class Loans
{
    /** Heratio's TRANSITIONS. */
    public const TRANSITIONS = ['requested' => ['approved', 'declined', 'cancelled'], 'approved' => ['in-transit', 'cancelled'], 'in-transit' => ['returned'],
        'declined' => [], 'returned' => [], 'cancelled' => []];

    public function create(Institution $institution, array $data, User $actor): FederationLoanRequest
    {
        $this->assertMay($actor, $institution);
        $member = fn (?string $id) => $id ? FederationMember::where(['id' => $id, 'institution_id' => $institution->id])->first() : null;
        $requesting = $member($data['requesting_member_id'] ?? null) ?? throw new InvalidArgumentException(__('Choose the member that asks to borrow it.'));
        $holding = $member($data['holding_member_id'] ?? null) ?? throw new InvalidArgumentException(__('Choose the member that holds it.'));
        $requesting->id !== $holding->id || throw new InvalidArgumentException(__('A member does not borrow from itself.'));
        // The institution's own item is its own unit; another member's is the reference that member gives it.
        $unit = $holding->is_self && ($data['record_resource_id'] ?? null)
            ? RecordResource::where(['id' => $data['record_resource_id'], 'institution_id' => $institution->id])->whereNull('deleted_at')->first() : null;
        $ref = $unit?->iri ?? trim((string) ($data['item_ref'] ?? ''));
        $ref !== '' || throw new InvalidArgumentException(__('Name the item: the unit of description, or the holder\'s reference for it.'));
        $title = trim((string) ($data['item_title'] ?? '')) ?: ($unit ? EntityKinds::label($unit) : $ref);
        [$from, $to] = [($data['needed_from'] ?? null) ?: null, ($data['needed_to'] ?? null) ?: null];
        (! $from || ! $to || $to >= $from) || throw new InvalidArgumentException(__('The loan ends on or after the day it begins.'));

        return AuditLog::actingAs($actor, fn () => FederationLoanRequest::create(['institution_id' => $institution->id, 'requesting_member_id' => $requesting->id,
            'holding_member_id' => $holding->id, 'record_resource_id' => $unit?->id, 'item_ref' => mb_substr($ref, 0, 1000), 'item_title' => mb_substr($title, 0, 1000),
            'purpose' => trim((string) ($data['purpose'] ?? '')) ?: null, 'status_id' => $this->status('requested'), 'needed_from' => $from, 'needed_to' => $to,
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null, 'created_by_id' => $actor->id]));
    }

    /** Move a loan along its transitions, a note added to what it says. */
    public function transition(FederationLoanRequest $loan, string $to, ?string $note, User $actor): FederationLoanRequest
    {
        $this->assertMay($actor, Institution::findOrFail($loan->institution_id));
        $from = $loan->status->notation;
        in_array($to, self::TRANSITIONS[$from] ?? [], true) || throw new InvalidArgumentException(__('A loan that is :from is not :to next.', ['from' => $from, 'to' => $to]));
        $note = trim((string) $note);
        AuditLog::actingAs($actor, fn () => $loan->update(['status_id' => $this->status($to), 'decided_by_id' => $actor->id, 'decided_at' => now(),
            'notes' => $note === '' ? $loan->notes : trim(($loan->notes ? $loan->notes."\n" : '').now(config('workspace.timezone'))->format('Y-m-d').' '.$to.': '.$note)]));

        return $loan->fresh();
    }

    /** Heratio's LoanAnalyticsService report. */
    public function report(Institution $institution, int $top = 8): array
    {
        $loans = FederationLoanRequest::where('institution_id', $institution->id)->with('status', 'requesting', 'holding')->get();
        $byStatus = collect(array_keys(self::TRANSITIONS))->mapWithKeys(fn ($s) => [$s => $loans->filter(fn ($l) => $l->status->notation === $s)->count()])->all();
        $self = FederationMember::where(['institution_id' => $institution->id, 'is_self' => true])->value('id');
        $decided = $byStatus['approved'] + $byStatus['in-transit'] + $byStatus['returned'] + $byStatus['declined'];
        $days = $loans->filter(fn ($l) => $l->decided_at && $l->status->notation !== 'requested')->map(fn ($l) => $l->created_at->diffInHours($l->decided_at) / 24);

        return ['total' => $loans->count(), 'by_status' => $byStatus,
            'outgoing' => $loans->where('requesting_member_id', $self)->count(), 'incoming' => $loans->where('holding_member_id', $self)->count(),
            'approval_rate' => $decided > 0 ? round(($decided - $byStatus['declined']) / $decided * 100, 1) : null,
            'average_days_to_decide' => $days->isNotEmpty() ? round($days->avg(), 1) : null,
            'top_requesting' => $loans->countBy(fn ($l) => $l->requesting?->name ?? '?')->sortDesc()->take($top)->all(),
            'top_holding' => $loans->countBy(fn ($l) => $l->holding?->name ?? '?')->sortDesc()->take($top)->all(),
            'top_items' => $loans->countBy('item_title')->sortDesc()->take($top)->all()];
    }

    private function assertMay(User $actor, Institution $institution): void
    {
        ($actor->institution_id === $institution->id && $actor->hasPermission(Vocab::permission('manage-institution')))
            || throw new InvalidArgumentException(__('Only who administers the institution handles its loans.'));
    }

    private function status(string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.'loan-status/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
