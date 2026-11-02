<?php

// OpenRiC Workspace - Zimbabwe's packs (roadmap phase 18e, ADR 0016): the National Archives of Zimbabwe's closure
// periods and protected records (kept as the embargoes that enforce them), research permits, records schedules and
// transfers, after Heratio's ahg-naz (naz_closure_period, naz_protected_record, naz_research_permit,
// naz_records_schedule, naz_transfer); and the Cyber and Data Protection Act as a jurisdiction of the privacy
// registers, with its data controller licences and its Data Protection Officer's certification and form DP2, after
// Heratio's ahg-cdpa (cdpa_controller_license, cdpa_dpo). NMMZ goes to phase 21 (Dr Johan Pieterse, 2 November 2026).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const VOCAB = 'https://workspace.openric.org/vocab/';

    private const VOCABULARIES = ['naz-closure-type', 'naz-protection-type', 'naz-permit-type', 'naz-permit-status', 'naz-schedule-status', 'naz-records-class', 'naz-access-restriction',
        'naz-transfer-type', 'naz-transfer-status', 'cdpa-licence-tier', 'licence-status'];

    public function up(): void
    {
        foreach (self::VOCABULARIES as $name) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$name.ttl")));
        }
        $concept = fn (Blueprint $table, string $column, bool $nullable = false) => $nullable
            ? $table->foreignUuid($column)->nullable()->constrained('concepts')->restrictOnDelete()
            : $table->foreignUuid($column)->constrained('concepts')->restrictOnDelete();
        $who = fn (Blueprint $table, string $column) => $table->foreignUuid($column)->nullable()->constrained('users')->nullOnDelete();
        $institution = fn (Blueprint $table) => $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();

        // A closure period or a protection, each kept as the embargo that enforces it.
        Schema::create('naz_restrictions', function (Blueprint $table) use ($concept, $who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->cascadeOnDelete();
            $table->foreignUuid('embargo_id')->unique()->constrained('embargoes')->cascadeOnDelete();
            $table->string('kind', 12);
            $concept($table, 'closure_type_id', true);
            $concept($table, 'protection_type_id', true);
            $table->integer('years')->nullable();
            $table->text('authority_reference')->nullable();
            $who($table, 'created_by_id');
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE naz_restrictions ADD CONSTRAINT naz_restrictions_kind_check CHECK ((kind = 'closure' AND closure_type_id IS NOT NULL) OR (kind = 'protection' AND protection_type_id IS NOT NULL))");

        Schema::create('naz_research_permits', function (Blueprint $table) use ($concept, $who, $institution) {
            $table->uuid('id')->primary();
            $institution($table);
            $table->string('permit_number', 50);
            $table->foreignUuid('researcher_id')->constrained('researchers')->restrictOnDelete();
            $concept($table, 'permit_type_id');
            $table->text('research_topic');
            $table->text('research_purpose')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('fee_amount', 10, 2)->default(0);
            $table->char('fee_currency', 3)->default('USD');
            $table->boolean('fee_paid')->default(false);
            $table->text('fee_receipt')->nullable();
            $table->date('payment_date')->nullable();
            $who($table, 'approved_by_id');
            $table->date('approved_on')->nullable();
            $concept($table, 'status_id');
            $table->text('rejection_reason')->nullable();
            // The repositories it opens; none means all the permit type allows.
            $table->jsonb('repository_ids')->nullable();
            $table->text('restrictions')->nullable();
            $who($table, 'created_by_id');
            $table->timestampsTz();
            $table->unique(['institution_id', 'permit_number']);
        });
        DB::statement('ALTER TABLE naz_research_permits ADD CONSTRAINT naz_research_permits_period_check CHECK (end_date >= start_date)');

        Schema::create('naz_records_schedules', function (Blueprint $table) use ($concept, $who, $institution) {
            $table->uuid('id')->primary();
            $institution($table);
            $table->string('schedule_number', 50);
            $table->text('agency_name');
            $table->text('agency_code')->nullable();
            $table->text('record_series');
            $table->text('description')->nullable();
            $table->integer('retention_active_years');
            $table->integer('retention_semi_active_years')->default(0);
            $concept($table, 'disposal_action_id');
            $table->text('legal_authority')->nullable();
            $concept($table, 'records_class_id', true);
            $concept($table, 'access_restriction_id', true);
            $table->foreignUuid('disposal_class_id')->nullable()->constrained('disposal_classes')->nullOnDelete();
            $table->text('approved_by')->nullable();
            $table->date('approval_date')->nullable();
            $table->date('effective_date')->nullable();
            $table->date('review_date')->nullable();
            $concept($table, 'status_id');
            $table->text('notes')->nullable();
            $who($table, 'created_by_id');
            $table->timestampsTz();
            $table->unique(['institution_id', 'schedule_number']);
        });

        Schema::create('naz_transfers', function (Blueprint $table) use ($concept, $who, $institution) {
            $table->uuid('id')->primary();
            $institution($table);
            $table->string('transfer_number', 50);
            $table->text('transferring_agency');
            $table->text('agency_contact')->nullable();
            $table->text('agency_email')->nullable();
            $table->text('agency_phone')->nullable();
            $table->foreignUuid('schedule_id')->nullable()->constrained('naz_records_schedules')->nullOnDelete();
            $concept($table, 'transfer_type_id');
            $table->text('description')->nullable();
            $table->date('date_range_start')->nullable();
            $table->date('date_range_end')->nullable();
            $table->decimal('quantity_linear_metres', 10, 2)->nullable();
            $table->integer('quantity_boxes')->nullable();
            $table->integer('quantity_items')->nullable();
            $table->boolean('contains_restricted')->default(false);
            $table->text('restriction_details')->nullable();
            $table->text('accession_number')->nullable();
            $table->date('proposed_date')->nullable();
            $table->date('actual_date')->nullable();
            $who($table, 'received_by_id');
            $concept($table, 'status_id');
            $table->text('rejection_reason')->nullable();
            $table->text('location_assigned')->nullable();
            $table->text('notes')->nullable();
            $who($table, 'created_by_id');
            $table->timestampsTz();
            $table->unique(['institution_id', 'transfer_number']);
        });

        // The Cyber and Data Protection Act as a jurisdiction of the privacy registers.
        DB::table('privacy_jurisdictions')->insertOrIgnore(['id' => (string) Str::uuid7(), 'code' => 'cdpa', 'name' => 'CDPA', 'full_name' => 'Cyber and Data Protection Act [Chapter 12:07]',
            'country' => 'Zimbabwe', 'regulator' => 'Postal and Telecommunications Regulatory Authority of Zimbabwe (POTRAZ)', 'regulator_url' => 'https://www.potraz.gov.zw',
            'request_days' => 30, 'breach_notice_hours' => 72, 'effective_on' => '2021-12-03', 'is_active' => true, 'position' => 8, 'created_at' => now(), 'updated_at' => now()]);

        Schema::table('privacy_officers', function (Blueprint $table) {
            $table->text('qualifications')->nullable();
            $table->text('certificate_number')->nullable();
            $table->date('term_ends_on')->nullable();
            $table->date('form_dp2_on')->nullable();
            $table->text('form_dp2_reference')->nullable();
        });

        Schema::create('controller_licences', function (Blueprint $table) use ($concept, $who, $institution) {
            $table->uuid('id')->primary();
            $institution($table);
            $table->foreignUuid('jurisdiction_id')->constrained('privacy_jurisdictions')->restrictOnDelete();
            $table->text('reference')->nullable();
            $table->text('licence_number');
            $concept($table, 'tier_id');
            $table->text('organisation_name');
            $table->date('registered_on');
            $table->date('issued_on');
            $table->date('expires_on');
            $table->text('regulator_reference')->nullable();
            $table->integer('data_subjects_count')->nullable();
            $table->timestampTz('renewal_reminder_sent_at')->nullable();
            $concept($table, 'status_id');
            $table->text('notes')->nullable();
            $who($table, 'created_by_id');
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE controller_licences ADD CONSTRAINT controller_licences_period_check CHECK (expires_on >= issued_on)');

        foreach ([['naz_restrictions', 'closure_type_id', 'naz-closure-type'], ['naz_restrictions', 'protection_type_id', 'naz-protection-type'],
            ['naz_research_permits', 'permit_type_id', 'naz-permit-type'], ['naz_research_permits', 'status_id', 'naz-permit-status'],
            ['naz_records_schedules', 'disposal_action_id', 'disposal-action-type'], ['naz_records_schedules', 'records_class_id', 'naz-records-class'],
            ['naz_records_schedules', 'access_restriction_id', 'naz-access-restriction'], ['naz_records_schedules', 'status_id', 'naz-schedule-status'],
            ['naz_transfers', 'transfer_type_id', 'naz-transfer-type'], ['naz_transfers', 'status_id', 'naz-transfer-status'],
            ['controller_licences', 'tier_id', 'cdpa-licence-tier'], ['controller_licences', 'status_id', 'licence-status']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }
    }

    public function down(): void
    {
        foreach (['controller_licences', 'naz_transfers', 'naz_records_schedules', 'naz_research_permits', 'naz_restrictions'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('privacy_officers', fn (Blueprint $table) => $table->dropColumn(['qualifications', 'certificate_number', 'term_ends_on', 'form_dp2_on', 'form_dp2_reference']));
        DB::table('privacy_jurisdictions')->where('code', 'cdpa')->delete();
    }
};
