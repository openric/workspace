<?php

// OpenRiC Workspace - fixity on a schedule (roadmap phase 12a), after Heratio's ahg-integrity: schedules of what to
// check and how often, each run with its counts, a ledger of every check (a PREMIS fixity check event), and the files
// that failed, kept open until someone resolves them or they pass again.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHECKED = [['fixity_schedules', 'scope_id', 'fixity-scope'], ['fixity_schedules', 'frequency_id', 'fixity-frequency'],
        ['fixity_runs', 'status_id', 'fixity-run-status'], ['fixity_runs', 'trigger_id', 'fixity-trigger'], ['fixity_checks', 'outcome_id', 'fixity-outcome'],
        ['fixity_failures', 'outcome_id', 'fixity-outcome'], ['fixity_failures', 'status_id', 'fixity-failure-status']];

    public function up(): void
    {
        foreach (['fixity-frequency', 'fixity-scope', 'fixity-run-status', 'fixity-trigger', 'fixity-outcome', 'fixity-failure-status'] as $name) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$name.ttl")));
        }
        Schema::create('fixity_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->text('name');
            $table->foreignUuid('scope_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('repository_id')->nullable()->constrained('repositories')->cascadeOnDelete();
            $table->foreignUuid('record_resource_id')->nullable()->constrained('record_resources')->cascadeOnDelete();
            $table->foreignUuid('frequency_id')->constrained('concepts')->restrictOnDelete();
            // Only files not checked for this many days; 0 checks every file in scope each run.
            $table->integer('stale_days')->default(0);
            $table->integer('batch_size')->default(500);
            $table->integer('max_runtime_minutes')->default(60);
            $table->boolean('is_enabled')->default(true);
            $table->foreignUuid('notify_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('last_run_at')->nullable();
            $table->timestampTz('next_run_at')->nullable();
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        Schema::create('fixity_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('schedule_id')->nullable()->constrained('fixity_schedules')->nullOnDelete();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('trigger_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            foreach (['files_checked', 'files_passed', 'files_changed', 'files_missing'] as $column) {
                $table->integer($column)->default(0);
            }
            $table->bigInteger('bytes_checked')->default(0);
            $table->text('error_message')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->index(['institution_id', 'started_at']);
        });
        // No foreign key to the file: the check outlives a file erased by disposal, as Heratio's ledger does.
        Schema::create('fixity_checks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('run_id')->nullable()->constrained('fixity_runs')->nullOnDelete();
            $table->uuid('digital_file_id');
            $table->foreignUuid('outcome_id')->constrained('concepts')->restrictOnDelete();
            $table->char('expected_sha256', 64);
            $table->char('computed_sha256', 64)->nullable();
            $table->bigInteger('expected_size');
            $table->bigInteger('found_size')->nullable();
            $table->integer('duration_ms')->default(0);
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('checked_at');
            $table->index(['digital_file_id', 'checked_at']);
        });
        Schema::create('fixity_failures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->uuid('digital_file_id');
            $table->foreignUuid('outcome_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->integer('consecutive_failures')->default(1);
            $table->timestampTz('first_failed_at');
            $table->timestampTz('last_failed_at');
            $table->foreignUuid('last_run_id')->nullable()->constrained('fixity_runs')->nullOnDelete();
            $table->foreignUuid('acknowledged_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
            $table->index(['institution_id', 'digital_file_id']);
        });
        foreach (self::CHECKED as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', 'https://workspace.openric.org/vocab/{$vocabulary}/')");
        }
    }

    public function down(): void
    {
        foreach (['fixity_failures', 'fixity_checks', 'fixity_runs', 'fixity_schedules'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
