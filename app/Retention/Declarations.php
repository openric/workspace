<?php

// OpenRiC Workspace - units of description declared as records, and vital records (roadmap phase 13c, ADR 0015).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's RecordDeclarationService and VitalRecordService. A declaration is asked for, then approved, or
// rejected, by someone who takes part in retention and disposal; when the institution keeps a workflow named "Record
// declaration", asking starts it, as Heratio's does. Rules for declaration are tried then, and place a record that has
// no place yet. Changed: Heratio's declared record was a status and nothing more, though its DoD 5015.2 check says a
// declared record cannot be changed; here a declared record, and its instantiations, cannot be deleted except by its
// disposal once cleared, so what was declared stays as evidence until its retention ends. A vital record (Heratio's
// vital_record) is one the institution needs to go on working: it has a reason, a review cycle of days and its next
// review; reviewing it sets the next.

namespace App\Retention;

use App\Audit\AuditLog;
use App\Description\WorkflowService;
use App\Models\Concept;
use App\Models\DisposalAction;
use App\Models\RecordDeclaration;
use App\Models\RecordResource;
use App\Models\User;
use App\Models\VitalRecord;
use App\Models\Workflow;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Declarations
{
    public function __construct(private ClassificationRules $rules) {}

    public function request(User $actor, RecordResource $record, ?string $notes = null): RecordDeclaration
    {
        $this->mayChange($actor, $record);
        $existing = RecordDeclaration::where('record_resource_id', $record->id)->with('status')->first();
        if ($existing && in_array($existing->status->notation, ['declared', 'pending-approval'], true)) {
            throw new InvalidArgumentException($existing->status->notation === 'declared' ? __('It is declared a record already.') : __('Its declaration awaits approval.'));
        }

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $record, $notes, $existing) {
            $fields = ['status_id' => $this->concept('declaration-status', 'pending-approval'), 'requested_by_id' => $actor->id, 'requested_at' => now(), 'declared_by_id' => null,
                'declared_at' => null, 'notes' => trim((string) $notes) ?: null];
            $declaration = $existing ? tap($existing)->update($fields) : RecordDeclaration::create($fields + ['record_resource_id' => $record->id]);
            $flow = Workflow::where(['institution_id' => $record->institution_id, 'is_active' => true])->whereRaw('lower(name) = ?', ['record declaration'])->first();
            if ($flow) {
                app(WorkflowService::class)->start($record, $actor, $flow);
            }

            return $declaration;
        }));
    }

    /** Approve or reject a declaration that awaits it. */
    public function decide(User $actor, RecordDeclaration $declaration, bool $approve, ?string $notes = null): void
    {
        $this->mayChange($actor, $declaration->record);
        if ($declaration->status->notation !== 'pending-approval') {
            throw new InvalidArgumentException(__('It does not await approval.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $declaration, $approve, $notes) {
            $declaration->update(['status_id' => $this->concept('declaration-status', $approve ? 'declared' : 'rejected'), 'declared_by_id' => $approve ? $actor->id : null,
                'declared_at' => $approve ? now() : null, 'notes' => trim((string) $notes) ?: $declaration->notes]);
            if ($approve) {
                $this->rules->classify($actor, $declaration->record, 'declare');
            }
        }));
    }

    /** Declared at once, as a captured e-mail is. */
    public function declareNow(User $actor, RecordResource $record, ?string $notes = null): RecordDeclaration
    {
        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => RecordDeclaration::updateOrCreate(['record_resource_id' => $record->id], [
            'status_id' => $this->concept('declaration-status', 'declared'), 'requested_by_id' => $actor->id, 'requested_at' => now(), 'declared_by_id' => $actor->id, 'declared_at' => now(),
            'notes' => $notes])));
    }

    /** Declared, and no disposal of it has been cleared: it may not be deleted. */
    public static function locked(RecordResource $record): bool
    {
        return RecordDeclaration::where('record_resource_id', $record->id)->whereHas('status', fn ($q) => $q->where('iri', Vocab::BASE.'declaration-status/declared'))->exists()
            && ! DisposalAction::where('record_resource_id', $record->id)->whereHas('status', fn ($q) => $q->where('iri', Vocab::BASE.'disposal-status/cleared'))->exists();
    }

    // Vital records.

    public function flagVital(User $actor, RecordResource $record, string $reason, int $cycleDays): VitalRecord
    {
        $this->mayChange($actor, $record);
        if (trim($reason) === '') {
            throw new InvalidArgumentException(__('Say why it is vital.'));
        }
        if ($cycleDays < 1 || $cycleDays > 3650) {
            throw new InvalidArgumentException(__('Review it every 1 to 3650 days.'));
        }

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => VitalRecord::updateOrCreate(['record_resource_id' => $record->id], ['reason' => trim($reason),
            'review_cycle_days' => $cycleDays, 'next_review_on' => now(config('workspace.timezone'))->addDays($cycleDays)->toDateString(), 'is_active' => true, 'created_by_id' => $actor->id])));
    }

    public function unflagVital(User $actor, VitalRecord $vital): void
    {
        $this->mayChange($actor, $vital->record);
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $vital->update(['is_active' => false])));
    }

    public function reviewVital(User $actor, VitalRecord $vital): void
    {
        $this->mayChange($actor, $vital->record);
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $vital->update(['last_reviewed_at' => now(), 'last_reviewed_by_id' => $actor->id,
            'next_review_on' => now(config('workspace.timezone'))->addDays($vital->review_cycle_days)->toDateString()])));
    }

    private function mayChange(User $actor, RecordResource $record): void
    {
        if ($record->institution_id !== $actor->institution_id || ! $actor->may('delete', 'description', $record)) {
            throw new InvalidArgumentException(__('You may not manage that record.'));
        }
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
