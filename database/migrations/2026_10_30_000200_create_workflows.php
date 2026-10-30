<?php

// OpenRiC Workspace - workflows, their steps, the tasks that move records through them and the history of what was
// done, after Heratio's ahg-workflow (ahg_workflow, ahg_workflow_step, ahg_workflow_task, ahg_workflow_history) and
// PSIS's ahgWorkflowPlugin (roadmap phase 10a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A workflow is the institution's: everywhere (its default), a repository's or a collection's (a unit of description
// and everything inside it). Its steps run in order; a task is one step for one record, claimed from the pool or
// assigned, and decided. Every list is a vocabulary; queues and service levels come in phase 10b.

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

    public function up(): void
    {
        foreach (config('workspace.bundled_vocabularies') as $file) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$file")));
        }

        Schema::create('workflows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->text('name');
            $table->text('description')->nullable();
            $table->foreignUuid('scope_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('scope_repository_id')->nullable()->constrained('repositories')->cascadeOnDelete();
            $table->foreignUuid('scope_record_id')->nullable()->constrained('record_resources')->cascadeOnDelete();
            $table->foreignUuid('trigger_id')->constrained('concepts')->restrictOnDelete();
            // The kind of entity it moves (App\Relations\EntityKinds): record, agent, repository or accession.
            $table->string('applies_to', 30)->default('record');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->boolean('require_all_steps')->default(true);
            $table->boolean('allow_parallel')->default(false);
            $table->integer('auto_archive_days')->nullable();
            $table->boolean('notification_enabled')->default(true);
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workflow_id')->constrained('workflows')->cascadeOnDelete();
            $table->text('name');
            $table->text('description')->nullable();
            $table->integer('position');
            $table->foreignUuid('step_type_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('action_id')->constrained('concepts')->restrictOnDelete();
            // Who may take it: a role, roles or named accounts; anyone who may change the record when none is given.
            $table->foreignUuid('required_role_id')->nullable()->constrained('roles')->nullOnDelete();
            // Kept for Heratio's security clearance, which comes with phase 18; not yet applied.
            $table->smallInteger('required_clearance_level')->nullable();
            $table->jsonb('allowed_role_ids')->nullable();
            $table->jsonb('allowed_user_ids')->nullable();
            $table->boolean('pool_enabled')->default(true);
            $table->foreignUuid('auto_assign_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('escalation_days')->nullable();
            $table->foreignUuid('escalation_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notification_template')->nullable();
            $table->text('instructions')->nullable();
            // Labels, one per item, ticked on each task.
            $table->jsonb('checklist')->nullable();
            $table->boolean('is_optional')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
        Schema::create('workflow_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('workflow_id')->constrained('workflows')->cascadeOnDelete();
            $table->foreignUuid('step_id')->constrained('workflow_steps')->cascadeOnDelete();
            $table->text('subject_table');
            $table->uuid('subject_id');
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('priority_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('submitted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('claimed_at')->nullable();
            $table->date('due_date')->nullable();
            $table->foreignUuid('decision_id')->constrained('concepts')->restrictOnDelete();
            $table->text('decision_comment')->nullable();
            $table->timestampTz('decision_at')->nullable();
            $table->foreignUuid('decision_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('checklist_completed')->nullable();
            $table->jsonb('metadata')->nullable();
            // The task of the step before, when a step's approval created it.
            $table->uuid('previous_task_id')->nullable();
            $table->integer('retry_count')->default(0);
            $table->timestampTz('escalated_at')->nullable();
            $table->timestampsTz();
            $table->index(['subject_table', 'subject_id']);
            $table->index(['institution_id', 'assigned_to_id']);
            $table->index(['institution_id', 'status_id']);
        });
        Schema::table('workflow_tasks', fn (Blueprint $table) => $table->foreign('previous_task_id')->references('id')->on('workflow_tasks')->nullOnDelete());
        Schema::create('workflow_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('task_id')->nullable()->constrained('workflow_tasks')->nullOnDelete();
            $table->foreignUuid('workflow_id')->constrained('workflows')->cascadeOnDelete();
            $table->foreignUuid('step_id')->nullable()->constrained('workflow_steps')->nullOnDelete();
            $table->text('subject_table');
            $table->uuid('subject_id');
            $table->foreignUuid('action_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('from_status_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('to_status_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('performed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('performed_at')->useCurrent();
            $table->text('comment')->nullable();
            $table->jsonb('metadata')->nullable();
            // Groups the events of one bulk operation.
            $table->uuid('correlation_id')->nullable();
            $table->index(['subject_table', 'subject_id']);
            $table->index(['institution_id', 'performed_at']);
            $table->index('correlation_id');
        });
        DB::statement("ALTER TABLE workflows ADD CONSTRAINT workflows_values CHECK (btrim(name) <> '' AND applies_to IN ('record', 'agent', 'repository', 'accession') AND (auto_archive_days IS NULL OR auto_archive_days > 0))");
        DB::statement('CREATE UNIQUE INDEX workflows_one_default ON workflows (institution_id, applies_to) WHERE is_default');
        DB::statement("ALTER TABLE workflow_steps ADD CONSTRAINT workflow_steps_values CHECK (btrim(name) <> '' AND position > 0 AND (escalation_days IS NULL OR escalation_days > 0) AND (required_clearance_level IS NULL OR required_clearance_level BETWEEN 0 AND 9))");
        DB::statement("ALTER TABLE workflow_tasks ADD CONSTRAINT workflow_tasks_subject CHECK (subject_table IN ('record_resources', 'agents', 'repositories', 'accessions') AND retry_count >= 0)");
        DB::statement("ALTER TABLE workflow_history ADD CONSTRAINT workflow_history_subject CHECK (subject_table IN ('record_resources', 'agents', 'repositories', 'accessions'))");

        foreach ([['workflows', 'scope_id', 'workflow-scope'], ['workflows', 'trigger_id', 'workflow-trigger'], ['workflow_steps', 'step_type_id', 'workflow-step-type'],
            ['workflow_steps', 'action_id', 'workflow-action'], ['workflow_tasks', 'status_id', 'workflow-task-status'], ['workflow_tasks', 'priority_id', 'workflow-priority'],
            ['workflow_tasks', 'decision_id', 'workflow-decision'], ['workflow_history', 'action_id', 'workflow-history-action'],
            ['workflow_history', 'from_status_id', 'workflow-task-status'], ['workflow_history', 'to_status_id', 'workflow-task-status']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }

        foreach (Institution::all() as $institution) {
            DB::transaction(fn () => app(RoleService::class)->grantKind($institution, 'institution', ['manage-workflows']));
        }
    }

    public function down(): void
    {
        foreach (['workflow_history', 'workflow_tasks', 'workflow_steps', 'workflows'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
