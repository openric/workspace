<?php

// OpenRiC Workspace - retention schedules and disposal, after Heratio's ahg-records-manage rm_retention_schedule,
// rm_disposal_class, rm_record_disposal_class, rm_disposal_action, rm_review_schedule and destruction_certificate
// (roadmap phase 13b, ADR 0015). Statuses, triggers, actions and decisions are vocabulary rows. A certificate keeps
// what was destroyed, by title, reference, IRI and each file's checksum, after the files are gone.
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

    public function up(): void
    {
        foreach (['retention-schedule-status.ttl', 'retention-trigger.ttl', 'disposal-action-type.ttl', 'disposal-status.ttl', 'review-decision.ttl', 'provenance-transfer-type.ttl'] as $file) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$file")));
        }

        Schema::create('retention_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('reference');
            $table->text('title');
            $table->text('description')->nullable();
            // The disposal authority it is issued under, such as a NARSSA standing disposal authority.
            $table->text('authority')->nullable();
            $table->text('jurisdiction')->nullable();
            $table->date('effective_on')->nullable();
            $table->date('review_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->integer('version')->default(1);
            $table->uuid('previous_id')->nullable();
            $table->text('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        Schema::table('retention_schedules', fn (Blueprint $table) => $table->foreign('previous_id')->references('id')->on('retention_schedules')->nullOnDelete());
        Schema::create('disposal_classes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('retention_schedule_id')->constrained('retention_schedules')->restrictOnDelete();
            $table->text('reference');
            $table->text('title');
            $table->text('description')->nullable();
            $table->integer('retention_years')->nullable();
            $table->integer('retention_months')->nullable();
            $table->foreignUuid('trigger_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('action_id')->constrained('concepts')->restrictOnDelete();
            $table->boolean('review_required')->default(true);
            $table->text('citation')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('position')->default(0);
            $table->timestampsTz();
            $table->unique(['retention_schedule_id', 'reference']);
        });
        Schema::create('record_disposal_classes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('record_resource_id')->unique()->constrained('record_resources')->cascadeOnDelete();
            $table->foreignUuid('disposal_class_id')->constrained('disposal_classes')->restrictOnDelete();
            $table->foreignUuid('assigned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('retention_starts_on')->nullable();
            $table->date('disposal_due_on')->nullable();
            $table->date('override_due_on')->nullable();
            $table->text('override_reason')->nullable();
            $table->timestampsTz();
        });
        Schema::create('destruction_certificates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('number')->unique();
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->restrictOnDelete();
            // What was destroyed, as it was: the files are gone, the description is no longer shown.
            $table->text('record_iri');
            $table->text('record_title');
            $table->text('reference_code')->nullable();
            $table->text('authority')->nullable();
            $table->text('disposal_class')->nullable();
            $table->text('method');
            $table->text('witness')->nullable();
            $table->jsonb('files');
            $table->char('content_hash', 64)->nullable();
            $table->text('approved_by');
            $table->text('executed_by');
            $table->timestampTz('destroyed_at');
            $table->timestampsTz();
        });
        Schema::create('disposal_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->restrictOnDelete();
            $table->foreignUuid('disposal_class_id')->nullable()->constrained('disposal_classes')->nullOnDelete();
            $table->foreignUuid('action_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('initiated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('initiated_at');
            $table->foreignUuid('recommended_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('recommended_at')->nullable();
            $table->foreignUuid('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignUuid('cleared_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('cleared_at')->nullable();
            $table->foreignUuid('executed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('executed_at')->nullable();
            $table->text('transfer_destination')->nullable();
            $table->foreignUuid('certificate_id')->nullable()->constrained('destruction_certificates')->restrictOnDelete();
            $table->jsonb('verification')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampsTz();
            $table->index(['institution_id', 'status_id']);
        });
        // Separation of duties, as Heratio's #1374: no one approves their own disposal, clears what they approved, or carries out what they approved or cleared.
        DB::statement('ALTER TABLE disposal_actions ADD CONSTRAINT disposal_actions_four_eyes CHECK (approved_by_id IS DISTINCT FROM initiated_by_id OR approved_by_id IS NULL)');
        DB::statement('ALTER TABLE disposal_actions ADD CONSTRAINT disposal_actions_clearer CHECK (cleared_by_id IS NULL OR cleared_by_id IS DISTINCT FROM approved_by_id)');
        DB::statement('ALTER TABLE disposal_actions ADD CONSTRAINT disposal_actions_executor CHECK (executed_by_id IS NULL OR (executed_by_id IS DISTINCT FROM approved_by_id AND executed_by_id IS DISTINCT FROM cleared_by_id))');
        Schema::create('retention_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->cascadeOnDelete();
            $table->foreignUuid('disposal_class_id')->nullable()->constrained('disposal_classes')->nullOnDelete();
            $table->date('due_on');
            $table->date('completed_on')->nullable();
            $table->foreignUuid('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('decision_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->text('decision_notes')->nullable();
            $table->date('next_due_on')->nullable();
            $table->foreignUuid('disposal_action_id')->nullable()->constrained('disposal_actions')->nullOnDelete();
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        foreach ([['retention_schedules', 'status_id', 'retention-schedule-status'], ['disposal_classes', 'trigger_id', 'retention-trigger'],
            ['disposal_classes', 'action_id', 'disposal-action-type'], ['disposal_actions', 'action_id', 'disposal-action-type'],
            ['disposal_actions', 'status_id', 'disposal-status'], ['retention_reviews', 'decision_id', 'review-decision']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }
        DB::statement("ALTER TABLE disposal_classes ADD CONSTRAINT disposal_classes_period CHECK (coalesce(retention_years, 0) >= 0 AND coalesce(retention_months, 0) >= 0 AND btrim(reference) <> '' AND btrim(title) <> '')");
    }

    public function down(): void
    {
        foreach (['retention_reviews', 'disposal_actions', 'destruction_certificates', 'record_disposal_classes', 'disposal_classes', 'retention_schedules'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
