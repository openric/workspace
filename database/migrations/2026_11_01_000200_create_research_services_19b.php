<?php

// OpenRiC Workspace - research services, part 19b (roadmap phase 19, ADR 0017): researchers and the reading room,
// after Heratio's ahg-research: research_researcher_type, research_researcher, research_verification,
// research_reading_room, research_reading_room_seat, research_booking, research_seat_assignment,
// research_material_request, research_request_queue, research_request_status_history,
// research_request_correspondence, research_retrieval_schedule, research_walk_in_visitor, research_equipment,
// research_equipment_booking, research_equipment_maintenance and research_custody_handoff. Statuses, types and
// conditions are vocabulary rows; researcher types are reference data of the institution.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Access\RoleService;
use App\Models\Institution;
use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const VOCAB = 'https://workspace.openric.org/vocab/';

    private const VOCABULARIES = ['researcher-affiliation', 'identity-document-type', 'researcher-status', 'verification-type', 'verification-status', 'seat-type',
        'booking-status', 'seat-assignment-status', 'material-request-type', 'request-priority', 'material-location', 'material-request-status', 'request-queue-type',
        'equipment-type', 'equipment-condition', 'equipment-booking-status', 'return-condition', 'custody-handoff-type', 'permission'];

    public function up(): void
    {
        foreach (self::VOCABULARIES as $name) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$name.ttl")));
        }
        $concept = fn (Blueprint $table, string $column, bool $nullable = false) => $nullable
            ? $table->foreignUuid($column)->nullable()->constrained('concepts')->restrictOnDelete()
            : $table->foreignUuid($column)->constrained('concepts')->restrictOnDelete();
        $who = fn (Blueprint $table, string $column) => $table->foreignUuid($column)->nullable()->constrained('users')->nullOnDelete();

        // Files of research services: a researcher's photo, verification documents, floor plans, attachments.
        // Kept by checksum in the file store, as agreement documents are.
        Schema::create('research_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('original_name');
            $table->text('storage_path');
            $table->text('mime_type');
            $table->bigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->text('scanned_with')->nullable();
            $table->timestampTz('scanned_at')->nullable();
            $table->foreignUuid('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('researcher_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->text('name');
            $table->text('code')->nullable();
            $table->text('description')->nullable();
            $table->integer('max_booking_days_advance')->nullable();
            $table->integer('max_booking_hours_per_day')->nullable();
            $table->integer('max_materials_per_booking')->nullable();
            $table->boolean('can_remote_access')->default(false);
            $table->boolean('can_request_reproductions')->default(true);
            $table->boolean('can_export_data')->default(true);
            $table->boolean('requires_id_verification')->default(true);
            $table->boolean('auto_approve')->default(false);
            $table->integer('expiry_months')->default(12);
            $table->integer('priority_level')->default(0);
            $table->boolean('is_active')->default(true);
            $table->integer('position')->default(0);
            $table->timestampsTz();
        });

        Schema::create('researchers', function (Blueprint $table) use ($concept, $who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->text('title')->nullable();
            $table->text('first_name');
            $table->text('last_name');
            // The phone and the identity number are kept encrypted (Heratio encrypts them too).
            $table->text('phone')->nullable();
            $concept($table, 'affiliation_id');
            $table->text('institution')->nullable();
            $table->text('department')->nullable();
            $table->text('position')->nullable();
            $table->text('student_id')->nullable();
            $table->text('research_interests')->nullable();
            $table->text('current_project')->nullable();
            $table->text('orcid_id')->nullable();
            $table->text('researcher_id_wos')->nullable();
            $table->text('scopus_id')->nullable();
            $table->text('isni')->nullable();
            $table->foreignUuid('researcher_type_id')->nullable()->constrained('researcher_types')->nullOnDelete();
            $concept($table, 'id_type_id', true);
            $table->text('id_number')->nullable();
            $table->boolean('id_verified')->default(false);
            $who($table, 'id_verified_by_id');
            $table->timestampTz('id_verified_at')->nullable();
            $concept($table, 'status_id');
            $table->text('rejection_reason')->nullable();
            $who($table, 'approved_by_id');
            $table->timestampTz('approved_at')->nullable();
            $table->date('expires_on')->nullable();
            $table->timestampTz('renewal_reminder_sent_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('photo_file_id')->nullable()->constrained('research_files')->nullOnDelete();
            $table->text('card_number')->nullable()->unique();
            $table->timestampTz('card_issued_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('researcher_verifications', function (Blueprint $table) use ($concept, $who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('researcher_id')->constrained('researchers')->cascadeOnDelete();
            $concept($table, 'type_id');
            $table->text('document_type')->nullable();
            $table->text('document_reference')->nullable();
            $table->foreignUuid('document_file_id')->nullable()->constrained('research_files')->nullOnDelete();
            $concept($table, 'status_id');
            $who($table, 'verified_by_id');
            $table->timestampTz('verified_at')->nullable();
            $table->date('expires_on')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });

        Schema::create('reading_rooms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('repository_id')->nullable()->constrained('repositories')->nullOnDelete();
            $table->text('name');
            $table->text('code')->nullable();
            $table->text('description')->nullable();
            $table->text('amenities')->nullable();
            $table->integer('capacity')->default(10);
            $table->boolean('has_seat_management')->default(false);
            $table->boolean('walk_ins_allowed')->default(true);
            $table->integer('walk_in_capacity')->default(2);
            $table->text('location')->nullable();
            $table->foreignUuid('floor_plan_file_id')->nullable()->constrained('research_files')->nullOnDelete();
            $table->text('operating_hours')->nullable();
            $table->text('rules')->nullable();
            $table->integer('advance_booking_days')->default(14);
            $table->integer('max_booking_hours')->default(8);
            $table->integer('cancellation_hours')->default(24);
            $table->time('opening_time')->default('09:00');
            $table->time('closing_time')->default('16:30');
            // ISO weekdays it opens: 1 Monday to 7 Sunday.
            $table->jsonb('days_open')->default('[1,2,3,4,5]');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE reading_rooms ADD CONSTRAINT reading_rooms_hours CHECK (closing_time > opening_time AND capacity >= 0 AND walk_in_capacity >= 0)');

        Schema::create('reading_room_seats', function (Blueprint $table) use ($concept) {
            $table->uuid('id')->primary();
            $table->foreignUuid('reading_room_id')->constrained('reading_rooms')->cascadeOnDelete();
            $table->text('seat_number');
            $table->text('seat_label')->nullable();
            $concept($table, 'seat_type_id');
            $table->boolean('has_power')->default(false);
            $table->boolean('has_lamp')->default(false);
            $table->boolean('has_computer')->default(false);
            $table->boolean('has_magnifier')->default(false);
            $table->text('zone')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('position')->default(0);
            $table->timestampsTz();
            $table->unique(['reading_room_id', 'seat_number']);
        });

        Schema::create('reading_room_bookings', function (Blueprint $table) use ($concept, $who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('researcher_id')->constrained('researchers')->cascadeOnDelete();
            $table->foreignUuid('reading_room_id')->constrained('reading_rooms')->restrictOnDelete();
            $table->date('booking_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->text('purpose')->nullable();
            $concept($table, 'status_id');
            $who($table, 'confirmed_by_id');
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestampTz('checked_in_at')->nullable();
            $table->timestampTz('checked_out_at')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_walk_in')->default(false);
            $table->timestampTz('rules_acknowledged_at')->nullable();
            $table->foreignUuid('seat_id')->nullable()->constrained('reading_room_seats')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['reading_room_id', 'booking_date']);
        });
        DB::statement('ALTER TABLE reading_room_bookings ADD CONSTRAINT reading_room_bookings_times CHECK (end_time > start_time)');

        Schema::create('seat_assignments', function (Blueprint $table) use ($concept, $who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('booking_id')->constrained('reading_room_bookings')->cascadeOnDelete();
            $table->foreignUuid('seat_id')->constrained('reading_room_seats')->cascadeOnDelete();
            $table->timestampTz('assigned_at');
            $who($table, 'assigned_by_id');
            $table->timestampTz('released_at')->nullable();
            $who($table, 'released_by_id');
            $concept($table, 'status_id');
            $table->text('notes')->nullable();
        });

        Schema::create('request_queues', function (Blueprint $table) use ($concept) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->text('name');
            $table->text('code')->nullable();
            $table->text('description')->nullable();
            $concept($table, 'queue_type_id');
            // The statuses (material-request-status notations), room and priority a queue shows.
            $table->jsonb('filter_statuses')->nullable();
            $table->foreignUuid('filter_room_id')->nullable()->constrained('reading_rooms')->nullOnDelete();
            $table->text('filter_priority')->nullable();
            $table->text('sort_field')->default('created_at');
            $table->string('sort_direction', 4)->default('asc');
            $table->boolean('auto_assign')->default(false);
            $table->foreignUuid('assigned_staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('position')->default(0);
            $table->timestampsTz();
        });

        Schema::create('retrieval_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('reading_room_id')->constrained('reading_rooms')->cascadeOnDelete();
            $table->text('name');
            // ISO weekday, 1 Monday to 7 Sunday; empty: every day the room is open.
            $table->smallInteger('day_of_week')->nullable();
            $table->time('retrieval_time');
            $table->integer('cutoff_minutes_before')->default(30);
            $table->integer('max_items_per_run')->nullable();
            $table->text('storage_location')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });

        Schema::create('material_requests', function (Blueprint $table) use ($concept, $who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('booking_id')->constrained('reading_room_bookings')->cascadeOnDelete();
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->restrictOnDelete();
            $table->integer('quantity')->default(1);
            $table->text('notes')->nullable();
            $concept($table, 'request_type_id');
            $concept($table, 'priority_id');
            $table->text('handling_instructions')->nullable();
            // Where it is kept, as storage says when it is asked for; and where it is now.
            $table->text('location_code')->nullable();
            $table->text('shelf_location')->nullable();
            $concept($table, 'location_current_id', true);
            $table->timestampTz('retrieval_scheduled_for')->nullable();
            $table->foreignUuid('queue_id')->nullable()->constrained('request_queues')->nullOnDelete();
            $table->text('box_number')->nullable();
            $table->text('folder_number')->nullable();
            $table->boolean('curatorial_approval_required')->default(false);
            $who($table, 'curatorial_approved_by_id');
            $table->timestampTz('curatorial_approved_at')->nullable();
            $table->timestampTz('call_slip_printed_at')->nullable();
            $who($table, 'call_slip_printed_by_id');
            $concept($table, 'status_id');
            $table->text('unavailable_reason')->nullable();
            $who($table, 'retrieved_by_id');
            $table->timestampTz('retrieved_at')->nullable();
            $table->timestampTz('returned_at')->nullable();
            $table->text('condition_notes')->nullable();
            $table->date('sla_due_on')->nullable();
            $who($table, 'assigned_to_id');
            $table->timestampTz('checkout_confirmed_at')->nullable();
            $who($table, 'checkout_confirmed_by_id');
            $concept($table, 'return_condition_id', true);
            $who($table, 'return_verified_by_id');
            $table->timestampTz('return_verified_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('material_request_status_changes', function (Blueprint $table) use ($who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('material_request_id')->constrained('material_requests')->cascadeOnDelete();
            $table->text('old_status')->nullable();
            $table->text('new_status');
            $who($table, 'changed_by_id');
            $table->text('notes')->nullable();
            $table->timestampTz('created_at');
        });

        Schema::create('material_request_messages', function (Blueprint $table) use ($who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('material_request_id')->constrained('material_requests')->cascadeOnDelete();
            $table->boolean('from_staff');
            $who($table, 'sender_id');
            $table->text('subject')->nullable();
            $table->text('body');
            // Staff only; the researcher does not see it.
            $table->boolean('is_internal')->default(false);
            $table->foreignUuid('attachment_file_id')->nullable()->constrained('research_files')->nullOnDelete();
            $table->timestampTz('created_at');
        });

        Schema::create('custody_handoffs', function (Blueprint $table) use ($concept, $who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('material_request_id')->constrained('material_requests')->cascadeOnDelete();
            $concept($table, 'handoff_type_id');
            $who($table, 'from_handler_id');
            $who($table, 'to_handler_id');
            $table->text('from_location')->nullable();
            $table->text('to_location')->nullable();
            $concept($table, 'condition_id', true);
            $table->text('condition_notes')->nullable();
            $table->boolean('signature_confirmed')->default(false);
            $table->timestampTz('confirmed_at')->nullable();
            $who($table, 'confirmed_by_id');
            $table->text('barcode_scanned')->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('created_at');
            $who($table, 'created_by_id');
        });

        Schema::create('walk_in_visitors', function (Blueprint $table) use ($concept, $who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('reading_room_id')->constrained('reading_rooms')->cascadeOnDelete();
            $table->date('visit_date');
            $table->text('first_name');
            $table->text('last_name');
            $table->text('email')->nullable();
            $table->text('phone')->nullable();
            $concept($table, 'id_type_id', true);
            $table->text('id_number')->nullable();
            $table->text('organization')->nullable();
            $table->text('purpose')->nullable();
            $table->text('research_topic')->nullable();
            $table->timestampTz('rules_acknowledged_at')->nullable();
            $table->boolean('photo_permission')->default(false);
            $table->foreignUuid('converted_to_researcher_id')->nullable()->constrained('researchers')->nullOnDelete();
            $table->foreignUuid('seat_id')->nullable()->constrained('reading_room_seats')->nullOnDelete();
            $table->timestampTz('check_in_time');
            $table->timestampTz('check_out_time')->nullable();
            $who($table, 'checked_in_by_id');
            $who($table, 'checked_out_by_id');
            $table->text('notes')->nullable();
            $table->timestampTz('created_at');
        });

        Schema::create('reading_room_equipment', function (Blueprint $table) use ($concept) {
            $table->uuid('id')->primary();
            $table->foreignUuid('reading_room_id')->constrained('reading_rooms')->cascadeOnDelete();
            $table->text('name');
            $table->text('code')->nullable();
            $concept($table, 'equipment_type_id');
            $table->text('brand')->nullable();
            $table->text('model')->nullable();
            $table->text('serial_number')->nullable();
            $table->text('description')->nullable();
            $table->text('location')->nullable();
            $table->boolean('requires_training')->default(false);
            $table->integer('max_booking_hours')->default(4);
            $table->integer('booking_increment_minutes')->default(30);
            $concept($table, 'condition_id');
            $table->date('last_maintenance_on')->nullable();
            $table->date('next_maintenance_on')->nullable();
            $table->boolean('is_available')->default(true);
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });

        Schema::create('equipment_bookings', function (Blueprint $table) use ($concept, $who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('booking_id')->nullable()->constrained('reading_room_bookings')->nullOnDelete();
            $table->foreignUuid('researcher_id')->constrained('researchers')->cascadeOnDelete();
            $table->foreignUuid('equipment_id')->constrained('reading_room_equipment')->cascadeOnDelete();
            $table->date('booking_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->text('purpose')->nullable();
            $concept($table, 'status_id');
            $table->timestampTz('checked_out_at')->nullable();
            $who($table, 'checked_out_by_id');
            $table->timestampTz('returned_at')->nullable();
            $who($table, 'returned_by_id');
            $concept($table, 'condition_on_return_id', true);
            $table->text('return_notes')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE equipment_bookings ADD CONSTRAINT equipment_bookings_times CHECK (end_time > start_time)');

        Schema::create('equipment_maintenance', function (Blueprint $table) use ($who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('equipment_id')->constrained('reading_room_equipment')->cascadeOnDelete();
            $table->text('description');
            $table->text('condition_before')->nullable();
            $table->text('condition_after')->nullable();
            $table->date('next_maintenance_on')->nullable();
            $who($table, 'performed_by_id');
            $table->timestampTz('performed_at');
        });

        foreach ([
            ['researchers', 'affiliation_id', 'researcher-affiliation'], ['researchers', 'id_type_id', 'identity-document-type'], ['researchers', 'status_id', 'researcher-status'],
            ['researcher_verifications', 'type_id', 'verification-type'], ['researcher_verifications', 'status_id', 'verification-status'],
            ['reading_room_seats', 'seat_type_id', 'seat-type'], ['reading_room_bookings', 'status_id', 'booking-status'], ['seat_assignments', 'status_id', 'seat-assignment-status'],
            ['request_queues', 'queue_type_id', 'request-queue-type'], ['material_requests', 'request_type_id', 'material-request-type'], ['material_requests', 'priority_id', 'request-priority'],
            ['material_requests', 'location_current_id', 'material-location'], ['material_requests', 'status_id', 'material-request-status'], ['material_requests', 'return_condition_id', 'return-condition'],
            ['custody_handoffs', 'handoff_type_id', 'custody-handoff-type'], ['custody_handoffs', 'condition_id', 'container-condition'], ['walk_in_visitors', 'id_type_id', 'identity-document-type'],
            ['reading_room_equipment', 'equipment_type_id', 'equipment-type'], ['reading_room_equipment', 'condition_id', 'equipment-condition'],
            ['equipment_bookings', 'status_id', 'equipment-booking-status'], ['equipment_bookings', 'condition_on_return_id', 'return-condition'],
        ] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }

        foreach (Institution::all() as $institution) {
            DB::transaction(fn () => app(RoleService::class)->grantKind($institution, 'institution', ['manage-reading-room']));
        }
    }

    public function down(): void
    {
        foreach (['equipment_maintenance', 'equipment_bookings', 'reading_room_equipment', 'walk_in_visitors', 'custody_handoffs', 'material_request_messages',
            'material_request_status_changes', 'material_requests', 'retrieval_schedules', 'request_queues', 'seat_assignments', 'reading_room_bookings', 'reading_room_seats',
            'reading_rooms', 'researcher_verifications', 'researchers', 'researcher_types', 'research_files'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
