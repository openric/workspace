<?php

// OpenRiC Workspace - security clearance (roadmap phase 18c, ADR 0016): the institution's classification levels and
// compartments, accounts' clearances and their history, records' classifications with their declassification, and
// requests for, and grants of, access to a classified record above one's clearance, after Heratio's
// ahg-security-clearance: security_classification, security_compartment, user_security_clearance,
// user_compartment_access, security_clearance_history, object_security_classification, object_compartment_access,
// security_declassification_schedule, security_access_request and object_access_grant. Its two-factor sign-in goes
// with single sign-on in phase 12.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const VOCAB = 'https://workspace.openric.org/vocab/';

    private const VOCABULARIES = ['clearance-action', 'clearance-request-type', 'clearance-request-status', 'clearance-request-priority'];

    public function up(): void
    {
        foreach (self::VOCABULARIES as $name) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$name.ttl")));
        }
        $concept = fn (Blueprint $table, string $column) => $table->foreignUuid($column)->constrained('concepts')->restrictOnDelete();
        $who = fn (Blueprint $table, string $column) => $table->foreignUuid($column)->nullable()->constrained('users')->nullOnDelete();
        $institution = fn (Blueprint $table) => $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();

        // The institution's levels, 0 (public) and up; a higher level needs a higher clearance.
        Schema::create('security_levels', function (Blueprint $table) use ($institution) {
            $table->uuid('id')->primary();
            $institution($table);
            $table->string('code', 20);
            $table->smallInteger('level');
            $table->text('name');
            $table->text('description')->nullable();
            $table->string('colour', 20)->nullable();
            $table->boolean('requires_justification')->default(false);
            $table->boolean('requires_approval')->default(false);
            // Kept now, enforced with two-factor sign-in in phase 12.
            $table->boolean('requires_2fa')->default(false);
            $table->integer('max_session_hours')->nullable();
            $table->boolean('watermark_required')->default(false);
            $table->boolean('download_allowed')->default(true);
            $table->boolean('print_allowed')->default(true);
            $table->boolean('copy_allowed')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['institution_id', 'code']);
            $table->unique(['institution_id', 'level']);
        });
        DB::statement('ALTER TABLE security_levels ADD CONSTRAINT security_levels_level_check CHECK (level BETWEEN 0 AND 100)');

        Schema::create('security_compartments', function (Blueprint $table) use ($institution) {
            $table->uuid('id')->primary();
            $institution($table);
            $table->string('code', 50);
            $table->text('name');
            $table->text('description')->nullable();
            $table->foreignUuid('min_level_id')->constrained('security_levels')->restrictOnDelete();
            $table->boolean('requires_need_to_know')->default(true);
            $table->boolean('requires_briefing')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['institution_id', 'code']);
        });

        Schema::create('account_clearances', function (Blueprint $table) use ($who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('level_id')->constrained('security_levels')->restrictOnDelete();
            $who($table, 'granted_by_id');
            $table->timestampTz('granted_at');
            $table->timestampTz('expires_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });

        Schema::create('account_compartments', function (Blueprint $table) use ($who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('compartment_id')->constrained('security_compartments')->cascadeOnDelete();
            $who($table, 'granted_by_id');
            $table->timestampTz('granted_at');
            $table->timestampTz('briefed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->unique(['user_id', 'compartment_id']);
        });

        Schema::create('clearance_history', function (Blueprint $table) use ($concept, $who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('previous_level_id')->nullable()->constrained('security_levels')->nullOnDelete();
            $table->foreignUuid('new_level_id')->nullable()->constrained('security_levels')->nullOnDelete();
            $concept($table, 'action_id');
            $who($table, 'changed_by_id');
            $table->text('reason')->nullable();
            $table->timestampTz('created_at');
        });

        // A record's classification, and its children's unless they have their own.
        Schema::create('record_classifications', function (Blueprint $table) use ($who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('record_resource_id')->unique()->constrained('record_resources')->cascadeOnDelete();
            $table->foreignUuid('level_id')->constrained('security_levels')->restrictOnDelete();
            $who($table, 'classified_by_id');
            $table->timestampTz('classified_at');
            $table->date('review_on')->nullable();
            $table->date('declassify_on')->nullable();
            $table->foreignUuid('declassify_to_level_id')->nullable()->constrained('security_levels')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->text('handling_instructions')->nullable();
            $table->boolean('inherit_to_children')->default(true);
            $table->text('justification')->nullable();
            $table->timestampsTz();
        });

        Schema::create('record_compartments', function (Blueprint $table) use ($who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->cascadeOnDelete();
            $table->foreignUuid('compartment_id')->constrained('security_compartments')->cascadeOnDelete();
            $who($table, 'granted_by_id');
            $table->timestampTz('created_at');
            $table->unique(['record_resource_id', 'compartment_id']);
        });

        Schema::create('clearance_requests', function (Blueprint $table) use ($concept, $who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->cascadeOnDelete();
            $concept($table, 'request_type_id');
            $concept($table, 'priority_id');
            $table->text('justification');
            $concept($table, 'status_id');
            $who($table, 'reviewed_by_id');
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->integer('duration_hours')->nullable();
            $table->timestampsTz();
        });

        // Access to one classified record for one account, for a time, from an approved request.
        Schema::create('clearance_grants', function (Blueprint $table) use ($concept, $who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->cascadeOnDelete();
            $table->foreignUuid('request_id')->nullable()->constrained('clearance_requests')->nullOnDelete();
            $concept($table, 'request_type_id');
            $who($table, 'granted_by_id');
            $table->timestampTz('granted_at');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $who($table, 'revoked_by_id');
        });

        foreach ([['clearance_history', 'action_id', 'clearance-action'], ['clearance_requests', 'request_type_id', 'clearance-request-type'],
            ['clearance_requests', 'priority_id', 'clearance-request-priority'], ['clearance_requests', 'status_id', 'clearance-request-status'],
            ['clearance_grants', 'request_type_id', 'clearance-request-type']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }
    }

    public function down(): void
    {
        foreach (['clearance_grants', 'clearance_requests', 'record_compartments', 'record_classifications', 'clearance_history', 'account_compartments', 'account_clearances',
            'security_compartments', 'security_levels'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
