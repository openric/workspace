<?php

// OpenRiC Workspace - the privacy registers of POPIA governance (roadmap phase 18a, ADR 0016), after Heratio's
// ahg-privacy: privacy_jurisdiction, privacy_officer, privacy_processing_activity, ahg_dpia, privacy_consent_record,
// privacy_dsar, privacy_paia_request, privacy_complaint, privacy_breach and privacy_breach_notification, and the
// compliance control catalogue (ahg_compliance_control, ahg_compliance_mapping). Every status, type, basis and ground
// is a vocabulary row. Jurisdictions and controls are reference data, seeded from Heratio's.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Access\RoleService;
use App\Models\Institution;
use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const VOCAB = 'https://workspace.openric.org/vocab/';

    private const VOCABULARIES = ['privacy-request-type', 'privacy-request-status', 'lawful-basis', 'processing-register-status', 'breach-type', 'breach-severity', 'breach-status',
        'notification-recipient', 'notification-method', 'complaint-type', 'paia-section', 'paia-access-form', 'paia-refusal-ground', 'dpia-status', 'permission'];

    public function up(): void
    {
        foreach (self::VOCABULARIES as $name) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$name.ttl")));
        }

        Schema::create('privacy_jurisdictions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('code')->unique();
            $table->text('name');
            $table->text('full_name');
            $table->text('country');
            $table->text('regulator');
            $table->text('regulator_url')->nullable();
            // Days to answer a data subject request; hours to tell the regulator of a compromise (none: as soon as reasonably possible).
            $table->integer('request_days');
            $table->integer('breach_notice_hours')->nullable();
            $table->date('effective_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('position')->default(0);
            $table->timestampsTz();
        });
        $concept = fn (Blueprint $table, string $column, bool $nullable = false) => $nullable ? $table->foreignUuid($column)->nullable()->constrained('concepts')->restrictOnDelete()
            : $table->foreignUuid($column)->constrained('concepts')->restrictOnDelete();
        $user = fn (Blueprint $table, string $column) => $table->foreignUuid($column)->nullable()->constrained('users')->nullOnDelete();

        Schema::create('privacy_officers', function (Blueprint $table) use ($user) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $user($table, 'user_id');
            $table->text('name');
            $table->text('title')->nullable();
            $table->text('email')->nullable();
            $table->text('phone')->nullable();
            $table->foreignUuid('jurisdiction_id')->constrained('privacy_jurisdictions')->restrictOnDelete();
            // The registration with the Information Regulator (POPIA s.55, PAIA s.17).
            $table->text('registration_number')->nullable();
            $table->boolean('is_deputy')->default(false);
            $table->date('appointed_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
        Schema::create('processing_activities', function (Blueprint $table) use ($concept, $user) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('name');
            $table->text('description')->nullable();
            $table->foreignUuid('jurisdiction_id')->constrained('privacy_jurisdictions')->restrictOnDelete();
            $table->text('purpose');
            $concept($table, 'lawful_basis_id');
            $table->text('data_categories')->nullable();
            $table->text('data_subjects')->nullable();
            $table->text('recipients')->nullable();
            $table->text('transfers')->nullable();
            $table->text('retention_period')->nullable();
            $table->text('security_measures')->nullable();
            // Special personal information (POPIA s.26) and children's (s.34) need a ground of their own.
            $table->boolean('special_information')->default(false);
            $table->boolean('children')->default(false);
            $table->boolean('impact_assessment_required')->default(false);
            $concept($table, 'status_id');
            $table->text('owner')->nullable();
            $table->text('department')->nullable();
            $table->foreignUuid('officer_id')->nullable()->constrained('privacy_officers')->nullOnDelete();
            $table->date('next_review_on')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $user($table, 'submitted_by_id');
            $table->timestampTz('decided_at')->nullable();
            $user($table, 'decided_by_id');
            $table->text('rejection_reason')->nullable();
            $user($table, 'created_by_id');
            $table->timestampsTz();
        });
        Schema::create('impact_assessments', function (Blueprint $table) use ($concept, $user) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('processing_activity_id')->nullable()->constrained('processing_activities')->nullOnDelete();
            $table->text('name');
            $table->text('description')->nullable();
            $table->text('necessity')->nullable();
            $table->text('risks')->nullable();
            $table->text('measures')->nullable();
            $table->text('residual_risks')->nullable();
            $table->text('officer_opinion')->nullable();
            $table->date('officer_consulted_on')->nullable();
            $concept($table, 'status_id');
            $user($table, 'signed_off_by_id');
            $table->timestampTz('signed_off_at')->nullable();
            $user($table, 'created_by_id');
            $table->timestampsTz();
        });
        Schema::create('consent_records', function (Blueprint $table) use ($user) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('data_subject');
            $table->text('contact')->nullable();
            $table->text('purpose');
            $table->foreignUuid('processing_activity_id')->nullable()->constrained('processing_activities')->nullOnDelete();
            $table->timestampTz('given_at');
            $table->timestampTz('withdrawn_at')->nullable();
            $table->text('source')->nullable();
            $table->text('note')->nullable();
            $user($table, 'recorded_by_id');
            $table->timestampsTz();
        });
        Schema::create('privacy_requests', function (Blueprint $table) use ($concept, $user) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('reference')->unique();
            $table->foreignUuid('jurisdiction_id')->constrained('privacy_jurisdictions')->restrictOnDelete();
            $concept($table, 'type_id');
            $table->text('requester_name');
            $table->text('requester_email')->nullable();
            $table->text('requester_phone')->nullable();
            $table->text('requester_id_type')->nullable();
            $table->text('requester_id_number')->nullable();
            $table->text('requester_address')->nullable();
            $table->text('details')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $user($table, 'verified_by_id');
            $concept($table, 'status_id');
            $table->date('received_on');
            $table->date('due_on');
            $table->date('completed_on')->nullable();
            $user($table, 'assigned_to_id');
            $table->text('outcome')->nullable();
            $table->text('refusal_reason')->nullable();
            $table->decimal('fee', 10, 2)->nullable();
            $table->boolean('fee_paid')->default(false);
            $user($table, 'created_by_id');
            $table->timestampsTz();
        });
        Schema::create('paia_requests', function (Blueprint $table) use ($concept, $user) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('reference')->unique();
            $concept($table, 'kind_id');
            $table->text('requester_name');
            $table->text('requester_email')->nullable();
            $table->text('requester_phone')->nullable();
            $table->text('requester_id_number')->nullable();
            $table->text('requester_address')->nullable();
            $table->text('record_description');
            $concept($table, 'access_form_id', true);
            $concept($table, 'status_id');
            $concept($table, 'refusal_ground_id', true);
            $table->text('outcome')->nullable();
            $table->decimal('fee_request', 10, 2)->nullable();
            $table->decimal('fee_deposit', 10, 2)->nullable();
            $table->decimal('fee_access', 10, 2)->nullable();
            $table->boolean('fee_paid')->default(false);
            $table->date('received_on');
            $table->date('due_on');
            $table->date('completed_on')->nullable();
            $user($table, 'assigned_to_id');
            $user($table, 'created_by_id');
            $table->timestampsTz();
        });
        Schema::create('privacy_complaints', function (Blueprint $table) use ($concept, $user) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('reference')->unique();
            $table->foreignUuid('jurisdiction_id')->constrained('privacy_jurisdictions')->restrictOnDelete();
            $table->text('complainant_name');
            $table->text('complainant_email')->nullable();
            $table->text('complainant_phone')->nullable();
            $concept($table, 'type_id');
            $table->text('description');
            $table->date('incident_on')->nullable();
            $concept($table, 'status_id');
            $user($table, 'assigned_to_id');
            $table->text('resolution')->nullable();
            $table->date('received_on');
            $table->date('resolved_on')->nullable();
            $user($table, 'created_by_id');
            $table->timestampsTz();
        });
        Schema::create('security_compromises', function (Blueprint $table) use ($concept, $user) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('reference')->unique();
            $table->foreignUuid('jurisdiction_id')->constrained('privacy_jurisdictions')->restrictOnDelete();
            $concept($table, 'type_id');
            $concept($table, 'severity_id');
            $concept($table, 'status_id');
            $table->text('description');
            $table->timestampTz('detected_at');
            $table->timestampTz('occurred_at')->nullable();
            $table->timestampTz('contained_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->integer('subjects_affected')->nullable();
            $table->text('categories_affected')->nullable();
            $table->text('risk')->nullable();
            // POPIA s.22: unless the identity of the affected cannot be established, they and the Regulator are told.
            $table->boolean('notification_required')->default(true);
            $user($table, 'assigned_to_id');
            $user($table, 'created_by_id');
            $table->timestampsTz();
        });
        Schema::create('compromise_notices', function (Blueprint $table) use ($concept, $user) {
            $table->uuid('id')->primary();
            $table->foreignUuid('security_compromise_id')->constrained('security_compromises')->cascadeOnDelete();
            $concept($table, 'recipient_id');
            $concept($table, 'method_id');
            $table->text('recipient_name')->nullable();
            $table->timestampTz('sent_at');
            $table->timestampTz('acknowledged_at')->nullable();
            $table->text('content')->nullable();
            $user($table, 'created_by_id');
            $table->timestampsTz();
        });
        Schema::create('compliance_controls', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('code')->unique();
            $table->text('name');
            $table->text('category');
            $table->text('objective');
            $table->text('recommended_config')->nullable();
            $table->text('standards')->nullable();
            $table->jsonb('mappings')->default('[]');
            $table->integer('position')->default(0);
            $table->timestampsTz();
        });

        foreach ([['processing_activities', 'lawful_basis_id', 'lawful-basis'], ['processing_activities', 'status_id', 'processing-register-status'],
            ['impact_assessments', 'status_id', 'dpia-status'], ['privacy_requests', 'type_id', 'privacy-request-type'], ['privacy_requests', 'status_id', 'privacy-request-status'],
            ['paia_requests', 'kind_id', 'paia-section'], ['paia_requests', 'access_form_id', 'paia-access-form'], ['paia_requests', 'status_id', 'privacy-request-status'],
            ['paia_requests', 'refusal_ground_id', 'paia-refusal-ground'], ['privacy_complaints', 'type_id', 'complaint-type'], ['privacy_complaints', 'status_id', 'privacy-request-status'],
            ['security_compromises', 'type_id', 'breach-type'], ['security_compromises', 'severity_id', 'breach-severity'], ['security_compromises', 'status_id', 'breach-status'],
            ['compromise_notices', 'recipient_id', 'notification-recipient'], ['compromise_notices', 'method_id', 'notification-method']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }
        DB::statement('ALTER TABLE privacy_requests ADD CONSTRAINT privacy_requests_dates CHECK (due_on >= received_on)');
        DB::statement('ALTER TABLE paia_requests ADD CONSTRAINT paia_requests_dates CHECK (due_on >= received_on)');

        $now = now();
        foreach (json_decode(file_get_contents(resource_path('privacy/jurisdictions.json')), true) as $j) {
            DB::table('privacy_jurisdictions')->insert([...$j, 'id' => (string) Str::uuid7(), 'created_at' => $now, 'updated_at' => $now]);
        }
        $catalogue = json_decode(file_get_contents(resource_path('privacy/compliance-controls.json')), true);
        foreach ($catalogue['controls'] as $c) {
            DB::table('compliance_controls')->insert(['id' => (string) Str::uuid7(), 'code' => $c['control_id'], 'name' => $c['control_name'], 'category' => $c['category'],
                'objective' => $c['objective'], 'recommended_config' => $c['recommended_config'], 'standards' => $c['standards_refs'], 'position' => $c['sort_order'],
                'mappings' => json_encode(array_values(array_map(fn ($m) => array_diff_key($m, ['control_id' => 0]), array_filter($catalogue['mappings'], fn ($m) => $m['control_id'] === $c['control_id'])))),
                'created_at' => $now, 'updated_at' => $now]);
        }
        foreach (Institution::all() as $institution) {
            DB::transaction(fn () => app(RoleService::class)->grantKind($institution, 'institution', ['manage-privacy']));
        }
    }

    public function down(): void
    {
        foreach (['compliance_controls', 'compromise_notices', 'security_compromises', 'privacy_complaints', 'paia_requests', 'privacy_requests', 'consent_records',
            'impact_assessments', 'processing_activities', 'privacy_officers', 'privacy_jurisdictions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
