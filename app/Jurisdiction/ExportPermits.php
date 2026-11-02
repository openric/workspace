<?php

// OpenRiC Workspace - permits to export heritage objects from South Africa under the National Heritage Resources Act,
// 1999, s.32 (roadmap phase 18d, ADR 0016).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After PSIS's ahgSAHRAPlugin (SahraPermitService: createApplication, endorse, reject, submitToSahra, recordDecision,
// revoke, cancel, expireOverdue, its documents), its section 32 only, by Dr Johan Pieterse's choice 2A of 2 November
// 2026: its archaeology, palaeontology, structures and burial permits belong to other heritage work, not to an
// archive's. An application names the records to be exported, where they go and for how long; it is approved within
// the institution by someone who may change those records (PSIS's supervisor endorsement), lodged with SAHRA or a
// provincial heritage resources authority, and its outcome recorded: issued with the permit number, period and
// conditions, or declined. An issued permit expires when its period ends, or is revoked, or closed when the records are
// back. The documents (the application, the authority's letter, the permit) are kept in the object store.

namespace App\Jurisdiction;

use App\Audit\AuditLog;
use App\Files\FileStore;
use App\Models\Concept;
use App\Models\ExportPermit;
use App\Models\RecordResource;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ExportPermits
{
    /** What may follow what: the step and the statuses it may follow, as PSIS's workflow allows. */
    private const STEPS = ['submit-for-approval' => ['draft', 'returned'], 'approve' => ['pending-approval'], 'return' => ['pending-approval'], 'lodge' => ['approved'],
        'issued' => ['submitted'], 'declined' => ['submitted'], 'revoke' => ['issued'], 'close' => ['issued', 'expired'], 'cancel' => ['draft', 'pending-approval', 'approved', 'returned']];

    public function __construct(private FileStore $store) {}

    public function save(User $applicant, array $data, ?ExportPermit $permit = null): ExportPermit
    {
        if ($permit && ($permit->applicant_id !== $applicant->id || ! in_array($permit->status->notation, ['draft', 'returned'], true))) {
            throw new InvalidArgumentException(__('Only its applicant changes it, and only before it is approved.'));
        }
        if (trim((string) ($data['purpose'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Say why the records are to leave the country.'));
        }
        $start = ($data['start_date'] ?? '') ?: null;
        $end = ($data['end_date'] ?? '') ?: null;
        if ($start && $end && $end < $start) {
            throw new InvalidArgumentException(__('The end date comes before the start.'));
        }
        $country = strtoupper(trim((string) ($data['destination_country'] ?? '')));
        if ($country !== '' && ! preg_match('/^[A-Z]{2}$/', $country)) {
            throw new InvalidArgumentException(__('Give the country as its two-letter ISO code.'));
        }
        $iris = array_filter(array_map('trim', preg_split('/\R/', (string) ($data['record_iris'] ?? ''))));
        $records = RecordResource::where('institution_id', $applicant->institution_id)->whereNull('deleted_at')->whereIn('iri', $iris)->get();
        if (! $iris || $records->count() !== count(array_unique($iris))) {
            throw new InvalidArgumentException(__('Name every record to export by its persistent IRI, one a line.'));
        }
        $fields = ['issuing_authority' => trim((string) ($data['issuing_authority'] ?? '')) ?: 'SAHRA', 'purpose' => mb_substr(trim($data['purpose']), 0, 1000),
            'description' => trim((string) ($data['description'] ?? '')) ?: null, 'destination_institution' => trim((string) ($data['destination_institution'] ?? '')) ?: null,
            'destination_country' => $country ?: null, 'start_date' => $start, 'end_date' => $end,
            'fee_amount' => ($data['fee_amount'] ?? '') !== '' && $data['fee_amount'] !== null ? max(0, (float) $data['fee_amount']) : null];

        return DB::transaction(fn () => AuditLog::actingAs($applicant, function () use ($applicant, $permit, $fields, $records) {
            $permit = $permit ? tap($permit)->update($fields) : ExportPermit::create($fields + ['institution_id' => $applicant->institution_id, 'application_ref' => $this->reference($applicant->institution_id),
                'applicant_id' => $applicant->id, 'status_id' => $this->status('draft')]);
            $permit->records()->whereNotIn('record_resource_id', $records->pluck('id'))->delete();
            foreach ($records as $r) {
                $permit->records()->firstOrCreate(['record_resource_id' => $r->id]);
            }

            return $permit;
        }));
    }

    /** One step of the workflow; who may take it, and what it records, as PSIS's service has it. */
    public function step(User $actor, ExportPermit $permit, string $step, array $data = []): void
    {
        $from = $permit->status->notation;
        if (! in_array($from, self::STEPS[$step] ?? [], true)) {
            throw new InvalidArgumentException(__('That cannot be done to an application that is :status.', ['status' => mb_strtolower($permit->status->label(['en']))]));
        }
        $applicantStep = in_array($step, ['submit-for-approval', 'cancel'], true);
        if ($applicantStep && $permit->applicant_id !== $actor->id) {
            throw new InvalidArgumentException(__('Only its applicant does that.'));
        }
        if (! $applicantStep) {
            if ($permit->applicant_id === $actor->id) {
                throw new InvalidArgumentException(__('Someone other than its applicant does that.'));
            }
            if ($permit->institution_id !== $actor->institution_id || $permit->records()->with('record')->get()->contains(fn ($r) => ! $actor->may('update', 'description', $r->record))) {
                throw new InvalidArgumentException(__('Only who may change every record it covers does that.'));
            }
        }
        if ($step === 'issued' && trim((string) ($data['permit_number'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give the permit number the authority issued.'));
        }
        $notes = trim((string) ($data['notes'] ?? '')) ?: null;
        $fields = match ($step) {
            'submit-for-approval' => ['status_id' => $this->status('pending-approval')],
            'approve', 'return' => ['status_id' => $this->status($step === 'approve' ? 'approved' : 'returned'), 'approved_by_id' => $actor->id, 'approved_at' => now(), 'approval_notes' => $notes],
            'lodge' => ['status_id' => $this->status('submitted'), 'submitted_at' => now(), 'authority_reference' => trim((string) ($data['authority_reference'] ?? '')) ?: null],
            'issued' => ['status_id' => $this->status('issued'), 'decided_at' => now(), 'permit_number' => trim($data['permit_number']), 'authority_notes' => $notes,
                'conditions' => trim((string) ($data['conditions'] ?? '')) ?: $permit->conditions, 'start_date' => ($data['start_date'] ?? '') ?: $permit->start_date, 'end_date' => ($data['end_date'] ?? '') ?: $permit->end_date],
            'declined' => ['status_id' => $this->status('declined'), 'decided_at' => now(), 'authority_notes' => $notes],
            'revoke' => ['status_id' => $this->status('revoked'), 'authority_notes' => trim(($permit->authority_notes ? $permit->authority_notes."\n" : '').(string) $notes) ?: null],
            'close' => ['status_id' => $this->status('closed')],
            'cancel' => ['status_id' => $this->status('closed')],
        };
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $permit->update($fields)));
    }

    public function addDocument(User $actor, ExportPermit $permit, UploadedFile $upload, ?string $kind): void
    {
        if ($permit->institution_id !== $actor->institution_id || ($permit->applicant_id !== $actor->id && $permit->records()->with('record')->get()->contains(fn ($r) => ! $actor->may('update', 'description', $r->record)))) {
            throw new InvalidArgumentException(__('You may not add to that application.'));
        }
        $kept = $this->store->ingest($permit->institution_id, $upload->getRealPath());
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $permit->documents()->create(['original_name' => mb_substr($upload->getClientOriginalName(), 0, 255), 'storage_path' => $kept['storage_path'],
            'mime_type' => $kept['mime_type'], 'size_bytes' => $kept['size_bytes'], 'sha256' => $kept['sha256'], 'kind' => trim((string) $kind) ?: null, 'uploaded_by_id' => $actor->id, 'created_at' => now()])));
    }

    /** The daily run: issued permits whose period has ended expire (PSIS's expireOverdue). */
    public function expireOverdue(): int
    {
        $due = ExportPermit::whereHas('status', fn ($q) => $q->where('iri', Vocab::BASE.'export-permit-status/issued'))->whereNotNull('end_date')
            ->where('end_date', '<', now(config('workspace.timezone'))->toDateString())->get();
        $due->each(fn ($p) => AuditLog::actingAs(null, fn () => $p->update(['status_id' => $this->status('expired')])));

        return $due->count();
    }

    /** SAHRA-APP-YYYY-NNNN, numbered within the institution and year (PSIS's generateApplicationRef). */
    private function reference(string $institutionId): string
    {
        $year = now(config('workspace.timezone'))->format('Y');

        return sprintf('SAHRA-APP-%s-%04d', $year, ExportPermit::where('institution_id', $institutionId)->where('application_ref', 'like', "SAHRA-APP-$year-%")->count() + 1);
    }

    private function status(string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.'export-permit-status/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
