<?php

// OpenRiC Workspace - researchers and the reading room (roadmap phase 19b, ADR 0017): registration and its review,
// a booking within the room's and the researcher type's limits, the desk's day (confirm, check in with a seat,
// fetch, hand over, check out), walk-ins, equipment, and who may do what.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\EquipmentBooking;
use App\Models\Institution;
use App\Models\MaterialRequest;
use App\Models\ReadingRoom;
use App\Models\ReadingRoomBooking;
use App\Models\ReadingRoomEquipment;
use App\Models\RecordResource;
use App\Models\Researcher;
use App\Models\ResearcherType;
use App\Models\User;
use App\Models\WalkInVisitor;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReadingRoomTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private Institution $institution;

    private User $admin;

    private User $reader;

    private User $other;

    private ReadingRoom $room;

    private RecordResource $letter;

    private RecordResource $map;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-02 08:00');
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->admin = app(AccountService::class)->create($this->institution, 'Admin', 'admin@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->admin, 'administrator');
        $this->reader = app(AccountService::class)->create($this->institution, 'Rita', 'rita@example.org', 'correct horse battery staple');
        $this->other = app(AccountService::class)->create($this->institution, 'Otto', 'otto@example.org', 'correct horse battery staple');
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $fonds = app(RecordService::class)->create($this->institution, self::LEVEL.'fonds', 'Parish council records', 'en', null, $holder, 'PC');
        $this->letter = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Letter of 3 May 1902', 'en', $fonds, null, '1');
        $this->map = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Map of the parish', 'en', $fonds, null, '2');
        foreach ([$holder, $fonds, $this->letter, $this->map] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->admin);
        }
        $this->actingAs($this->admin)->post(route('reading-room.rooms.store'), ['name' => 'Main reading room', 'capacity' => 1, 'has_seat_management' => '1', 'walk_ins_allowed' => '1',
            'walk_in_capacity' => 1, 'advance_booking_days' => 14, 'max_booking_hours' => 4, 'cancellation_hours' => 24, 'opening_time' => '09:00', 'closing_time' => '16:30',
            'days_open' => [1, 2, 3, 4, 5], 'rules' => 'Pencils only.'])->assertSessionHasNoErrors();
        $this->room = ReadingRoom::sole();
        $this->actingAs($this->admin)->post(route('reading-room.seats.store', $this->room), ['count' => 2, 'prefix' => 'A', 'seat_type' => 'standard'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('reading-room.schedules.store', $this->room), ['name' => 'Morning retrieval', 'retrieval_time' => '08:30', 'cutoff_minutes_before' => 15]);
    }

    private function register(User $user, array $extra = [])
    {
        return $this->actingAs($user)->post(route('research.register'), ['first_name' => $user->name, 'last_name' => 'Researcher', 'affiliation' => 'academic',
            'institution' => 'University of the Cape', 'research_interests' => 'Parish history', 'id_type' => 'passport', 'id_number' => 'A1234567'] + $extra);
    }

    private function booking(User $user, array $data, array $records = [])
    {
        return $this->actingAs($user)->post(route('research.book.store'), $data + ['reading_room_id' => $this->room->id, 'rules_acknowledged' => '1', 'records' => $records]);
    }

    public function test_a_researcher_registers_and_is_approved_once_their_identity_is_verified(): void
    {
        $this->actingAs($this->admin)->post(route('reading-room.types.store'), ['name' => 'Postgraduate', 'expiry_months' => 6, 'max_materials_per_booking' => 1,
            'requires_id_verification' => '1', 'is_active' => '1'])->assertSessionHasNoErrors();
        $type = ResearcherType::sole();
        $this->register($this->reader, ['researcher_type_id' => $type->id])->assertSessionHasNoErrors();
        $researcher = Researcher::sole();
        $this->assertSame(['pending', 'A1234567'], [$researcher->status->notation, $researcher->id_number]);
        $this->assertNotSame('A1234567', \DB::table('researchers')->value('id_number'), 'the identity number is kept encrypted');
        $this->booking($this->reader, ['booking_date' => '2026-11-03', 'start_time' => '09:00', 'end_time' => '10:00'])->assertSessionHasErrors('research');

        $this->actingAs($this->reader)->get(route('reading-room.researchers'))->assertForbidden();
        $this->actingAs($this->admin)->post(route('reading-room.researchers.decide', [$researcher, 'approve']))->assertSessionHasErrors('research');
        $this->actingAs($this->admin)->post(route('reading-room.researchers.verify', $researcher), ['type' => 'id-document', 'status' => 'verified', 'document_type' => 'Passport'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('reading-room.researchers.decide', [$researcher, 'approve']))->assertSessionHasNoErrors();
        $researcher->refresh();
        $this->assertSame(['approved', '2027-05-02', 'R-2026-00001'], [$researcher->status->notation, $researcher->expires_on->format('Y-m-d'), $researcher->card_number]);

        // Rejected, then registered again; a month before it ends, a warning; then it expires.
        $this->register($this->other)->assertSessionHasNoErrors();
        $otto = Researcher::where('user_id', $this->other->id)->sole();
        $this->actingAs($this->admin)->post(route('reading-room.researchers.decide', [$otto, 'reject']), ['reason' => 'No affiliation given'])->assertSessionHasNoErrors();
        $this->register($this->other, ['institution' => 'Rhodes University'])->assertSessionHasNoErrors();
        $this->assertSame('pending', $otto->fresh()->status->notation);
        $this->travelTo('2027-04-10 07:00');
        $this->artisan('workspace:researchers')->expectsOutputToContain('Warned 1')->assertSuccessful();
        $this->travelTo('2027-05-03 07:00');
        $this->artisan('workspace:researchers')->assertSuccessful();
        $this->assertSame('expired', $researcher->fresh()->status->notation);
        $this->actingAs($this->reader)->post(route('research.renew'))->assertSessionHasNoErrors();
        $this->assertSame('pending', $researcher->fresh()->status->notation);
    }

    public function test_a_booking_keeps_the_rooms_and_the_researchers_limits(): void
    {
        foreach ([$this->reader, $this->other] as $user) {
            $this->register($user);
            $this->actingAs($this->admin)->post(route('reading-room.researchers.decide', [Researcher::where('user_id', $user->id)->sole(), 'approve']))->assertSessionHasNoErrors();
        }
        $refused = function (array $data, string $why) {
            $this->booking($this->reader, $data)->assertSessionHasErrors('research');
            $this->assertStringContainsString($why, session('errors')->first('research'));
        };
        $refused(['booking_date' => '2026-11-07', 'start_time' => '09:00', 'end_time' => '10:00'], 'closed that day');
        $refused(['booking_date' => '2026-11-03', 'start_time' => '08:00', 'end_time' => '10:00'], 'open from 09:00 to 16:30');
        $refused(['booking_date' => '2026-11-03', 'start_time' => '09:00', 'end_time' => '14:00'], 'at most 4 hours');
        $refused(['booking_date' => '2026-12-01', 'start_time' => '09:00', 'end_time' => '10:00'], 'at most 14 days ahead');
        $refused(['booking_date' => '2026-11-03', 'start_time' => '09:00', 'end_time' => '10:00', 'rules_acknowledged' => '0'], 'rules');

        $this->booking($this->reader, ['booking_date' => '2026-11-03', 'start_time' => '09:00', 'end_time' => '11:00'], [$this->letter->id])->assertSessionHasNoErrors();
        $this->booking($this->other, ['booking_date' => '2026-11-03', 'start_time' => '10:00', 'end_time' => '12:00'])->assertSessionHasErrors('research');
        $this->assertStringContainsString('full then', session('errors')->first('research'));
        $this->booking($this->other, ['booking_date' => '2026-11-03', 'start_time' => '11:00', 'end_time' => '12:00'])->assertSessionHasNoErrors();
        $request = MaterialRequest::sole();
        $this->assertSame(['requested', '2026-11-03 08:30'], [$request->status->notation, $request->retrieval_scheduled_for->format('Y-m-d H:i')], 'the run before the visit');

        // The researcher cancels only with a day's notice; staff at any time.
        $booking = ReadingRoomBooking::where('researcher_id', Researcher::where('user_id', $this->other->id)->value('id'))->sole();
        $this->travelTo('2026-11-02 12:00');
        $this->actingAs($this->other)->post(route('research.bookings.cancel', $booking))->assertSessionHasErrors('research');
        $this->actingAs($this->admin)->post(route('research.bookings.cancel', $booking), ['reason' => 'Room closed for repairs'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $booking->fresh()->status->notation);
    }

    public function test_the_desk_confirms_checks_in_fetches_hands_over_and_checks_out(): void
    {
        $this->register($this->reader);
        $researcher = Researcher::sole();
        $this->actingAs($this->admin)->post(route('reading-room.researchers.decide', [$researcher, 'approve']));
        $this->booking($this->reader, ['booking_date' => '2026-11-02', 'start_time' => '10:00', 'end_time' => '12:00'], [$this->letter->id, $this->map->id])->assertSessionHasNoErrors();
        $booking = ReadingRoomBooking::sole();
        [$letter, $map] = [MaterialRequest::where('record_resource_id', $this->letter->id)->sole(), MaterialRequest::where('record_resource_id', $this->map->id)->sole()];

        $this->actingAs($this->reader)->post(route('reading-room.bookings.step', [$booking, 'confirm']))->assertForbidden();
        $this->actingAs($this->admin)->post(route('reading-room.bookings.step', [$booking, 'confirm']))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('reading-room.requests.step', [$map, 'handle']), ['priority' => 'high', 'curatorial_approval_required' => '1'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('reading-room.requests.step', [$map, 'status']), ['status' => 'retrieved'])->assertSessionHasErrors('research');
        $this->actingAs($this->admin)->post(route('reading-room.requests.step', [$map, 'approve']))->assertSessionHasNoErrors();
        foreach ([$letter, $map] as $m) {
            foreach (['retrieved', 'delivered'] as $step) {
                $this->actingAs($this->admin)->post(route('reading-room.requests.step', [$m, 'status']), ['status' => $step])->assertSessionHasNoErrors();
            }
        }
        $this->actingAs($this->admin)->post(route('reading-room.requests.step', [$letter, 'status']), ['status' => 'requested'])->assertSessionHasErrors('research');
        $this->assertSame('reading-room', $letter->fresh()->locationCurrent->notation);

        $this->actingAs($this->admin)->post(route('reading-room.bookings.step', [$booking, 'check-in']))->assertSessionHasNoErrors();
        $this->assertSame('A1', $booking->fresh()->seat->seat_number, 'the first free seat');
        $this->actingAs($this->admin)->post(route('reading-room.requests.step', [$letter, 'handoff']), ['handoff_type' => 'checkout', 'condition' => 'good', 'signature_confirmed' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(['in-use', true], [$letter->fresh()->status->notation, $letter->fresh()->checkout_confirmed_at !== null]);

        // Messages: the researcher does not see staff notes.
        $this->actingAs($this->admin)->post(route('research.requests.write', $letter), ['body' => 'Fragile: handle the seal with care.', 'is_internal' => '1'])->assertSessionHasNoErrors();
        $this->actingAs($this->reader)->post(route('research.requests.write', $letter), ['body' => 'May I photograph it?'])->assertSessionHasNoErrors();
        $this->actingAs($this->reader)->get(route('research.bookings.show', $booking))->assertOk()->assertSee('May I photograph it?')->assertDontSee('handle the seal');
        $this->actingAs($this->admin)->get(route('research.bookings.show', $booking))->assertOk()->assertSee('handle the seal');

        $this->actingAs($this->admin)->post(route('reading-room.bookings.step', [$booking, 'check-out']))->assertSessionHasNoErrors();
        $this->assertSame(['completed', 'returned', 'returned'], [$booking->fresh()->status->notation, $letter->fresh()->status->notation, $map->fresh()->status->notation]);
        $this->assertNotNull($booking->seatAssignments()->sole()->released_at);
        $this->assertSame(['requested', 'retrieved', 'delivered', 'in-use', 'returned'], $letter->statusChanges()->pluck('new_status')->all());
        $this->actingAs($this->admin)->get(route('reading-room.slips', ['ids' => [$letter->id]]))->assertOk()->assertSee('Letter of 3 May 1902')->assertSee('R-2026-00001');
        $this->assertNotNull($letter->fresh()->call_slip_printed_at);
    }

    public function test_walk_ins_and_equipment(): void
    {
        $walk = fn (string $name) => $this->actingAs($this->admin)->post(route('reading-room.walk-ins.store', $this->room), ['first_name' => $name, 'last_name' => 'Visitor', 'rules_acknowledged' => '1']);
        $walk('Wendy')->assertSessionHasNoErrors();
        $walk('Walter')->assertSessionHasErrors('research');
        $this->actingAs($this->admin)->post(route('reading-room.walk-ins.leave', WalkInVisitor::sole()))->assertSessionHasNoErrors();
        $walk('Walter')->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post(route('reading-room.equipment.store', $this->room), ['name' => 'Microfilm reader 1', 'equipment_type' => 'microfilm-reader', 'condition' => 'good',
            'max_booking_hours' => 2, 'booking_increment_minutes' => 30, 'is_available' => '1'])->assertSessionHasNoErrors();
        $reader = ReadingRoomEquipment::sole();
        $this->register($this->reader);
        $this->actingAs($this->admin)->post(route('reading-room.researchers.decide', [Researcher::sole(), 'approve']));
        $book = fn (string $from, string $to) => $this->actingAs($this->reader)->post(route('research.equipment.book', $reader), ['booking_date' => '2026-11-03', 'start_time' => $from, 'end_time' => $to]);
        $book('09:00', '12:00')->assertSessionHasErrors('research');
        $book('09:00', '10:00')->assertSessionHasNoErrors();
        $book('09:30', '10:30')->assertSessionHasErrors('research');
        $booking = EquipmentBooking::sole();
        $this->actingAs($this->admin)->post(route('reading-room.equipment-bookings.step', [$booking, 'check-out']))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('reading-room.equipment-bookings.step', [$booking, 'return']), ['condition' => 'damaged', 'return_notes' => 'Lamp broken'])->assertSessionHasNoErrors();
        $this->assertSame('needs-repair', $reader->fresh()->condition->notation);
        $book('11:00', '12:00')->assertSessionHasErrors('research');
        $this->actingAs($this->admin)->post(route('reading-room.equipment.maintain', $reader), ['description' => 'Lamp replaced', 'condition' => 'good'])->assertSessionHasNoErrors();
        $this->assertSame(['good', 'needs-repair'], [$reader->fresh()->condition->notation, $reader->maintenance()->sole()->condition_before]);
        $book('11:00', '12:00')->assertSessionHasNoErrors();
    }
}
