<?php

// OpenRiC Workspace - booking the reading room, its seats and its equipment, and walk-ins (roadmap phase 19b,
// ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ResearchService (createBooking, addMaterialRequest, confirmBooking, cancelBooking, checkIn,
// checkOut), ResearchBookingsController, ResearchSeatsController, ResearchWalkInsController and
// ResearchEquipmentController. Heratio keeps each room's hours, days, advance-booking and booking-hour limits,
// capacity and cancellation notice, and each researcher type's limits, but checks none of them when a booking is
// made; the Workspace does: an approved researcher books a day the room is open, within its hours and its notice,
// for no longer than the room and their type allow, while there is room, with no more records than their type
// allows, having accepted the room's rules. A cancellation by the researcher keeps the room's notice; staff may
// cancel at any time. Checking in gives a seat when the room manages them; checking out releases it and returns
// what was in use, as Heratio's checkOut does.

namespace App\Research;

use App\Audit\AuditLog;
use App\Mail\Outbox;
use App\Mail\ResearchMail;
use App\Models\Concept;
use App\Models\EquipmentBooking;
use App\Models\EquipmentMaintenance;
use App\Models\MaterialRequest;
use App\Models\ReadingRoom;
use App\Models\ReadingRoomBooking;
use App\Models\ReadingRoomEquipment;
use App\Models\ReadingRoomSeat;
use App\Models\RecordResource;
use App\Models\Researcher;
use App\Models\SeatAssignment;
use App\Models\User;
use App\Models\WalkInVisitor;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Bookings
{
    public const STATUSES = Vocab::BASE.'booking-status/';

    /** Bookings that hold a place in the room. */
    private const HOLDING = ['pending', 'confirmed'];

    public function __construct(private Researchers $researchers, private Retrieval $retrieval, private Outbox $outbox) {}

    /**
     * Book a visit, with the records to have ready.
     *
     * @param  list<string>  $records  record ids
     */
    public function book(User $user, array $data, array $records = []): ReadingRoomBooking
    {
        $researcher = $this->researchers->of($user);
        if (! $this->researchers->active($researcher)) {
            throw new InvalidArgumentException(__('Only an approved researcher may book the reading room.'));
        }
        $room = ReadingRoom::where(['id' => $data['reading_room_id'] ?? null, 'institution_id' => $user->institution_id, 'is_active' => true])->first()
            ?? throw new InvalidArgumentException(__('Choose a reading room.'));
        $tz = config('workspace.timezone');
        $day = CarbonImmutable::parse((string) ($data['booking_date'] ?? ''), $tz)->startOfDay();
        [$start, $end] = [$this->time($data['start_time'] ?? null), $this->time($data['end_time'] ?? null)];
        $type = $researcher->type;
        $advance = min($room->advance_booking_days, $type?->max_booking_days_advance ?? PHP_INT_MAX);
        $hours = min($room->max_booking_hours, $type?->max_booking_hours_per_day ?? PHP_INT_MAX);

        match (true) {
            $day->lessThan(CarbonImmutable::today($tz)) => throw new InvalidArgumentException(__('Choose a day from today.')),
            $day->greaterThan(CarbonImmutable::today($tz)->addDays($advance)) => throw new InvalidArgumentException(__('The reading room may be booked at most :days days ahead.', ['days' => $advance])),
            ! in_array($day->dayOfWeekIso, (array) $room->days_open, true) => throw new InvalidArgumentException(__('The reading room is closed that day.')),
            $start === null || $end === null || $end <= $start => throw new InvalidArgumentException(__('Give a start and an end time, the end after the start.')),
            $start < substr($room->opening_time, 0, 5) || $end > substr($room->closing_time, 0, 5) => throw new InvalidArgumentException(__('The reading room is open from :from to :to.', ['from' => substr($room->opening_time, 0, 5), 'to' => substr($room->closing_time, 0, 5)])),
            $day->isSameDay(CarbonImmutable::now($tz)) && $start < CarbonImmutable::now($tz)->format('H:i') => throw new InvalidArgumentException(__('That time has passed.')),
            default => null,
        };
        $already = $this->holding(ReadingRoomBooking::where(['researcher_id' => $researcher->id, 'booking_date' => $day->toDateString()]))->get();
        if ($this->minutes($start, $end) + $already->sum(fn ($b) => $this->minutes(substr($b->start_time, 0, 5), substr($b->end_time, 0, 5))) > $hours * 60) {
            throw new InvalidArgumentException(__('You may book at most :hours hours a day.', ['hours' => $hours]));
        }
        if ($this->overlapping($room, $day->toDateString(), $start, $end)->count() >= $room->capacity) {
            throw new InvalidArgumentException(__('The reading room is full then. Choose another time or day.'));
        }
        if ($type?->max_materials_per_booking !== null && count($records) > $type->max_materials_per_booking) {
            throw new InvalidArgumentException(__('You may ask for at most :count records a visit.', ['count' => $type->max_materials_per_booking]));
        }
        if ($room->rules && empty($data['rules_acknowledged'])) {
            throw new InvalidArgumentException(__('Accept the reading room\'s rules.'));
        }
        $units = collect($records)->unique()->map(fn ($id) => RecordResource::where('institution_id', $user->institution_id)->whereNull('deleted_at')->find($id))
            ->each(fn ($unit) => $unit && EntityKinds::readable($user, $unit) ?: throw new InvalidArgumentException(__('A record you asked for cannot be found.')));

        $booking = DB::transaction(fn () => AuditLog::actingAs($user, function () use ($user, $researcher, $room, $day, $start, $end, $data, $units) {
            $booking = ReadingRoomBooking::create(['institution_id' => $user->institution_id, 'researcher_id' => $researcher->id, 'reading_room_id' => $room->id,
                'booking_date' => $day->toDateString(), 'start_time' => $start, 'end_time' => $end, 'purpose' => trim((string) ($data['purpose'] ?? '')) ?: null,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null, 'status_id' => $this->status('pending'), 'rules_acknowledged_at' => empty($data['rules_acknowledged']) ? null : now()]);
            foreach ($units as $unit) {
                $this->retrieval->request($booking, $unit, $user);
            }
            app(Diary::class)->auto($user, 'auto-booking', __('Booked :room for :date', ['room' => $room->name, 'date' => $day->toDateString()]), $booking);

            return $booking;
        }));
        $this->tell($booking, 'Your reading room booking for :date', [['We have your booking for :room on :date from :from to :to. We will confirm it.', $this->facts($booking)]]);

        return $booking;
    }

    /** Ask for one more record for a booking still to come. */
    public function addRecord(User $user, ReadingRoomBooking $booking, RecordResource $unit): MaterialRequest
    {
        $this->mayChange($user, $booking);
        if (! in_array($booking->status->notation, self::HOLDING, true)) {
            throw new InvalidArgumentException(__('Records can be asked for only before the visit.'));
        }
        $max = $booking->researcher->type?->max_materials_per_booking;
        if ($max !== null && $booking->materials()->count() >= $max) {
            throw new InvalidArgumentException(__('You may ask for at most :count records a visit.', ['count' => $max]));
        }
        if ($unit->institution_id !== $booking->institution_id || ! EntityKinds::readable($user, $unit)) {
            throw new InvalidArgumentException(__('A record you asked for cannot be found.'));
        }

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => $this->retrieval->request($booking, $unit, $user)));
    }

    public function confirm(User $actor, ReadingRoomBooking $booking): void
    {
        $this->mustManage($actor, $booking);
        $this->from($booking, ['pending']);
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $booking->update(['status_id' => $this->status('confirmed'), 'confirmed_by_id' => $actor->id, 'confirmed_at' => now()])));
        $this->tell($booking, 'Your reading room booking for :date is confirmed', [['Your booking for :room on :date from :from to :to is confirmed.', $this->facts($booking)]]);
    }

    /** Cancel: the researcher until the room's notice before the visit; staff at any time. */
    public function cancel(User $actor, ReadingRoomBooking $booking, ?string $reason): void
    {
        $this->from($booking, self::HOLDING);
        $staff = $this->manages($actor, $booking);
        if (! $staff) {
            $this->mayChange($actor, $booking);
            $start = CarbonImmutable::parse($booking->booking_date->toDateString().' '.$booking->start_time, config('workspace.timezone'));
            if (now()->greaterThan($start->subHours($booking->room->cancellation_hours))) {
                throw new InvalidArgumentException(__('A booking may be cancelled up to :hours hours before the visit. Ask the reading room.', ['hours' => $booking->room->cancellation_hours]));
            }
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($booking, $reason) {
            $booking->update(['status_id' => $this->status('cancelled'), 'cancelled_at' => now(), 'cancellation_reason' => trim((string) $reason) ?: null]);
            $this->releaseSeat($booking, null, 'released');
            $booking->equipmentBookings()->whereHas('status', fn ($q) => $q->where('iri', Vocab::BASE.'equipment-booking-status/reserved'))->get()
                ->each(fn ($e) => $e->update(['status_id' => $this->concept('equipment-booking-status', 'cancelled')]));
        }));
        if ($staff) {
            $this->tell($booking, 'Your reading room booking for :date is cancelled', [['Your booking for :room on :date from :from to :to is cancelled.', $this->facts($booking)],
                ['The reason: :reason', ['reason' => trim((string) $reason) ?: '-']]]);
        }
    }

    /** Check in: confirmed if it was not, and a seat when the room manages them (the one chosen, else the first free). */
    public function checkIn(User $actor, ReadingRoomBooking $booking, ?string $seatId = null): void
    {
        $this->mustManage($actor, $booking);
        $this->from($booking, self::HOLDING);
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $booking, $seatId) {
            $booking->update(['status_id' => $this->status('confirmed'), 'checked_in_at' => now(), 'confirmed_by_id' => $booking->confirmed_by_id ?? $actor->id, 'confirmed_at' => $booking->confirmed_at ?? now()]);
            if ($booking->room->has_seat_management) {
                $this->assignSeat($actor, $booking, $seatId, 'occupied');
            }
        }));
    }

    /** Check out: completed, the seat released, and what was in use returned (Heratio's checkOut). */
    public function checkOut(User $actor, ReadingRoomBooking $booking): void
    {
        $this->mustManage($actor, $booking);
        if ($booking->checked_in_at === null) {
            throw new InvalidArgumentException(__('The researcher has not checked in.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $booking) {
            $booking->update(['status_id' => $this->status('completed'), 'checked_out_at' => now()]);
            $this->releaseSeat($booking, $actor, 'released');
            foreach ($booking->materials()->with('status')->get() as $material) {
                if (in_array($material->status->notation, ['delivered', 'in-use'], true)) {
                    $this->retrieval->setStatus($actor, $material, 'returned', __('Returned when the researcher checked out.'));
                }
            }
        }));
    }

    public function noShow(User $actor, ReadingRoomBooking $booking): void
    {
        $this->mustManage($actor, $booking);
        $this->from($booking, self::HOLDING);
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $booking) {
            $booking->update(['status_id' => $this->status('no-show')]);
            $this->releaseSeat($booking, $actor, 'no-show');
        }));
    }

    /** Give the booking a seat: the one chosen, else the first free one of the room. */
    public function assignSeat(User $actor, ReadingRoomBooking $booking, ?string $seatId, string $status = 'assigned'): SeatAssignment
    {
        $this->mustManage($actor, $booking);
        $taken = SeatAssignment::whereNull('released_at')->whereHas('booking', fn ($q) => $q->where('booking_date', $booking->booking_date->toDateString())
            ->where('start_time', '<', $booking->end_time)->where('end_time', '>', $booking->start_time)->where('id', '!=', $booking->id))->pluck('seat_id');
        $seats = ReadingRoomSeat::where(['reading_room_id' => $booking->reading_room_id, 'is_active' => true])->whereNotIn('id', $taken)->orderBy('position')->orderBy('seat_number');
        $seat = $seatId ? ((clone $seats)->find($seatId) ?? throw new InvalidArgumentException(__('That seat is taken, or not in this room.'))) : $seats->first();
        if ($seat === null) {
            throw new InvalidArgumentException(__('No seat is free then.'));
        }
        $this->releaseSeat($booking, $actor, 'released');
        $booking->update(['seat_id' => $seat->id]);

        return SeatAssignment::create(['booking_id' => $booking->id, 'seat_id' => $seat->id, 'assigned_at' => now(), 'assigned_by_id' => $actor->id,
            'status_id' => $this->concept('seat-assignment-status', $status)]);
    }

    public function releaseSeat(ReadingRoomBooking $booking, ?User $actor, string $status): void
    {
        SeatAssignment::where('booking_id', $booking->id)->whereNull('released_at')->get()
            ->each(fn ($a) => $a->update(['released_at' => now(), 'released_by_id' => $actor?->id, 'status_id' => $this->concept('seat-assignment-status', $status)]));
    }

    /** A visitor with no booking: while the room allows walk-ins and has room for them today (Heratio's walkIn). */
    public function walkIn(User $actor, ReadingRoom $room, array $data): WalkInVisitor
    {
        if ($room->institution_id !== $actor->institution_id || ! $actor->hasPermission(Vocab::permission('manage-reading-room'))) {
            throw new InvalidArgumentException(__('Only who may manage the reading room may do that.'));
        }
        $today = CarbonImmutable::today(config('workspace.timezone'))->toDateString();
        if (! $room->walk_ins_allowed) {
            throw new InvalidArgumentException(__('This reading room takes no walk-ins.'));
        }
        if (WalkInVisitor::where(['reading_room_id' => $room->id, 'visit_date' => $today])->whereNull('check_out_time')->count() >= $room->walk_in_capacity) {
            throw new InvalidArgumentException(__('The reading room has no more room for walk-ins today.'));
        }
        if (trim((string) ($data['first_name'] ?? '')) === '' || trim((string) ($data['last_name'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give the visitor\'s first name and surname.'));
        }
        if ($room->rules && empty($data['rules_acknowledged'])) {
            throw new InvalidArgumentException(__('The visitor must accept the reading room\'s rules.'));
        }
        $text = fn (string $k) => trim((string) ($data[$k] ?? '')) ?: null;
        $email = $text('email');
        $account = $email ? User::where(['institution_id' => $room->institution_id])->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first() : null;

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => WalkInVisitor::create(['reading_room_id' => $room->id, 'visit_date' => $today,
            'first_name' => $text('first_name'), 'last_name' => $text('last_name'), 'email' => $email, 'phone' => $text('phone'),
            'id_type_id' => $text('id_type') ? $this->concept('identity-document-type', $data['id_type']) : null, 'id_number' => $text('id_number'),
            'organization' => $text('organization'), 'purpose' => $text('purpose'), 'research_topic' => $text('research_topic'),
            'rules_acknowledged_at' => empty($data['rules_acknowledged']) ? null : now(), 'photo_permission' => (bool) ($data['photo_permission'] ?? false),
            'converted_to_researcher_id' => $account ? Researcher::where('user_id', $account->id)->value('id') : null,
            'seat_id' => $text('seat_id') ? ReadingRoomSeat::where('reading_room_id', $room->id)->find($data['seat_id'])?->id : null,
            'check_in_time' => now(), 'checked_in_by_id' => $actor->id, 'notes' => $text('notes'), 'created_at' => now()])));
    }

    public function walkOut(User $actor, WalkInVisitor $visitor): void
    {
        if ($visitor->check_out_time !== null) {
            throw new InvalidArgumentException(__('The visitor has left already.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $visitor->update(['check_out_time' => now(), 'checked_out_by_id' => $actor->id])));
    }

    /** Book equipment: in working order, within the room's hours and the equipment's maximum, and free then. */
    public function bookEquipment(User $user, ReadingRoomEquipment $equipment, array $data): EquipmentBooking
    {
        $researcher = $this->researchers->of($user);
        if (! $this->researchers->active($researcher)) {
            throw new InvalidArgumentException(__('Only an approved researcher may book equipment.'));
        }
        $room = $equipment->room;
        [$start, $end] = [$this->time($data['start_time'] ?? null), $this->time($data['end_time'] ?? null)];
        $day = CarbonImmutable::parse((string) ($data['booking_date'] ?? ''), config('workspace.timezone'))->toDateString();
        match (true) {
            $room->institution_id !== $user->institution_id => throw new InvalidArgumentException(__('Choose equipment of the institution.')),
            ! $equipment->is_available || in_array($equipment->condition->notation, ['needs-repair', 'out-of-service'], true) => throw new InvalidArgumentException(__('That equipment cannot be booked now.')),
            $start === null || $end === null || $end <= $start => throw new InvalidArgumentException(__('Give a start and an end time, the end after the start.')),
            $start < substr($room->opening_time, 0, 5) || $end > substr($room->closing_time, 0, 5) => throw new InvalidArgumentException(__('The reading room is open from :from to :to.', ['from' => substr($room->opening_time, 0, 5), 'to' => substr($room->closing_time, 0, 5)])),
            $this->minutes($start, $end) > $equipment->max_booking_hours * 60 => throw new InvalidArgumentException(__('It may be booked for at most :hours hours.', ['hours' => $equipment->max_booking_hours])),
            EquipmentBooking::where(['equipment_id' => $equipment->id, 'booking_date' => $day])->whereHas('status', fn ($q) => $q->whereIn('iri', [Vocab::BASE.'equipment-booking-status/reserved', Vocab::BASE.'equipment-booking-status/in-use']))
                ->where('start_time', '<', $end)->where('end_time', '>', $start)->exists() => throw new InvalidArgumentException(__('It is booked then.')),
            default => null,
        };
        $visit = ($data['booking_id'] ?? '') !== '' ? ReadingRoomBooking::where(['id' => $data['booking_id'], 'researcher_id' => $researcher->id])->first() : null;

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => EquipmentBooking::create(['booking_id' => $visit?->id, 'researcher_id' => $researcher->id, 'equipment_id' => $equipment->id,
            'booking_date' => $day, 'start_time' => $start, 'end_time' => $end, 'purpose' => trim((string) ($data['purpose'] ?? '')) ?: null,
            'status_id' => $this->concept('equipment-booking-status', 'reserved')])));
    }

    /** Equipment handed over, returned (its condition, which sets the equipment's when damaged), cancelled or not collected. */
    public function equipmentStep(User $actor, EquipmentBooking $booking, string $step, array $data = []): void
    {
        $staff = $actor->institution_id === $booking->equipment->room->institution_id && $actor->hasPermission(Vocab::permission('manage-reading-room'));
        $mine = $booking->researcher->user_id === $actor->id;
        $from = $booking->status->notation;
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => match (true) {
            $step === 'check-out' && $staff && $from === 'reserved' => $booking->update(['status_id' => $this->concept('equipment-booking-status', 'in-use'), 'checked_out_at' => now(), 'checked_out_by_id' => $actor->id]),
            $step === 'return' && $staff && $from === 'in-use' => $this->returnEquipment($actor, $booking, $data),
            $step === 'cancel' && ($staff || $mine) && $from === 'reserved' => $booking->update(['status_id' => $this->concept('equipment-booking-status', 'cancelled')]),
            $step === 'no-show' && $staff && $from === 'reserved' => $booking->update(['status_id' => $this->concept('equipment-booking-status', 'no-show')]),
            default => throw new InvalidArgumentException(__('That cannot be done now.')),
        }));
    }

    /** Maintenance done: logged, and the equipment's condition and dates follow (Heratio's equipment history). */
    public function maintain(User $actor, ReadingRoomEquipment $equipment, array $data): EquipmentMaintenance
    {
        if (trim((string) ($data['description'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Say what was done.'));
        }
        $after = ($data['condition'] ?? '') !== '' ? $this->concept('equipment-condition', $data['condition']) : $equipment->condition_id;

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $equipment, $data, $after) {
            $log = EquipmentMaintenance::create(['equipment_id' => $equipment->id, 'description' => trim($data['description']), 'condition_before' => $equipment->condition->notation,
                'condition_after' => Concept::find($after)->notation, 'next_maintenance_on' => ($data['next_maintenance_on'] ?? '') ?: null, 'performed_by_id' => $actor->id, 'performed_at' => now()]);
            $equipment->update(['condition_id' => $after, 'last_maintenance_on' => now()->toDateString(), 'next_maintenance_on' => ($data['next_maintenance_on'] ?? '') ?: $equipment->next_maintenance_on]);

            return $log;
        }));
    }

    /** A day's bookings of a room, for the desk. */
    public function day(ReadingRoom $room, string $day): Collection
    {
        return ReadingRoomBooking::where(['reading_room_id' => $room->id, 'booking_date' => $day])->with('researcher.user', 'status', 'seat', 'materials.status', 'materials.record')->orderBy('start_time')->get();
    }

    public function manages(User $user, ReadingRoomBooking $booking): bool
    {
        return $user->institution_id === $booking->institution_id && $user->hasPermission(Vocab::permission('manage-reading-room'));
    }

    public function status(string $notation): string
    {
        return $this->concept('booking-status', $notation);
    }

    private function returnEquipment(User $actor, EquipmentBooking $booking, array $data): void
    {
        $condition = $this->concept('return-condition', $data['condition'] ?? 'good');
        $booking->update(['status_id' => $this->concept('equipment-booking-status', 'returned'), 'returned_at' => now(), 'returned_by_id' => $actor->id,
            'condition_on_return_id' => $condition, 'return_notes' => trim((string) ($data['return_notes'] ?? '')) ?: null]);
        if (($data['condition'] ?? '') === 'damaged') {
            $booking->equipment->update(['condition_id' => $this->concept('equipment-condition', 'needs-repair')]);
        }
    }

    private function holding($query)
    {
        return $query->whereHas('status', fn ($q) => $q->whereIn('iri', array_map(fn ($s) => self::STATUSES.$s, self::HOLDING)));
    }

    private function overlapping(ReadingRoom $room, string $day, string $start, string $end): Collection
    {
        return $this->holding(ReadingRoomBooking::where(['reading_room_id' => $room->id, 'booking_date' => $day])->where('start_time', '<', $end)->where('end_time', '>', $start))->get();
    }

    private function from(ReadingRoomBooking $booking, array $statuses): void
    {
        if (! in_array($booking->status->notation, $statuses, true)) {
            throw new InvalidArgumentException(__('That cannot be done to a booking that is :status.', ['status' => $booking->status->label(['en'])]));
        }
    }

    private function mayChange(User $user, ReadingRoomBooking $booking): void
    {
        if ($booking->researcher->user_id !== $user->id && ! $this->manages($user, $booking)) {
            throw new InvalidArgumentException(__('That is not yours.'));
        }
    }

    private function mustManage(User $actor, ReadingRoomBooking $booking): void
    {
        if (! $this->manages($actor, $booking)) {
            throw new InvalidArgumentException(__('Only who may manage the reading room may do that.'));
        }
    }

    private function time(?string $value): ?string
    {
        return preg_match('/^([01]\d|2[0-3]):([0-5]\d)/', (string) $value, $m) ? $m[1].':'.$m[2] : null;
    }

    private function minutes(string $from, string $to): int
    {
        [$h1, $m1] = array_map('intval', explode(':', $from));
        [$h2, $m2] = array_map('intval', explode(':', $to));

        return ($h2 * 60 + $m2) - ($h1 * 60 + $m1);
    }

    private function facts(ReadingRoomBooking $booking): array
    {
        return ['room' => $booking->room->name, 'date' => $booking->booking_date->format('j F Y'), 'from' => substr($booking->start_time, 0, 5), 'to' => substr($booking->end_time, 0, 5)];
    }

    /** @param  list<array{0: string, 1: array}>  $lines */
    private function tell(ReadingRoomBooking $booking, string $subject, array $lines): void
    {
        $user = $booking->researcher->user;
        if ($user && $user->disabled_at === null) {
            $this->outbox->send($user, new ResearchMail($user, $subject, ['date' => $booking->booking_date->format('j F Y')], $lines, route('research.bookings.show', $booking), 'Open your booking', 'booking'));
        }
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
