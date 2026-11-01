<?php

// OpenRiC Workspace - records asked for, fetched, used and put back (roadmap phase 19b, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's material requests and its ResearchRetrievalQueueController (queues), ResearchCustodyService and
// ResearchCustodyController (checkout, return, verification, the chain of custody), research_request_status_history
// and research_request_correspondence. A request notes where the record is kept, from its storage, and the next
// retrieval run before the visit; it goes requested, retrieved, delivered, in use, returned (or unavailable, with
// the reason), each step logged and moving where it is; one that needs a curator's approval is not fetched before
// it; staff print a call slip; each hand-over is a step of its custody, with its condition; staff and the
// researcher write to each other about it, and staff keep notes the researcher does not see.

namespace App\Research;

use App\Audit\AuditLog;
use App\Exchange\ExportView;
use App\Models\Concept;
use App\Models\CustodyHandoff;
use App\Models\Institution;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestMessage;
use App\Models\MaterialRequestStatusChange;
use App\Models\ReadingRoomBooking;
use App\Models\RecordResource;
use App\Models\RequestQueue;
use App\Models\RetrievalSchedule;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Retrieval
{
    public const STATUSES = Vocab::BASE.'material-request-status/';

    /** From each status, the ones it may go to, and where the record then is. */
    private const NEXT = ['requested' => ['retrieved', 'unavailable'], 'retrieved' => ['delivered', 'returned', 'unavailable'], 'delivered' => ['in-use', 'returned'],
        'in-use' => ['delivered', 'returned'], 'returned' => [], 'unavailable' => ['requested']];

    private const WHERE = ['requested' => 'storage', 'retrieved' => 'transit', 'delivered' => 'reading-room', 'in-use' => 'reading-room', 'returned' => 'storage', 'unavailable' => null];

    public function __construct(private ResearchFiles $files) {}

    /** Ask for a record for a visit: where it is kept, and the run that fetches it in time. */
    public function request(ReadingRoomBooking $booking, RecordResource $unit, User $actor, array $data = []): MaterialRequest
    {
        if (MaterialRequest::where(['booking_id' => $booking->id, 'record_resource_id' => $unit->id])->exists()) {
            throw new InvalidArgumentException(__('That record is asked for already.'));
        }
        $kept = (new ExportView($actor, false, null, Institution::find($unit->institution_id)))->storage($unit)[0] ?? null;
        $request = MaterialRequest::create(['booking_id' => $booking->id, 'record_resource_id' => $unit->id, 'quantity' => max(1, (int) ($data['quantity'] ?? 1)),
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null, 'request_type_id' => $this->concept('material-request-type', $data['request_type'] ?? 'reading-room'),
            'priority_id' => $this->concept('request-priority', $data['priority'] ?? 'normal'), 'location_code' => $kept['location'] ?? null, 'box_number' => $kept['name'] ?? null,
            'location_current_id' => $this->concept('material-location', 'storage'), 'retrieval_scheduled_for' => $this->nextRun($booking),
            'status_id' => $this->concept('material-request-status', 'requested'), 'sla_due_on' => $booking->booking_date->toDateString()]);
        $this->log($request, null, 'requested', $actor, null);
        if ($booking->researcher?->user_id === $actor->id) {
            app(Diary::class)->auto($actor, 'auto-material', __('Asked for ":title" to be brought', ['title' => EntityKinds::label($unit)]), $request);
        }

        return $request;
    }

    /**
     * The last retrieval run that still fetches it before the visit starts: the room's runs of that day (or every day),
     * whose cut-off has not passed. Null when the room has no runs.
     */
    public function nextRun(ReadingRoomBooking $booking): ?CarbonImmutable
    {
        $tz = config('workspace.timezone');
        $start = CarbonImmutable::parse($booking->booking_date->toDateString().' '.$booking->start_time, $tz);
        $runs = RetrievalSchedule::where(['reading_room_id' => $booking->reading_room_id, 'is_active' => true])
            ->where(fn ($q) => $q->whereNull('day_of_week')->orWhere('day_of_week', $start->dayOfWeekIso))->orderBy('retrieval_time')->get()
            ->map(fn ($r) => ['at' => CarbonImmutable::parse($start->toDateString().' '.$r->retrieval_time, $tz), 'cutoff' => $r->cutoff_minutes_before])
            ->filter(fn ($r) => $r['at']->lessThanOrEqualTo($start) && now()->lessThan($r['at']->subMinutes($r['cutoff'])));

        return $runs->last()['at'] ?? null;
    }

    /** Move a request on, logging it and where the record now is. */
    public function setStatus(User $actor, MaterialRequest $request, string $status, ?string $notes = null): void
    {
        $from = $request->status->notation;
        if (! in_array($status, self::NEXT[$from] ?? [], true)) {
            throw new InvalidArgumentException(__('A request that is :from cannot become :to.', ['from' => __($from), 'to' => __($status)]));
        }
        if ($status === 'retrieved' && $request->curatorial_approval_required && $request->curatorial_approved_at === null) {
            throw new InvalidArgumentException(__('A curator must approve it before it is fetched.'));
        }
        if ($status === 'unavailable' && trim((string) $notes) === '') {
            throw new InvalidArgumentException(__('Say why it is unavailable.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $request, $status, $from, $notes) {
            $request->update(array_filter([
                'status_id' => $this->concept('material-request-status', $status),
                'location_current_id' => self::WHERE[$status] ? $this->concept('material-location', self::WHERE[$status]) : null,
                'retrieved_by_id' => $status === 'retrieved' ? $actor->id : null, 'retrieved_at' => $status === 'retrieved' ? now() : null,
                'returned_at' => $status === 'returned' ? now() : null, 'unavailable_reason' => $status === 'unavailable' ? trim((string) $notes) : null,
            ], fn ($v) => $v !== null));
            $this->log($request, $from, $status, $actor, $notes);
        }));
    }

    /** A curator's approval, for a request that needs one. */
    public function approve(User $actor, MaterialRequest $request): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $request->update(['curatorial_approved_by_id' => $actor->id, 'curatorial_approved_at' => now()])));
        $this->log($request, $request->status->notation, $request->status->notation, $actor, __('Approved by a curator.'));
    }

    /** Staff change how it is handled: priority, the queue, who fetches it, whether a curator must approve, where it is kept. */
    public function handle(User $actor, MaterialRequest $request, array $data): void
    {
        $queue = ($data['queue_id'] ?? '') !== '' ? RequestQueue::where(['id' => $data['queue_id'], 'institution_id' => $actor->institution_id])->first() : null;
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $request->update([
            'priority_id' => $this->concept('request-priority', $data['priority'] ?? $request->priority->notation), 'queue_id' => $queue?->id,
            'assigned_to_id' => ($data['assigned_to_id'] ?? '') !== '' ? User::where(['id' => $data['assigned_to_id'], 'institution_id' => $actor->institution_id])->value('id') : null,
            'curatorial_approval_required' => (bool) ($data['curatorial_approval_required'] ?? false), 'handling_instructions' => trim((string) ($data['handling_instructions'] ?? '')) ?: null,
            'location_code' => trim((string) ($data['location_code'] ?? '')) ?: null, 'shelf_location' => trim((string) ($data['shelf_location'] ?? '')) ?: null,
            'box_number' => trim((string) ($data['box_number'] ?? '')) ?: null, 'folder_number' => trim((string) ($data['folder_number'] ?? '')) ?: null,
            'sla_due_on' => ($data['sla_due_on'] ?? '') ?: $request->sla_due_on?->toDateString()])));
    }

    /** The call slips of these requests, marked printed. */
    public function printSlips(User $actor, Collection $requests): void
    {
        DB::transaction(fn () => $requests->each(fn ($r) => $r->update(['call_slip_printed_at' => now(), 'call_slip_printed_by_id' => $actor->id])));
    }

    /**
     * A hand-over: checked out to the researcher (in use), checked in from them (delivered), transferred, returned to
     * storage (returned), or its condition checked; with its condition (Heratio's recordCheckout and recordReturn).
     */
    public function handoff(User $actor, MaterialRequest $request, array $data): CustodyHandoff
    {
        $type = $data['handoff_type'] ?? '';
        $status = ['checkout' => 'in-use', 'checkin' => 'delivered', 'return-to-storage' => 'returned'][$type] ?? null;
        $researcherUser = $request->booking->researcher->user;

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $request, $data, $type, $status, $researcherUser) {
            if ($status !== null && $status !== $request->status->notation) {
                $this->setStatus($actor, $request, $status, trim((string) ($data['notes'] ?? '')) ?: null);
            }
            $condition = ($data['condition'] ?? '') !== '' ? $this->concept('container-condition', $data['condition']) : null;
            if ($type === 'checkout') {
                $request->update(['checkout_confirmed_at' => now(), 'checkout_confirmed_by_id' => $actor->id]);
            }
            if ($type === 'return-to-storage') {
                $request->update(['return_condition_id' => ($data['return_condition'] ?? '') !== '' ? $this->concept('return-condition', $data['return_condition']) : null,
                    'condition_notes' => trim((string) ($data['condition_notes'] ?? '')) ?: $request->condition_notes]);
            }

            return CustodyHandoff::create(['material_request_id' => $request->id, 'handoff_type_id' => $this->concept('custody-handoff-type', $type),
                'from_handler_id' => $type === 'checkin' ? $researcherUser?->id : $actor->id, 'to_handler_id' => $type === 'checkout' ? $researcherUser?->id : $actor->id,
                'from_location' => trim((string) ($data['from_location'] ?? '')) ?: null, 'to_location' => trim((string) ($data['to_location'] ?? '')) ?: null,
                'condition_id' => $condition, 'condition_notes' => trim((string) ($data['condition_notes'] ?? '')) ?: null, 'signature_confirmed' => (bool) ($data['signature_confirmed'] ?? false),
                'confirmed_at' => ! empty($data['signature_confirmed']) ? now() : null, 'confirmed_by_id' => ! empty($data['signature_confirmed']) ? $actor->id : null,
                'barcode_scanned' => trim((string) ($data['barcode_scanned'] ?? '')) ?: null, 'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                'created_at' => now(), 'created_by_id' => $actor->id]);
        }));
    }

    /** A return checked by a second person (Heratio's returnVerify). */
    public function verifyReturn(User $actor, MaterialRequest $request): void
    {
        if ($request->status->notation !== 'returned') {
            throw new InvalidArgumentException(__('Only a returned item can be checked.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $request->update(['return_verified_by_id' => $actor->id, 'return_verified_at' => now()])));
    }

    /** A message: the researcher to staff, staff to the researcher, or a staff note the researcher does not see. */
    public function write(User $actor, MaterialRequest $request, array $data, bool $fromStaff, ?UploadedFile $attachment = null): MaterialRequestMessage
    {
        if (trim((string) ($data['body'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Write the message.'));
        }

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => MaterialRequestMessage::create(['material_request_id' => $request->id, 'from_staff' => $fromStaff,
            'sender_id' => $actor->id, 'subject' => trim((string) ($data['subject'] ?? '')) ?: null, 'body' => trim($data['body']),
            'is_internal' => $fromStaff && ! empty($data['is_internal']), 'created_at' => now(),
            'attachment_file_id' => $attachment ? $this->files->keep($attachment, $request->booking->institution_id, $actor)->id : null])));
    }

    /** A queue's requests: its statuses, room and priority, in its order (Heratio's retrievalQueue). */
    public function queue(RequestQueue $queue): Collection
    {
        $statuses = (array) ($queue->filter_statuses ?: ['requested']);

        return MaterialRequest::whereHas('status', fn ($q) => $q->whereIn('iri', array_map(fn ($s) => self::STATUSES.$s, $statuses)))
            ->whereHas('booking', fn ($q) => $q->where('institution_id', $queue->institution_id)->when($queue->filter_room_id, fn ($b) => $b->where('reading_room_id', $queue->filter_room_id)))
            ->when($queue->filter_priority, fn ($q) => $q->whereHas('priority', fn ($p) => $p->where('iri', Vocab::BASE.'request-priority/'.$queue->filter_priority)))
            ->with('booking.researcher', 'booking.room', 'record', 'status', 'priority', 'assignedTo')
            ->orderBy(in_array($queue->sort_field, ['created_at', 'retrieval_scheduled_for', 'sla_due_on'], true) ? $queue->sort_field : 'created_at', $queue->sort_direction === 'desc' ? 'desc' : 'asc')->get();
    }

    private function log(MaterialRequest $request, ?string $from, string $to, User $actor, ?string $notes): void
    {
        MaterialRequestStatusChange::create(['material_request_id' => $request->id, 'old_status' => $from, 'new_status' => $to, 'changed_by_id' => $actor->id,
            'notes' => $notes, 'created_at' => now()]);
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
