<?php

// OpenRiC Workspace - the disposal of a record (roadmap phase 13b, ADR 0015), after Heratio's ahg-records-manage
// DisposalWorkflowService (initiateDisposal, recommend, approve, clearLegal, reject, cancel, getStats),
// DisposalExecutionService (executeDestroy, executeTransfer, executeRetain, verifyDestruction) and
// DestructionCertificateService (the certificate and its number, DC-YYYY-NNNNN).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A disposal is initiated, may be recommended, is approved by someone other than who initiated it, cleared of legal
// holds by someone other than who approved it, and carried out by someone other than either: Heratio's separation of
// duties (#1374), kept here also by the database. Only who may administer the institution destroys, as Heratio keeps
// destruction to administrators. Destroying keeps the description as evidence (Dr Johan Pieterse, 31 October 2026):
// the record's files are erased, its destruction is a step of its custody history (a rico:Activity affecting it), and
// it is deleted as any record is, so it leaves every list and its IRI answers 410 with the reason (ADR 0013). The
// certificate keeps its title, reference code and IRI and each file's name and checksum. Files are kept once by their
// checksum, so a file another record still uses is not erased. A record with units inside it is not destroyed until
// they are disposed of. The Workspace has no legal holds of its own, so clearing is the clearer's word that none applies.

namespace App\Retention;

use App\Audit\AuditLog;
use App\Description\EntityDeletion;
use App\Description\ProvenanceService;
use App\Files\FileStore;
use App\Models\DestructionCertificate;
use App\Models\DigitalFile;
use App\Models\DisposalAction;
use App\Models\DisposalClass;
use App\Models\Instantiation;
use App\Models\RecordDisposalClass;
use App\Models\RecordResource;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DisposalService
{
    /** Start a disposal of a record: destroy, transfer, retain or review. */
    public function initiate(RecordResource $unit, string $action, ?string $reason, User $actor, ?DisposalClass $class = null): DisposalAction
    {
        $unit->deleted_at === null || throw new InvalidArgumentException(__('The record is gone.'));
        $open = DisposalAction::where('record_resource_id', $unit->id)->whereHas('status', fn ($q) => $q->whereIn('iri', $this->statuses(['pending', 'recommended', 'approved', 'cleared'])))->exists();
        ! $open || throw new InvalidArgumentException(__('A disposal of this record is already under way.'));
        $class ??= RecordDisposalClass::where('record_resource_id', $unit->id)->first()?->disposalClass;

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => DisposalAction::create(['institution_id' => $unit->institution_id, 'record_resource_id' => $unit->id,
            'disposal_class_id' => $class?->id, 'action_id' => RetentionService::concept('disposal-action-type', $action)->id, 'status_id' => $this->status('pending')->id,
            'reason' => $reason ? trim($reason) : null, 'initiated_by_id' => $actor->id, 'initiated_at' => now()])));
    }

    public function recommend(DisposalAction $disposal, ?string $comment, User $actor): void
    {
        $this->expect($disposal, ['pending']);
        $this->step($disposal, $actor, ['recommended_by_id' => $actor->id, 'recommended_at' => now(), 'status_id' => $this->status('recommended')->id], $comment);
    }

    /** Approve: never by who initiated it. */
    public function approve(DisposalAction $disposal, ?string $comment, User $actor): void
    {
        $this->expect($disposal, ['pending', 'recommended']);
        $actor->id !== $disposal->initiated_by_id || throw new InvalidArgumentException(__('Someone other than who initiated it approves it.'));
        $this->step($disposal, $actor, ['approved_by_id' => $actor->id, 'approved_at' => now(), 'status_id' => $this->status('approved')->id], $comment);
    }

    /** Clear it of legal holds: never by who approved it. */
    public function clear(DisposalAction $disposal, ?string $comment, User $actor): void
    {
        $this->expect($disposal, ['approved']);
        $actor->id !== $disposal->approved_by_id || throw new InvalidArgumentException(__('Someone other than who approved it clears it.'));
        $this->step($disposal, $actor, ['cleared_by_id' => $actor->id, 'cleared_at' => now(), 'status_id' => $this->status('cleared')->id], $comment);
    }

    public function reject(DisposalAction $disposal, string $reason, User $actor): void
    {
        $this->expect($disposal, ['pending', 'recommended', 'approved', 'cleared']);
        $this->step($disposal, $actor, ['status_id' => $this->status('rejected')->id], __('Rejected: :reason', ['reason' => trim($reason)]));
    }

    public function cancel(DisposalAction $disposal, string $reason, User $actor): void
    {
        $this->expect($disposal, ['pending', 'recommended']);
        $this->step($disposal, $actor, ['status_id' => $this->status('cancelled')->id], __('Cancelled: :reason', ['reason' => trim($reason)]));
    }

    /**
     * Carry it out: by someone other than who approved or cleared it, once cleared. Destroying needs the right to
     * administer the institution.
     */
    public function execute(DisposalAction $disposal, User $actor, array $details = []): DisposalAction
    {
        $this->expect($disposal, ['cleared']);
        in_array($actor->id, [$disposal->approved_by_id, $disposal->cleared_by_id], true) && throw new InvalidArgumentException(__('Someone other than who approved or cleared it carries it out.'));

        return match ($disposal->action->notation) {
            'destroy' => $this->destroy($disposal, $actor, (string) ($details['method'] ?? ''), $details['witness'] ?? null),
            'transfer' => $this->transfer($disposal, $actor, trim((string) ($details['destination'] ?? '')) ?: throw new InvalidArgumentException(__('Name where it is transferred to.'))),
            default => $this->retain($disposal, $actor, $details['reason'] ?? null),
        };
    }

    /**
     * Heratio's verifyDestruction: the certificate, its number and checksum, the files gone (or kept for another record
     * that uses them), the record deleted and its destruction in the audit log.
     */
    public function verify(DisposalAction $disposal): array
    {
        $certificate = $disposal->certificate;
        $checks = [];
        $add = function (string $check, bool $passed, ?string $detail = null) use (&$checks) {
            $checks[] = ['check' => $check, 'passed' => $passed, 'detail' => $detail];
        };
        $add('A certificate of destruction exists', $certificate !== null);
        $add('Its number is valid', $certificate !== null && preg_match('/^DC-\d{4}-\d{5}$/', $certificate->number) === 1, $certificate?->number);
        $add('The content checksum is kept', $certificate !== null && ($certificate->content_hash !== null || $certificate->files === []));
        $store = app(FileStore::class);
        $left = collect($certificate?->files ?? [])->filter(fn ($f) => ! ($f['shared'] ?? false) && is_file($store->path($f['storage_path'])))->pluck('name')->all();
        $add('Its files are erased', $left === [], $left === [] ? null : implode(', ', $left));
        $add('The record is deleted', $disposal->record()->first()?->deleted_at !== null);
        $add('The destruction is in the audit log', DB::table('audit_log')->where(['subject_table' => 'disposal_actions', 'subject_id' => $disposal->id])->where('after', 'like', '%"executed_at"%')->exists());
        $disposal->update(['verification' => $checks, 'verified_at' => now()]);

        return $checks;
    }

    /** @return array<string, int> disposals by status, as Heratio's getStats */
    public function counts(string $institutionId): array
    {
        return DisposalAction::where('institution_id', $institutionId)->with('status')->get()->countBy(fn ($d) => $d->status->notation)->all();
    }

    private function destroy(DisposalAction $disposal, User $actor, string $method, ?string $witness): DisposalAction
    {
        $actor->hasPermission(Vocab::permission('manage-institution')) || throw new InvalidArgumentException(__('Only who administers the institution destroys records.'));
        $unit = $disposal->record;
        ! RecordResource::where('parent_id', $unit->id)->whereNull('deleted_at')->exists() || throw new InvalidArgumentException(__('Units of description are inside it: dispose of them first.'));
        $institution = $unit->institution_id;
        $instantiations = Instantiation::where('record_resource_id', $unit->id)->whereNull('deleted_at')->with('files.use')->get();
        $files = $instantiations->flatMap(fn ($i) => $i->files)->map(fn (DigitalFile $f) => ['name' => $f->original_name, 'use' => $f->use->notation, 'mime_type' => $f->mime_type,
            'size_bytes' => $f->size_bytes, 'sha256' => $f->sha256, 'storage_path' => $f->storage_path, 'instantiation' => $f->instantiation_id])->values()->all();
        $class = $disposal->disposalClass;
        $authority = collect([$class?->schedule->authority, $class?->schedule ? $class->schedule->reference.' v'.$class->schedule->version : null])->filter()->join(', ') ?: null;

        $certificate = DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($disposal, $unit, $actor, $method, $witness, $files, $instantiations, $class, $authority, $institution) {
            $certificate = DestructionCertificate::create(['institution_id' => $institution, 'number' => $this->number(), 'record_resource_id' => $unit->id, 'record_iri' => $unit->iri,
                'record_title' => EntityKinds::label($unit), 'reference_code' => $unit->referenceCode(), 'authority' => $authority,
                'disposal_class' => $class ? $class->reference.' '.$class->title : null, 'method' => trim($method) ?: 'Secure erasure from the file store', 'witness' => $witness ? trim($witness) : null,
                'files' => $files, 'content_hash' => $files === [] ? null : hash('sha256', implode('', array_column($files, 'sha256'))),
                'approved_by' => (string) $disposal->approvedBy?->name, 'executed_by' => $actor->name, 'destroyed_at' => now()]);
            // Its destruction, a rico:Activity affecting it, in its custody history.
            app(ProvenanceService::class)->saveEntry($unit, null, ['owner_name' => $unit->repository?->described('authorised-name', ['en', ...Vocab::languages()]) ?? EntityKinds::label($unit),
                'transfer_type' => 'destruction', 'start_date' => now(config('workspace.timezone'))->toDateString(),
                'transfer_details' => __('Destroyed under :authority; certificate :number.', ['authority' => $authority ?? __('a disposal'), 'number' => $certificate->number]),
                'notes' => $disposal->reason], $actor);
            $instantiations->each(fn (Instantiation $i) => $i->forceFill(['deleted_at' => now()])->save());
            app(EntityDeletion::class)->delete($unit->fresh(), $actor, __('Destroyed under :authority; certificate :number.', ['authority' => $authority ?? __('a disposal'), 'number' => $certificate->number]));
            $disposal->update(['status_id' => $this->status('executed')->id, 'executed_by_id' => $actor->id, 'executed_at' => now(), 'certificate_id' => $certificate->id]);

            return $certificate;
        }));

        // Then the files, once the record of their destruction is kept: each unless another record still uses it.
        $store = app(FileStore::class);
        $kept = [];
        foreach ($certificate->files as $file) {
            $shared = DigitalFile::where(['institution_id' => $institution, 'storage_path' => $file['storage_path']])
                ->whereHas('instantiation', fn ($q) => $q->whereNull('deleted_at'))->exists();
            if (! $shared && is_file($store->path($file['storage_path']))) {
                @unlink($store->path($file['storage_path']));
            }
            $kept[] = [...$file, 'shared' => $shared];
        }
        $certificate->update(['files' => $kept]);

        return $disposal->fresh();
    }

    /** A transfer: the record stays described here, its transfer a step of its custody history. */
    private function transfer(DisposalAction $disposal, User $actor, string $destination): DisposalAction
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($disposal, $actor, $destination) {
            app(ProvenanceService::class)->saveEntry($disposal->record, null, ['owner_name' => $destination, 'transfer_type' => 'transfer',
                'start_date' => now(config('workspace.timezone'))->toDateString(), 'transfer_details' => __('Transferred under a disposal.'), 'notes' => $disposal->reason], $actor);
            $disposal->update(['status_id' => $this->status('executed')->id, 'executed_by_id' => $actor->id, 'executed_at' => now(), 'transfer_destination' => $destination]);
        }));

        return $disposal->fresh();
    }

    /** Retain permanently: its retention no longer ends. */
    private function retain(DisposalAction $disposal, User $actor, ?string $reason): DisposalAction
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($disposal, $actor, $reason) {
            RecordDisposalClass::where('record_resource_id', $disposal->record_resource_id)->get()
                ->each->update(['disposal_due_on' => null, 'override_due_on' => null, 'override_reason' => trim((string) $reason) ?: __('Retained permanently.')]);
            $disposal->update(['status_id' => $this->status('retained')->id, 'executed_by_id' => $actor->id, 'executed_at' => now(),
                'notes' => trim(($disposal->notes ?? '')."\n".__('Retained: :reason', ['reason' => trim((string) $reason) ?: '-']))]);
        }));

        return $disposal->fresh();
    }

    private function step(DisposalAction $disposal, User $actor, array $fields, ?string $comment): void
    {
        if ($comment !== null && trim($comment) !== '') {
            $fields['notes'] = trim(($disposal->notes ?? '')."\n".trim($comment));
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $disposal->update($fields)));
    }

    private function expect(DisposalAction $disposal, array $statuses): void
    {
        in_array($disposal->status->notation, $statuses, true) || throw new InvalidArgumentException(__('This disposal is :status.', ['status' => mb_strtolower((string) $disposal->status->label(Vocab::languages()))]));
    }

    private function status(string $notation)
    {
        return RetentionService::concept('disposal-status', $notation);
    }

    private function statuses(array $notations): array
    {
        return array_map(fn ($s) => Vocab::BASE.'disposal-status/'.$s, $notations);
    }

    /** The next certificate number, DC-YYYY-NNNNN, as Heratio numbers them. */
    private function number(): string
    {
        $prefix = 'DC-'.now(config('workspace.timezone'))->format('Y').'-';
        $last = DestructionCertificate::where('number', 'like', $prefix.'%')->lockForUpdate()->orderByDesc('number')->value('number');

        return $prefix.str_pad((string) ($last ? (int) substr($last, strlen($prefix)) + 1 : 1), 5, '0', STR_PAD_LEFT);
    }
}
