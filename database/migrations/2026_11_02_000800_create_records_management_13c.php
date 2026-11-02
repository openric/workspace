<?php

// OpenRiC Workspace - records management beyond retention and disposal (roadmap phase 13c, ADR 0015): the file plan
// and its imports, a record's place in it, captured e-mail, classification rules and what they classified, record
// declaration, vital records and compliance assessments, after Heratio's ahg-records-manage: rm_fileplan_node,
// rm_fileplan_import_session, rm_email_capture, rm_classification_rule, rm_classification_log, record_declaration,
// vital_record and rm_compliance_assessment. Heratio found a node's records by disposal class or identifier prefix;
// here a record is placed in one node (Dr Johan Pieterse, 2 November 2026).
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

    private const VOCABULARIES = ['file-plan-node-type', 'file-plan-status', 'file-plan-import-source', 'file-plan-import-status', 'email-capture-source', 'email-capture-status',
        'classification-rule-type', 'classification-apply-on', 'declaration-status', 'compliance-framework', 'assessment-status'];

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
        $record = fn (Blueprint $table, bool $unique = true) => $unique
            ? $table->foreignUuid('record_resource_id')->unique()->constrained('record_resources')->cascadeOnDelete()
            : $table->foreignUuid('record_resource_id')->nullable()->constrained('record_resources')->nullOnDelete();

        Schema::create('file_plan_imports', function (Blueprint $table) use ($concept, $who, $institution) {
            $table->uuid('id')->primary();
            $institution($table);
            $concept($table, 'source_id');
            $table->text('source_filename')->nullable();
            $table->text('department')->nullable();
            $table->text('agency_code')->nullable();
            $concept($table, 'status_id');
            $table->integer('total_nodes')->default(0);
            $table->integer('imported_nodes')->default(0);
            $table->integer('linked_records')->default(0);
            $table->jsonb('errors')->nullable();
            $table->jsonb('column_mapping')->nullable();
            $who($table, 'imported_by_id');
            $table->timestampTz('created_at');
            $table->timestampTz('completed_at')->nullable();
        });

        // A node may be one of the institution's functions or activities; its records are then exported as documenting it.
        Schema::create('file_plan_nodes', function (Blueprint $table) use ($concept, $who, $institution) {
            $table->uuid('id')->primary();
            $institution($table);
            $table->uuid('parent_id')->nullable();
            $table->foreignUuid('activity_id')->nullable()->constrained('activities')->nullOnDelete();
            $concept($table, 'node_type_id');
            $table->text('code');
            $table->text('title');
            $table->text('description')->nullable();
            $table->foreignUuid('disposal_class_id')->nullable()->constrained('disposal_classes')->nullOnDelete();
            // As written in an imported file plan, where no disposal class is given.
            $table->text('retention_period')->nullable();
            $concept($table, 'disposal_action_id', true);
            $concept($table, 'status_id');
            $table->text('source_department')->nullable();
            $table->text('source_agency_code')->nullable();
            $table->foreignUuid('import_id')->nullable()->constrained('file_plan_imports')->nullOnDelete();
            $table->integer('position')->default(0);
            $who($table, 'created_by_id');
            $table->timestampsTz();
            $table->unique(['institution_id', 'code']);
        });
        Schema::table('file_plan_nodes', fn (Blueprint $table) => $table->foreign('parent_id')->references('id')->on('file_plan_nodes')->restrictOnDelete());
        DB::statement('ALTER TABLE file_plan_nodes ADD CONSTRAINT file_plan_nodes_not_own_parent CHECK (parent_id IS NULL OR parent_id <> id)');

        Schema::create('classification_rules', function (Blueprint $table) use ($concept, $who, $institution) {
            $table->uuid('id')->primary();
            $institution($table);
            $table->text('name');
            $table->text('description')->nullable();
            $concept($table, 'rule_type_id');
            $table->text('match_pattern');
            $table->foreignUuid('file_plan_node_id')->constrained('file_plan_nodes')->cascadeOnDelete();
            $table->foreignUuid('disposal_class_id')->nullable()->constrained('disposal_classes')->nullOnDelete();
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $concept($table, 'apply_on_id');
            $who($table, 'created_by_id');
            $table->timestampsTz();
        });

        // A record's one place in the file plan.
        Schema::create('file_plan_placements', function (Blueprint $table) use ($who, $record) {
            $table->uuid('id')->primary();
            $record($table);
            $table->foreignUuid('file_plan_node_id')->constrained('file_plan_nodes')->restrictOnDelete();
            $table->foreignUuid('rule_id')->nullable()->constrained('classification_rules')->nullOnDelete();
            $who($table, 'placed_by_id');
            $table->timestampTz('placed_at');
        });

        Schema::create('email_captures', function (Blueprint $table) use ($concept, $who, $institution, $record) {
            $table->uuid('id')->primary();
            $institution($table);
            $table->text('message_id');
            $table->text('from_address');
            $table->text('to_addresses')->nullable();
            $table->text('cc_addresses')->nullable();
            $table->text('subject')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->text('body_text')->nullable();
            $table->text('body_html')->nullable();
            $table->integer('attachment_count')->default(0);
            // [{name, mime_type, size_bytes, sha256}] of the attachments, which stay inside the kept original.
            $table->jsonb('attachments')->nullable();
            // The file as uploaded, kept unaltered in the object store.
            $table->text('original_name');
            $table->text('storage_path');
            $table->char('sha256', 64);
            $table->bigInteger('size_bytes');
            $record($table, false);
            $table->foreignUuid('file_plan_node_id')->nullable()->constrained('file_plan_nodes')->nullOnDelete();
            $table->foreignUuid('disposal_class_id')->nullable()->constrained('disposal_classes')->nullOnDelete();
            $concept($table, 'source_id');
            $concept($table, 'status_id');
            $who($table, 'captured_by_id');
            $table->timestampsTz();
            $table->unique(['institution_id', 'message_id']);
        });

        Schema::create('classification_log', function (Blueprint $table) use ($record) {
            $table->uuid('id')->primary();
            $record($table, false);
            $table->foreignUuid('email_capture_id')->nullable()->constrained('email_captures')->nullOnDelete();
            $table->foreignUuid('rule_id')->constrained('classification_rules')->cascadeOnDelete();
            $table->foreignUuid('file_plan_node_id')->constrained('file_plan_nodes')->cascadeOnDelete();
            $table->text('match_detail')->nullable();
            $table->timestampTz('classified_at');
        });

        Schema::create('record_declarations', function (Blueprint $table) use ($concept, $who, $record) {
            $table->uuid('id')->primary();
            $record($table);
            $concept($table, 'status_id');
            $who($table, 'requested_by_id');
            $table->timestampTz('requested_at');
            $who($table, 'declared_by_id');
            $table->timestampTz('declared_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });

        Schema::create('vital_records', function (Blueprint $table) use ($who, $record) {
            $table->uuid('id')->primary();
            $record($table);
            $table->text('reason');
            $table->integer('review_cycle_days')->default(365);
            $table->date('next_review_on');
            $table->timestampTz('last_reviewed_at')->nullable();
            $who($table, 'last_reviewed_by_id');
            $table->boolean('is_active')->default(true);
            $who($table, 'created_by_id');
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE vital_records ADD CONSTRAINT vital_records_cycle_check CHECK (review_cycle_days BETWEEN 1 AND 3650)');

        Schema::create('compliance_assessments', function (Blueprint $table) use ($concept, $who, $institution) {
            $table->uuid('id')->primary();
            $institution($table);
            $concept($table, 'framework_id');
            $table->text('reference');
            $table->text('title');
            $table->text('scope')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('score_total', 6, 2)->nullable();
            $table->decimal('score_max', 6, 2)->nullable();
            $table->jsonb('findings')->nullable();
            $table->jsonb('recommendations')->nullable();
            $concept($table, 'status_id');
            $who($table, 'assessed_by_id');
            $table->timestampTz('assessed_at');
            $table->text('signed_off_by')->nullable();
            $table->timestampTz('signed_off_at')->nullable();
            $table->timestampsTz();
            $table->unique(['institution_id', 'reference']);
        });

        foreach ([['file_plan_imports', 'source_id', 'file-plan-import-source'], ['file_plan_imports', 'status_id', 'file-plan-import-status'],
            ['file_plan_nodes', 'node_type_id', 'file-plan-node-type'], ['file_plan_nodes', 'status_id', 'file-plan-status'], ['file_plan_nodes', 'disposal_action_id', 'disposal-action-type'],
            ['classification_rules', 'rule_type_id', 'classification-rule-type'], ['classification_rules', 'apply_on_id', 'classification-apply-on'],
            ['email_captures', 'source_id', 'email-capture-source'], ['email_captures', 'status_id', 'email-capture-status'], ['record_declarations', 'status_id', 'declaration-status'],
            ['compliance_assessments', 'framework_id', 'compliance-framework'], ['compliance_assessments', 'status_id', 'assessment-status']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }
    }

    public function down(): void
    {
        foreach (['compliance_assessments', 'vital_records', 'record_declarations', 'classification_log', 'email_captures', 'file_plan_placements', 'classification_rules',
            'file_plan_nodes', 'file_plan_imports'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
