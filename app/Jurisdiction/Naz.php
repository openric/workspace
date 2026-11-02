<?php

// OpenRiC Workspace - the National Archives of Zimbabwe pack: closure periods and protected records, research permits,
// records schedules and transfers (roadmap phase 18e, ADR 0016).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's NazController. A closure period (25 years by default, under the National Archives of Zimbabwe Act
// [Chapter 25:06]) or a protection (cabinet, security, personal, legal, commercial) is imposed as a full embargo on the
// record and what is inside it, so the access the Workspace already enforces enforces it too; the closure's type,
// years and authority are kept beside the embargo, and releasing one lifts its embargo. Heratio kept them in tables of
// their own that its access checks did not read. A research permit belongs to a registered researcher (Heratio kept a
// register of researchers of its own; the Workspace's reading room already has one), with its type, topic, period, fee
// and the repositories it opens; it is approved by someone other than who entered it, and expires at the end of its
// period. Records schedules and transfers to the National Archives are kept as Heratio keeps them; a schedule may name
// the disposal class that carries it out in the Workspace.

namespace App\Jurisdiction;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\DisposalClass;
use App\Models\NazRecordsSchedule;
use App\Models\NazResearchPermit;
use App\Models\NazRestriction;
use App\Models\NazTransfer;
use App\Models\RecordResource;
use App\Models\Researcher;
use App\Models\User;
use App\Rights\Embargoes;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Naz
{
    /** The embargo reason each protection is kept under. */
    private const REASONS = ['cabinet' => 'security', 'security' => 'security', 'personal' => 'privacy', 'legal' => 'legal', 'commercial' => 'commercial'];

    public function __construct(private Embargoes $embargoes) {}

    // Closure periods and protections.

    /** Close or protect the record, and what is inside it; the restrictions made. */
    public function restrict(User $actor, RecordResource $record, string $kind, string $type, array $data): array
    {
        if ($record->institution_id !== $actor->institution_id || ! $actor->may('update', 'description', $record)) {
            throw new InvalidArgumentException(__('You may not restrict that record.'));
        }
        $start = ($data['start_date'] ?? '') ?: now(config('workspace.timezone'))->toDateString();
        $years = $kind === 'closure' && $type !== 'indefinite' ? max(1, (int) ($data['years'] ?? 25)) : null;
        $end = ($data['end_date'] ?? '') ?: ($years ? now(config('workspace.timezone'))->parse($start)->addYears($years)->toDateString() : null);
        if ($kind === 'protection' && trim((string) ($data['reason'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Say why the record is protected.'));
        }
        if ($kind === 'closure' && $type === 'ministerial' && trim((string) ($data['authority_reference'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give the reference of the Minister\'s order.'));
        }
        $typeId = $this->concept($kind === 'closure' ? 'naz-closure-type' : 'naz-protection-type', $type);
        $label = Concept::find($typeId)->label(['en']);

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $record, $kind, $typeId, $type, $start, $end, $years, $data, $label) {
            $embargoes = $this->embargoes->impose($record, ['type' => 'full', 'reason' => $kind === 'closure' ? 'legal' : self::REASONS[$type], 'starts_on' => $start, 'ends_on' => $end,
                'note' => trim(($kind === 'closure' ? __('Closure period (National Archives of Zimbabwe Act): :type', ['type' => $label]) : __('Protected record (National Archives of Zimbabwe Act): :type', ['type' => $label]))
                    ."\n".(string) ($data['reason'] ?? '')), 'review_on' => ($data['review_date'] ?? '') ?: null], ! empty($data['inside']), $actor);

            return collect($embargoes)->map(fn ($e) => NazRestriction::create(['record_resource_id' => $e->record_resource_id, 'embargo_id' => $e->id, 'kind' => $kind,
                $kind === 'closure' ? 'closure_type_id' : 'protection_type_id' => $typeId, 'years' => $years, 'authority_reference' => trim((string) ($data['authority_reference'] ?? '')) ?: null,
                'created_by_id' => $actor->id]))->all();
        }));
    }

    /** Release a closure or lift a protection: its embargo is lifted. */
    public function release(User $actor, NazRestriction $restriction, ?string $reason): void
    {
        if (! $actor->may('update', 'description', $restriction->record)) {
            throw new InvalidArgumentException(__('You may not release that record.'));
        }
        if (trim((string) $reason) === '') {
            throw new InvalidArgumentException(__('Say why it is released.'));
        }
        $this->embargoes->lift($restriction->embargo, $reason, $actor);
    }

    // Research permits.

    public function savePermit(User $actor, array $data, ?NazResearchPermit $permit = null): NazResearchPermit
    {
        $researcher = Researcher::where('institution_id', $actor->institution_id)->find($data['researcher_id'] ?? null) ?? throw new InvalidArgumentException(__('Choose a registered researcher.'));
        if (trim((string) ($data['research_topic'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give the topic of the research.'));
        }
        if (($data['start_date'] ?? '') === '' || ($data['end_date'] ?? '') === '' || $data['end_date'] < $data['start_date']) {
            throw new InvalidArgumentException(__('Give the permit a period, its end on or after its start.'));
        }
        $fields = ['researcher_id' => $researcher->id, 'permit_type_id' => $this->concept('naz-permit-type', (string) ($data['permit_type'] ?? 'general')), 'research_topic' => mb_substr(trim($data['research_topic']), 0, 500),
            'research_purpose' => trim((string) ($data['research_purpose'] ?? '')) ?: null, 'start_date' => $data['start_date'], 'end_date' => $data['end_date'],
            'fee_amount' => max(0, (float) ($data['fee_amount'] ?? 0)), 'fee_currency' => strtoupper((string) ($data['fee_currency'] ?? 'USD')) ?: 'USD', 'fee_paid' => ! empty($data['fee_paid']),
            'fee_receipt' => trim((string) ($data['fee_receipt'] ?? '')) ?: null, 'payment_date' => ($data['payment_date'] ?? '') ?: null,
            'repository_ids' => array_values(array_filter((array) ($data['repository_ids'] ?? []))) ?: null, 'restrictions' => trim((string) ($data['restrictions'] ?? '')) ?: null];

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $permit ? tap($permit)->update($fields) : NazResearchPermit::create($fields + ['institution_id' => $actor->institution_id,
            'permit_number' => $this->number('naz_research_permits', 'permit_number', 'NAZ-RP', $actor->institution_id), 'status_id' => $this->concept('naz-permit-status', 'pending'), 'created_by_id' => $actor->id])));
    }

    /** approve, reject or revoke. */
    public function decidePermit(User $actor, NazResearchPermit $permit, string $step, ?string $reason = null): void
    {
        $from = $permit->status->notation;
        $to = match ($step) {
            'approve' => in_array($from, ['pending'], true) ? 'approved' : null,
            'reject' => in_array($from, ['pending'], true) ? 'rejected' : null,
            'revoke' => in_array($from, ['approved', 'active'], true) ? 'revoked' : null,
        } ?? throw new InvalidArgumentException(__('That cannot be done to a permit that is :status.', ['status' => mb_strtolower($permit->status->label(['en']))]));
        if ($step === 'approve' && $permit->created_by_id === $actor->id) {
            throw new InvalidArgumentException(__('Someone other than who entered it approves it.'));
        }
        if ($step !== 'approve' && trim((string) $reason) === '') {
            throw new InvalidArgumentException(__('Give the reason.'));
        }
        $today = now(config('workspace.timezone'))->toDateString();
        $to = $to === 'approved' && $permit->start_date->toDateString() <= $today ? 'active' : $to;
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $permit->update(['status_id' => $this->concept('naz-permit-status', $to)]
            + ($step === 'approve' ? ['approved_by_id' => $actor->id, 'approved_on' => $today] : ['rejection_reason' => trim($reason)]))));
    }

    /** The daily run: approved permits whose period has begun become active; active ones whose period has ended expire. */
    public function advancePermits(): int
    {
        $today = now(config('workspace.timezone'))->toDateString();
        $changed = 0;
        foreach (NazResearchPermit::with('status')->whereHas('status', fn ($q) => $q->whereIn('iri', [Vocab::BASE.'naz-permit-status/approved', Vocab::BASE.'naz-permit-status/active']))->get() as $p) {
            $to = $p->end_date->toDateString() < $today ? 'expired' : ($p->status->notation === 'approved' && $p->start_date->toDateString() <= $today ? 'active' : null);
            if ($to) {
                AuditLog::actingAs(null, fn () => $p->update(['status_id' => $this->concept('naz-permit-status', $to)]));
                $changed++;
            }
        }

        return $changed;
    }

    // Records schedules and transfers.

    public function saveSchedule(User $actor, array $data, ?NazRecordsSchedule $schedule = null): NazRecordsSchedule
    {
        foreach (['agency_name' => __('Name the agency.'), 'record_series' => __('Name the series of records.')] as $k => $message) {
            if (trim((string) ($data[$k] ?? '')) === '') {
                throw new InvalidArgumentException($message);
            }
        }
        $class = ($data['disposal_class_id'] ?? '') ? DisposalClass::whereKey($data['disposal_class_id'])->whereHas('schedule', fn ($q) => $q->where('institution_id', $actor->institution_id))->first()
            ?? throw new InvalidArgumentException(__('Choose a disposal class of this institution.')) : null;
        $fields = ['agency_name' => trim($data['agency_name']), 'agency_code' => trim((string) ($data['agency_code'] ?? '')) ?: null, 'record_series' => trim($data['record_series']),
            'description' => trim((string) ($data['description'] ?? '')) ?: null, 'retention_active_years' => max(0, (int) ($data['retention_active_years'] ?? 0)),
            'retention_semi_active_years' => max(0, (int) ($data['retention_semi_active_years'] ?? 0)), 'disposal_action_id' => $this->concept('disposal-action-type', (string) ($data['disposal_action'] ?? 'review')),
            'legal_authority' => trim((string) ($data['legal_authority'] ?? '')) ?: null,
            'records_class_id' => ($data['records_class'] ?? '') ? $this->concept('naz-records-class', $data['records_class']) : null,
            'access_restriction_id' => ($data['access_restriction'] ?? '') ? $this->concept('naz-access-restriction', $data['access_restriction']) : null, 'disposal_class_id' => $class?->id,
            'approved_by' => trim((string) ($data['approved_by'] ?? '')) ?: null, 'approval_date' => ($data['approval_date'] ?? '') ?: null, 'effective_date' => ($data['effective_date'] ?? '') ?: null,
            'review_date' => ($data['review_date'] ?? '') ?: null, 'status_id' => $this->concept('naz-schedule-status', (string) ($data['status'] ?? 'draft')), 'notes' => trim((string) ($data['notes'] ?? '')) ?: null];
        if (in_array($data['status'] ?? 'draft', ['approved'], true) && ! $fields['approved_by']) {
            throw new InvalidArgumentException(__('Name who approved the schedule.'));
        }

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $schedule ? tap($schedule)->update($fields) : NazRecordsSchedule::create($fields + ['institution_id' => $actor->institution_id,
            'schedule_number' => $this->number('naz_records_schedules', 'schedule_number', 'NAZ-RS', $actor->institution_id), 'created_by_id' => $actor->id])));
    }

    public function saveTransfer(User $actor, array $data, ?NazTransfer $transfer = null): NazTransfer
    {
        if (trim((string) ($data['transferring_agency'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Name the transferring agency.'));
        }
        $schedule = ($data['schedule_id'] ?? '') ? NazRecordsSchedule::where('institution_id', $actor->institution_id)->find($data['schedule_id']) : null;
        $status = (string) ($data['status'] ?? 'proposed');
        if ($status === 'accessioned' && trim((string) ($data['accession_number'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give the accession number the National Archives assigned.'));
        }
        if ($status === 'rejected' && trim((string) ($data['rejection_reason'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give the reason.'));
        }
        $text = fn (string $k) => trim((string) ($data[$k] ?? '')) ?: null;
        $fields = ['transferring_agency' => trim($data['transferring_agency']), 'agency_contact' => $text('agency_contact'), 'agency_email' => $text('agency_email'), 'agency_phone' => $text('agency_phone'),
            'schedule_id' => $schedule?->id, 'transfer_type_id' => $this->concept('naz-transfer-type', (string) ($data['transfer_type'] ?? 'scheduled')), 'description' => $text('description'),
            'date_range_start' => $text('date_range_start'), 'date_range_end' => $text('date_range_end'), 'quantity_linear_metres' => $text('quantity_linear_metres'),
            'quantity_boxes' => $text('quantity_boxes'), 'quantity_items' => $text('quantity_items'), 'contains_restricted' => ! empty($data['contains_restricted']),
            'restriction_details' => $text('restriction_details'), 'accession_number' => $text('accession_number'), 'proposed_date' => $text('proposed_date'), 'actual_date' => $text('actual_date'),
            'status_id' => $this->concept('naz-transfer-status', $status), 'rejection_reason' => $text('rejection_reason'), 'location_assigned' => $text('location_assigned'), 'notes' => $text('notes')]
            + (in_array($status, ['received', 'accessioned'], true) && ! $transfer?->received_by_id ? ['received_by_id' => $actor->id] : []);

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $transfer ? tap($transfer)->update($fields) : NazTransfer::create($fields + ['institution_id' => $actor->institution_id,
            'transfer_number' => $this->number('naz_transfers', 'transfer_number', 'NAZ-TR', $actor->institution_id), 'created_by_id' => $actor->id])));
    }

    /** PREFIX-YYYY-NNNN within the institution and year. */
    private function number(string $table, string $column, string $prefix, string $institutionId): string
    {
        $year = now(config('workspace.timezone'))->format('Y');

        return sprintf('%s-%s-%04d', $prefix, $year, DB::table($table)->where('institution_id', $institutionId)->where($column, 'like', "$prefix-$year-%")->count() + 1);
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
