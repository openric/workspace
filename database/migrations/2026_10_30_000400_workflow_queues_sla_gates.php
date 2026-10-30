<?php

// OpenRiC Workspace - work queues, service level policies, publish gates and the requirement of an approved workflow
// before publishing, after Heratio's ahg-workflow (ahg_workflow_queue, ahg_workflow_sla_policy, ahg_publish_gate_rule,
// ahg_publish_gate_result, workflow_required_for_publish) and PSIS's ahgWorkflowPlugin (roadmap phase 10b; ADR 0011).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Queues, policies and gate rules are the institution's. Heratio's queues and PSIS's policies are given to each
// institution when it first opens them; Heratio's gate rules too, switched off, so publishing is as it was until an
// administrator switches one on.

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

        Schema::create('workflow_queues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->text('name');
            $table->string('slug', 100);
            $table->text('description')->nullable();
            $table->integer('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->integer('sla_days')->nullable();
            $table->char('color', 7)->nullable();
            $table->timestampsTz();
            $table->unique(['institution_id', 'slug']);
        });
        Schema::create('workflow_sla_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->text('name');
            // For a queue, a workflow, or (neither) the institution's default.
            $table->foreignUuid('queue_id')->nullable()->constrained('workflow_queues')->cascadeOnDelete();
            $table->foreignUuid('workflow_id')->nullable()->constrained('workflows')->cascadeOnDelete();
            $table->integer('warning_days')->default(3);
            $table->integer('due_days')->default(5);
            $table->integer('escalation_days')->default(7);
            $table->foreignUuid('escalation_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('escalation_action_id')->constrained('concepts')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
        Schema::table('workflow_tasks', fn (Blueprint $table) => $table->foreignUuid('queue_id')->nullable()->constrained('workflow_queues')->nullOnDelete());

        Schema::create('publish_gate_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->text('name');
            $table->foreignUuid('rule_type_id')->constrained('concepts')->restrictOnDelete();
            // Only for descriptions of a level or held by a repository, when given.
            $table->foreignUuid('level_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('repository_id')->nullable()->constrained('repositories')->cascadeOnDelete();
            // An element of the description (description-element notation), for the field rules.
            $table->string('field_name', 100)->nullable();
            $table->integer('min_length')->nullable();
            $table->text('error_message');
            $table->foreignUuid('severity_id')->constrained('concepts')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->integer('position')->default(0);
            $table->timestampsTz();
        });
        Schema::create('publish_gate_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->cascadeOnDelete();
            $table->foreignUuid('rule_id')->constrained('publish_gate_rules')->cascadeOnDelete();
            $table->string('status', 10);
            $table->text('details')->nullable();
            $table->timestampTz('evaluated_at')->useCurrent();
            $table->foreignUuid('evaluated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['record_resource_id', 'evaluated_at']);
        });
        DB::statement("ALTER TABLE workflow_queues ADD CONSTRAINT workflow_queues_values CHECK (btrim(name) <> '' AND slug ~ '^[a-z0-9-]+$' AND (sla_days IS NULL OR sla_days > 0) AND (color IS NULL OR color ~ '^#[0-9a-fA-F]{6}$'))");
        DB::statement('ALTER TABLE workflow_sla_policies ADD CONSTRAINT workflow_sla_policies_days CHECK (warning_days >= 0 AND due_days > 0 AND escalation_days > 0 AND warning_days <= due_days)');
        DB::statement('CREATE UNIQUE INDEX workflow_sla_policies_scope ON workflow_sla_policies (institution_id, queue_id, workflow_id) NULLS NOT DISTINCT');
        DB::statement("ALTER TABLE publish_gate_rules ADD CONSTRAINT publish_gate_rules_values CHECK (btrim(name) <> '' AND btrim(error_message) <> '' AND (min_length IS NULL OR min_length > 0))");
        DB::statement("ALTER TABLE publish_gate_results ADD CONSTRAINT publish_gate_results_status CHECK (status IN ('passed', 'failed', 'warning'))");

        // Heratio's workflow_required_for_publish.
        Schema::table('institutions', fn (Blueprint $table) => $table->boolean('workflow_required_for_publish')->default(false));

        foreach ([['workflow_sla_policies', 'escalation_action_id', 'workflow-escalation-action'], ['publish_gate_rules', 'rule_type_id', 'publish-gate-rule-type'],
            ['publish_gate_rules', 'severity_id', 'publish-gate-severity'], ['publish_gate_rules', 'level_id', 'level-of-description']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }
    }

    public function down(): void
    {
        Schema::table('institutions', fn (Blueprint $table) => $table->dropColumn('workflow_required_for_publish'));
        Schema::dropIfExists('publish_gate_results');
        Schema::dropIfExists('publish_gate_rules');
        Schema::table('workflow_tasks', fn (Blueprint $table) => $table->dropConstrainedForeignId('queue_id'));
        Schema::dropIfExists('workflow_sla_policies');
        Schema::dropIfExists('workflow_queues');
    }
};
