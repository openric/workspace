<?php

// OpenRiC Workspace - running the reading room (roadmap phase 19b, ADR 0017), after Heratio's ResearchAdminController
// (researchers), ResearchBookingsController, ResearchRoomsController, ResearchSeatsController,
// ResearchWalkInsController, ResearchRetrievalQueueController, ResearchEquipmentController, ResearchCustodyController
// and ResearchAdminReferenceController (researcher types): for who may manage the reading room.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\EquipmentBooking;
use App\Models\MaterialRequest;
use App\Models\ReadingRoom;
use App\Models\ReadingRoomBooking;
use App\Models\ReadingRoomEquipment;
use App\Models\ReadingRoomSeat;
use App\Models\Repository;
use App\Models\RequestQueue;
use App\Models\Researcher;
use App\Models\ResearcherType;
use App\Models\RetrievalSchedule;
use App\Models\WalkInVisitor;
use App\Research\Bookings;
use App\Research\Researchers;
use App\Research\ResearchFiles;
use App\Research\Retrieval;
use App\Support\Vocab;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;

class ReadingRoomController extends Controller
{
    public function __construct(private Researchers $researchers, private Bookings $bookings, private Retrieval $retrieval) {}

    // Researchers (Heratio's ResearchAdminController).

    public function researchers(Request $request): View
    {
        $institution = $this->authorise($request);
        $status = $request->query('status', 'pending');
        $all = Researcher::where('institution_id', $institution)->with('status', 'type', 'user');

        return view('reading-room.researchers', ['status' => $status, 'counts' => (clone $all)->get()->countBy(fn ($r) => $r->status->notation),
            'researchers' => (clone $all)->when($status !== 'all', fn ($q) => $q->whereHas('status', fn ($s) => $s->where('iri', Researchers::STATUSES.$status)))
                ->orderBy('last_name')->orderBy('first_name')->get(), 'statuses' => Concept::choices(Researchers::STATUSES)->get()]);
    }

    public function researcher(Request $request, Researcher $researcher): View
    {
        abort_unless($researcher->institution_id === $this->authorise($request), 404);

        return view('reading-room.researcher', ['researcher' => $researcher->load('status', 'type', 'affiliation', 'idType', 'user', 'approvedBy', 'verifications.type', 'verifications.status', 'verifications.verifiedBy', 'verifications.document'),
            'bookings' => $researcher->bookings()->with('room', 'status')->orderByDesc('booking_date')->limit(50)->get(),
            'verificationTypes' => Concept::choices(Vocab::BASE.'verification-type/')->get(), 'verificationStatuses' => Concept::choices(Vocab::BASE.'verification-status/')->get()]);
    }

    public function decide(Request $request, Researcher $researcher, string $step): RedirectResponse
    {
        abort_unless($researcher->institution_id === $this->authorise($request), 404);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000'], 'expires_on' => ['nullable', 'date_format:Y-m-d']]);

        return $this->attempt(fn () => match ($step) {
            'approve' => $this->researchers->approve($researcher, $request->user(), $data['expires_on'] ?? null),
            'reject' => $this->researchers->reject($researcher, $request->user(), (string) ($data['reason'] ?? '')),
            'suspend' => $this->researchers->suspend($researcher, $request->user(), (string) ($data['reason'] ?? '')),
        }, __('Saved.'));
    }

    public function verify(Request $request, Researcher $researcher): RedirectResponse
    {
        abort_unless($researcher->institution_id === $this->authorise($request), 404);
        $data = $request->validate(['type' => ['required', 'string', 'max:64'], 'status' => ['required', 'string', 'max:64'], 'document_type' => ['nullable', 'string', 'max:255'],
            'document_reference' => ['nullable', 'string', 'max:255'], 'expires_on' => ['nullable', 'date_format:Y-m-d'], 'rejection_reason' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'], 'document' => ['nullable', 'file', 'max:'.ResearchFiles::MAX_KILOBYTES]]);

        return $this->attempt(fn () => $this->researchers->verify($researcher, $request->user(), $data, $request->file('document')), __('The check is recorded.'));
    }

    // The day's desk: bookings, walk-ins and seats of a room (Heratio's bookings list, walk-in and seats pages).

    public function desk(Request $request): View
    {
        $institution = $this->authorise($request);
        $rooms = ReadingRoom::where(['institution_id' => $institution])->orderBy('name')->get();
        $room = $rooms->firstWhere('id', $request->query('room')) ?? $rooms->first();
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('day')) ? $request->query('day') : CarbonImmutable::today(config('workspace.timezone'))->toDateString();

        return view('reading-room.desk', ['rooms' => $rooms, 'room' => $room, 'day' => $day, 'bookings' => $room ? $this->bookings->day($room, $day) : collect(),
            'walkIns' => $room ? WalkInVisitor::where(['reading_room_id' => $room->id, 'visit_date' => $day])->orderBy('check_in_time')->get() : collect(),
            'pending' => ReadingRoomBooking::where('institution_id', $institution)->whereHas('status', fn ($q) => $q->where('iri', Bookings::STATUSES.'pending'))->with('room', 'researcher')->orderBy('booking_date')->get(),
            'idTypes' => Concept::choices(Vocab::BASE.'identity-document-type/')->get()]);
    }

    public function bookingStep(Request $request, ReadingRoomBooking $booking, string $step): RedirectResponse
    {
        abort_unless($booking->institution_id === $this->authorise($request), 404);
        $data = $request->validate(['seat_id' => ['nullable', 'uuid'], 'reason' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt(fn () => match ($step) {
            'confirm' => $this->bookings->confirm($request->user(), $booking),
            'check-in' => $this->bookings->checkIn($request->user(), $booking, $data['seat_id'] ?? null),
            'check-out' => $this->bookings->checkOut($request->user(), $booking),
            'no-show' => $this->bookings->noShow($request->user(), $booking),
            'seat' => $this->bookings->assignSeat($request->user(), $booking, $data['seat_id'] ?? null),
        }, __('Saved.'));
    }

    public function walkIn(Request $request, ReadingRoom $room): RedirectResponse
    {
        $data = $request->validate(['first_name' => ['required', 'string', 'max:100'], 'last_name' => ['required', 'string', 'max:100'], 'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'], 'id_type' => ['nullable', 'string', 'max:64'], 'id_number' => ['nullable', 'string', 'max:100'], 'organization' => ['nullable', 'string', 'max:255'],
            'purpose' => ['nullable', 'string', 'max:1000'], 'research_topic' => ['nullable', 'string', 'max:1000'], 'rules_acknowledged' => ['nullable', 'boolean'],
            'photo_permission' => ['nullable', 'boolean'], 'seat_id' => ['nullable', 'uuid'], 'notes' => ['nullable', 'string', 'max:2000']]);

        return $this->attempt(fn () => $this->bookings->walkIn($request->user(), $room, $data), __('The visitor is checked in.'));
    }

    public function walkOut(Request $request, WalkInVisitor $visitor): RedirectResponse
    {
        abort_unless($visitor->room->institution_id === $this->authorise($request), 404);

        return $this->attempt(fn () => $this->bookings->walkOut($request->user(), $visitor), __('The visitor has left.'));
    }

    // Requests for records (Heratio's material requests, retrieval queue and custody).

    public function materialStep(Request $request, MaterialRequest $material, string $step): RedirectResponse
    {
        abort_unless($material->booking->institution_id === $this->authorise($request), 404);
        $data = $request->all();

        return $this->attempt(fn () => match ($step) {
            'status' => $this->retrieval->setStatus($request->user(), $material, (string) ($data['status'] ?? ''), $data['notes'] ?? null),
            'approve' => $this->retrieval->approve($request->user(), $material),
            'handle' => $this->retrieval->handle($request->user(), $material, $data),
            'handoff' => $this->retrieval->handoff($request->user(), $material, $data),
            'verify-return' => $this->retrieval->verifyReturn($request->user(), $material),
        }, __('Saved.'));
    }

    public function queues(Request $request): View
    {
        $institution = $this->authorise($request);
        $queues = RequestQueue::where('institution_id', $institution)->with('queueType')->orderBy('position')->get();
        $queue = $queues->firstWhere('id', $request->query('queue')) ?? $queues->firstWhere('is_default', true) ?? $queues->first();

        return view('reading-room.queues', ['queues' => $queues, 'queue' => $queue, 'requests' => $queue ? $this->retrieval->queue($queue) : collect(),
            'types' => Concept::choices(Vocab::BASE.'request-queue-type/')->get(), 'statuses' => Concept::choices(Retrieval::STATUSES)->get(),
            'rooms' => ReadingRoom::where('institution_id', $institution)->orderBy('name')->get()]);
    }

    public function saveQueue(Request $request, ?RequestQueue $queue = null): RedirectResponse
    {
        $institution = $this->authorise($request);
        abort_if($queue && $queue->institution_id !== $institution, 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'code' => ['nullable', 'string', 'max:50'], 'description' => ['nullable', 'string', 'max:2000'],
            'queue_type' => ['required', 'string', 'max:64'], 'filter_statuses' => ['nullable', 'array'], 'filter_statuses.*' => ['string', 'max:64'],
            'filter_room_id' => ['nullable', 'uuid'], 'filter_priority' => ['nullable', 'in:normal,high,rush'], 'sort_field' => ['nullable', 'in:created_at,retrieval_scheduled_for,sla_due_on'],
            'sort_direction' => ['nullable', 'in:asc,desc'], 'is_default' => ['nullable', 'boolean'], 'is_active' => ['nullable', 'boolean']]);
        $fields = ['name' => $data['name'], 'code' => $data['code'] ?? null, 'description' => $data['description'] ?? null,
            'queue_type_id' => Concept::where('iri', Vocab::BASE.'request-queue-type/'.$data['queue_type'])->value('id') ?? abort(422), 'filter_statuses' => $data['filter_statuses'] ?? ['requested'],
            'filter_room_id' => ReadingRoom::where('institution_id', $institution)->find($data['filter_room_id'] ?? null)?->id, 'filter_priority' => $data['filter_priority'] ?? null,
            'sort_field' => $data['sort_field'] ?? 'created_at', 'sort_direction' => $data['sort_direction'] ?? 'asc', 'is_default' => $request->boolean('is_default'), 'is_active' => $request->boolean('is_active', true)];
        DB::transaction(fn () => AuditLog::actingAs($request->user(), function () use ($queue, $fields, $institution) {
            if ($fields['is_default']) {
                RequestQueue::where('institution_id', $institution)->update(['is_default' => false]);
            }
            $queue ? $queue->update($fields) : RequestQueue::create($fields + ['institution_id' => $institution, 'position' => (int) RequestQueue::where('institution_id', $institution)->max('position') + 1]);
        }));

        return back()->with('status', __('Saved.'));
    }

    /** Call slips to print, for the requests chosen or a whole queue; marked printed. */
    public function slips(Request $request): View
    {
        $institution = $this->authorise($request);
        $ids = (array) $request->query('ids', []);
        $requests = MaterialRequest::whereIn('id', $ids)->whereHas('booking', fn ($q) => $q->where('institution_id', $institution))
            ->with('booking.researcher', 'booking.room', 'record', 'priority')->get();
        $this->retrieval->printSlips($request->user(), $requests);

        return view('reading-room.slips', ['requests' => $requests]);
    }

    // Rooms, seats, retrieval runs and equipment (Heratio's rooms, seats and equipment pages).

    public function rooms(Request $request): View
    {
        $this->authorise($request);

        return view('reading-room.rooms', ['rooms' => ReadingRoom::where('institution_id', $request->user()->institution_id)->withCount('seats', 'equipment')->orderBy('name')->get()]);
    }

    public function room(Request $request, ?ReadingRoom $room = null): View
    {
        $institution = $this->authorise($request);
        abort_if($room && $room->institution_id !== $institution, 404);

        return view('reading-room.room', ['room' => $room?->load('seats.seatType', 'schedules', 'equipment.equipmentType', 'equipment.condition', 'equipment.maintenance.performedBy'),
            'seatTypes' => Concept::choices(Vocab::BASE.'seat-type/')->get(), 'equipmentTypes' => Concept::choices(Vocab::BASE.'equipment-type/')->get(),
            'conditions' => Concept::choices(Vocab::BASE.'equipment-condition/')->get(), 'repositories' => Repository::where('institution_id', $institution)->whereNull('deleted_at')->get()]);
    }

    public function saveRoom(Request $request, ?ReadingRoom $room = null): RedirectResponse
    {
        $institution = $this->authorise($request);
        abort_if($room && $room->institution_id !== $institution, 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'code' => ['nullable', 'string', 'max:50'], 'description' => ['nullable', 'string', 'max:5000'],
            'amenities' => ['nullable', 'string', 'max:2000'], 'capacity' => ['required', 'integer', 'min:0', 'max:1000'], 'has_seat_management' => ['nullable', 'boolean'],
            'walk_ins_allowed' => ['nullable', 'boolean'], 'walk_in_capacity' => ['required', 'integer', 'min:0', 'max:1000'], 'location' => ['nullable', 'string', 'max:255'],
            'operating_hours' => ['nullable', 'string', 'max:2000'], 'rules' => ['nullable', 'string', 'max:20000'], 'advance_booking_days' => ['required', 'integer', 'min:0', 'max:365'],
            'max_booking_hours' => ['required', 'integer', 'min:1', 'max:24'], 'cancellation_hours' => ['required', 'integer', 'min:0', 'max:720'],
            'opening_time' => ['required', 'date_format:H:i'], 'closing_time' => ['required', 'date_format:H:i', 'after:opening_time'], 'days_open' => ['required', 'array', 'min:1'],
            'days_open.*' => ['integer', 'between:1,7'], 'is_active' => ['nullable', 'boolean'], 'repository_id' => ['nullable', 'uuid'], 'floor_plan' => ['nullable', 'image', 'max:'.ResearchFiles::MAX_KILOBYTES]]);
        $fields = array_merge(array_diff_key($data, ['floor_plan' => 1, 'days_open' => 1]), ['days_open' => array_values(array_unique(array_map('intval', $data['days_open']))),
            'has_seat_management' => $request->boolean('has_seat_management'), 'walk_ins_allowed' => $request->boolean('walk_ins_allowed'), 'is_active' => $request->boolean('is_active', true),
            'repository_id' => Repository::where('institution_id', $institution)->find($data['repository_id'] ?? null)?->id]);
        $saved = DB::transaction(fn () => AuditLog::actingAs($request->user(), function () use ($room, $fields, $institution, $request) {
            if ($request->file('floor_plan')) {
                $fields['floor_plan_file_id'] = app(ResearchFiles::class)->keep($request->file('floor_plan'), $institution, $request->user())->id;
            }

            return $room ? tap($room)->update($fields) : ReadingRoom::create($fields + ['institution_id' => $institution]);
        }));

        return redirect()->route('reading-room.rooms.edit', $saved)->with('status', __('Saved.'));
    }

    /** Add seats: one, or a run of them numbered from a prefix (Heratio's bulk seat creation). */
    public function addSeats(Request $request, ReadingRoom $room): RedirectResponse
    {
        abort_unless($room->institution_id === $this->authorise($request), 404);
        $data = $request->validate(['prefix' => ['nullable', 'string', 'max:20'], 'count' => ['required', 'integer', 'min:1', 'max:200'], 'seat_type' => ['required', 'string', 'max:64'],
            'zone' => ['nullable', 'string', 'max:100'], 'has_power' => ['nullable', 'boolean'], 'has_lamp' => ['nullable', 'boolean'], 'has_computer' => ['nullable', 'boolean'], 'has_magnifier' => ['nullable', 'boolean']]);
        $type = Concept::where('iri', Vocab::BASE.'seat-type/'.$data['seat_type'])->value('id') ?? abort(422);
        DB::transaction(fn () => AuditLog::actingAs($request->user(), function () use ($room, $data, $type, $request) {
            $next = (int) ReadingRoomSeat::where('reading_room_id', $room->id)->count();
            for ($i = 1; $i <= $data['count']; $i++) {
                $number = ($data['prefix'] ?? '').($next + $i);
                ReadingRoomSeat::firstOrCreate(['reading_room_id' => $room->id, 'seat_number' => $number], ['seat_label' => trim(($data['zone'] ?? '').' '.$number), 'seat_type_id' => $type,
                    'zone' => $data['zone'] ?? null, 'has_power' => $request->boolean('has_power'), 'has_lamp' => $request->boolean('has_lamp'), 'has_computer' => $request->boolean('has_computer'),
                    'has_magnifier' => $request->boolean('has_magnifier'), 'position' => $next + $i]);
            }
        }));

        return back()->with('status', __('The seats are added.'));
    }

    public function toggleSeat(Request $request, ReadingRoomSeat $seat): RedirectResponse
    {
        abort_unless($seat->room->institution_id === $this->authorise($request), 404);
        DB::transaction(fn () => AuditLog::actingAs($request->user(), fn () => $seat->update(['is_active' => ! $seat->is_active])));

        return back()->with('status', __('Saved.'));
    }

    public function saveSchedule(Request $request, ReadingRoom $room): RedirectResponse
    {
        abort_unless($room->institution_id === $this->authorise($request), 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'day_of_week' => ['nullable', 'integer', 'between:1,7'], 'retrieval_time' => ['required', 'date_format:H:i'],
            'cutoff_minutes_before' => ['required', 'integer', 'min:0', 'max:1440'], 'max_items_per_run' => ['nullable', 'integer', 'min:1'], 'storage_location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000']]);
        DB::transaction(fn () => AuditLog::actingAs($request->user(), fn () => RetrievalSchedule::create($data + ['reading_room_id' => $room->id])));

        return back()->with('status', __('The retrieval run is added.'));
    }

    public function removeSchedule(Request $request, RetrievalSchedule $schedule): RedirectResponse
    {
        abort_unless($schedule->room->institution_id === $this->authorise($request), 404);
        DB::transaction(fn () => AuditLog::actingAs($request->user(), fn () => $schedule->delete()));

        return back()->with('status', __('The retrieval run is removed.'));
    }

    public function saveEquipment(Request $request, ReadingRoom $room, ?ReadingRoomEquipment $equipment = null): RedirectResponse
    {
        abort_unless($room->institution_id === $this->authorise($request), 404);
        abort_if($equipment && $equipment->reading_room_id !== $room->id, 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'code' => ['nullable', 'string', 'max:50'], 'equipment_type' => ['required', 'string', 'max:64'],
            'brand' => ['nullable', 'string', 'max:255'], 'model' => ['nullable', 'string', 'max:255'], 'serial_number' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000'],
            'location' => ['nullable', 'string', 'max:255'], 'requires_training' => ['nullable', 'boolean'], 'max_booking_hours' => ['required', 'integer', 'min:1', 'max:24'],
            'booking_increment_minutes' => ['required', 'integer', 'min:5', 'max:240'], 'condition' => ['required', 'string', 'max:64'], 'next_maintenance_on' => ['nullable', 'date_format:Y-m-d'],
            'is_available' => ['nullable', 'boolean'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $fields = array_diff_key($data, ['equipment_type' => 1, 'condition' => 1]) + [
            'equipment_type_id' => Concept::where('iri', Vocab::BASE.'equipment-type/'.$data['equipment_type'])->value('id') ?? abort(422),
            'condition_id' => Concept::where('iri', Vocab::BASE.'equipment-condition/'.$data['condition'])->value('id') ?? abort(422),
            'requires_training' => $request->boolean('requires_training'), 'is_available' => $request->boolean('is_available', true)];
        DB::transaction(fn () => AuditLog::actingAs($request->user(), fn () => $equipment ? $equipment->update($fields) : ReadingRoomEquipment::create($fields + ['reading_room_id' => $room->id])));

        return back()->with('status', __('Saved.'));
    }

    public function maintain(Request $request, ReadingRoomEquipment $equipment): RedirectResponse
    {
        abort_unless($equipment->room->institution_id === $this->authorise($request), 404);
        $data = $request->validate(['description' => ['required', 'string', 'max:5000'], 'condition' => ['nullable', 'string', 'max:64'], 'next_maintenance_on' => ['nullable', 'date_format:Y-m-d']]);

        return $this->attempt(fn () => $this->bookings->maintain($request->user(), $equipment, $data), __('The maintenance is recorded.'));
    }

    public function equipmentStep(Request $request, EquipmentBooking $booking, string $step): RedirectResponse
    {
        $data = $request->validate(['condition' => ['nullable', 'in:excellent,good,fair,damaged'], 'return_notes' => ['nullable', 'string', 'max:2000']]);

        return $this->attempt(fn () => $this->bookings->equipmentStep($request->user(), $booking, $step, $data), __('Saved.'));
    }

    // Researcher types (Heratio's ResearchAdminReferenceController).

    public function types(Request $request): View
    {
        $this->authorise($request);

        return view('reading-room.types', ['types' => ResearcherType::where('institution_id', $request->user()->institution_id)->withCount('researchers')->orderBy('position')->get()]);
    }

    public function saveType(Request $request, ?ResearcherType $type = null): RedirectResponse
    {
        $institution = $this->authorise($request);
        abort_if($type && $type->institution_id !== $institution, 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'code' => ['nullable', 'string', 'max:50'], 'description' => ['nullable', 'string', 'max:2000'],
            'max_booking_days_advance' => ['nullable', 'integer', 'min:0', 'max:365'], 'max_booking_hours_per_day' => ['nullable', 'integer', 'min:1', 'max:24'],
            'max_materials_per_booking' => ['nullable', 'integer', 'min:1', 'max:500'], 'expiry_months' => ['required', 'integer', 'min:1', 'max:120'], 'priority_level' => ['nullable', 'integer', 'min:0', 'max:100']]);
        $flags = collect(['can_remote_access', 'can_request_reproductions', 'can_export_data', 'requires_id_verification', 'auto_approve', 'is_active'])->mapWithKeys(fn ($f) => [$f => $request->boolean($f)])->all();
        DB::transaction(fn () => AuditLog::actingAs($request->user(), fn () => $type ? $type->update($data + $flags)
            : ResearcherType::create($data + $flags + ['institution_id' => $institution, 'priority_level' => $data['priority_level'] ?? 0, 'position' => (int) ResearcherType::where('institution_id', $institution)->max('position') + 1])));

        return back()->with('status', __('Saved.'));
    }

    private function authorise(Request $request): string
    {
        abort_unless($request->user()->hasPermission(Vocab::permission('manage-reading-room')), 403);

        return $request->user()->institution_id;
    }

    private function attempt(callable $do, string $done): RedirectResponse
    {
        try {
            $do();
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['research' => $e->getMessage()]);
        } catch (\UnhandledMatchError) {
            abort(404);
        }

        return back()->with('status', $done);
    }
}
