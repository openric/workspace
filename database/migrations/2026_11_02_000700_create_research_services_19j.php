<?php

// OpenRiC Workspace - research services, part 19j (roadmap phase 19, ADR 0017): lectures, training courses with their
// assessments and certificates, research quotas and the downloads they count, notices to researchers and how they
// want them, document and print templates, rights policies on records (ODRL) and the decisions taken under them, and
// the researcher's inbox, after Heratio's ahg-research: research_lecture, research_lecture_section,
// research_lecture_resource, training_course, training_module, training_assessment, training_enrolment,
// training_progress, training_certificate, research_quota_policy, research_download_log, research_notification,
// research_notification_preference, research_document_template, research_print_template, research_rights_policy,
// research_access_decision and research_inbox_item. Heratio's offline sync goes with the offline catalogue (phase 11c).
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

    private const VOCABULARIES = ['lecture-type', 'lecture-status', 'lecture-media-type', 'lecture-resource-type', 'course-status', 'enrolment-status', 'quota-scope', 'quota-period',
        'notification-type', 'digest-frequency', 'odrl-policy-type', 'odrl-action', 'document-type', 'print-template-type', 'inbox-kind', 'inbox-origin', 'inbox-status'];

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

        // A lecture is the institution's (user_id null), or a researcher's own.
        Schema::create('research_lectures', function (Blueprint $table) use ($concept, $who, $institution) {
            $table->uuid('id')->primary();
            $institution($table);
            $who($table, 'user_id');
            $concept($table, 'type_id');
            $table->text('title');
            $table->text('subtitle')->nullable();
            $table->text('summary')->nullable();
            $table->text('speaker_name')->nullable();
            $table->text('speaker_affiliation')->nullable();
            $table->timestampTz('scheduled_at')->nullable();
            $table->text('location')->nullable();
            $table->integer('duration_minutes')->nullable();
            $table->text('recording_url')->nullable();
            $table->text('slides_url')->nullable();
            $table->text('curriculum_ref')->nullable();
            $table->foreignUuid('cover_record_id')->nullable()->constrained('record_resources')->nullOnDelete();
            $concept($table, 'status_id');
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
        });

        Schema::create('research_lecture_sections', function (Blueprint $table) use ($concept) {
            $table->uuid('id')->primary();
            $table->foreignUuid('lecture_id')->constrained('research_lectures')->cascadeOnDelete();
            $table->text('heading')->nullable();
            // Markdown, rendered safely when shown.
            $table->text('body')->nullable();
            $table->text('media_url')->nullable();
            $concept($table, 'media_type_id', true);
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
        });

        Schema::create('research_lecture_resources', function (Blueprint $table) use ($concept) {
            $table->uuid('id')->primary();
            $table->foreignUuid('lecture_id')->constrained('research_lectures')->cascadeOnDelete();
            $table->text('label');
            $table->text('url')->nullable();
            $concept($table, 'resource_type_id');
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
        });

        Schema::create('training_courses', function (Blueprint $table) use ($concept, $who, $institution) {
            $table->uuid('id')->primary();
            $institution($table);
            $who($table, 'user_id');
            $table->text('title');
            $table->text('description')->nullable();
            $table->text('audience')->nullable();
            $table->string('language', 40)->nullable();
            $table->smallInteger('pass_mark')->default(80);
            $concept($table, 'status_id');
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE training_courses ADD CONSTRAINT training_courses_pass_mark_check CHECK (pass_mark BETWEEN 0 AND 100)');

        Schema::create('training_modules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('course_id')->constrained('training_courses')->cascadeOnDelete();
            $table->text('title');
            $table->foreignUuid('lecture_id')->nullable()->constrained('research_lectures')->nullOnDelete();
            $table->text('body')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
        });

        // The questions: [{question, options: [..], answer: index}].
        Schema::create('training_assessments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('course_id')->unique()->constrained('training_courses')->cascadeOnDelete();
            $table->text('title')->nullable();
            $table->smallInteger('pass_mark')->nullable();
            $table->jsonb('questions');
            $table->timestampsTz();
        });

        // A learner is an account of the institution, or someone named without one.
        Schema::create('training_enrolments', function (Blueprint $table) use ($concept) {
            $table->uuid('id')->primary();
            $table->foreignUuid('course_id')->constrained('training_courses')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->text('learner_name')->nullable();
            $table->text('learner_email')->nullable();
            $concept($table, 'status_id');
            $table->smallInteger('score')->nullable();
            $table->timestampTz('enrolled_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX training_enrolments_once ON training_enrolments (course_id, user_id) WHERE user_id IS NOT NULL');

        Schema::create('training_progress', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('enrolment_id')->constrained('training_enrolments')->cascadeOnDelete();
            $table->foreignUuid('module_id')->constrained('training_modules')->cascadeOnDelete();
            $table->timestampTz('completed_at');
            $table->unique(['enrolment_id', 'module_id']);
        });

        Schema::create('training_certificates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('enrolment_id')->unique()->constrained('training_enrolments')->cascadeOnDelete();
            $table->string('certificate_no', 40)->unique();
            $table->smallInteger('score');
            $table->timestampTz('issued_at');
        });

        // scope_key: the kind of researcher, the account or the project the quota is for; none for everyone.
        Schema::create('research_quota_policies', function (Blueprint $table) use ($concept, $institution) {
            $table->uuid('id')->primary();
            $institution($table);
            $concept($table, 'scope_id');
            $table->uuid('scope_key')->nullable();
            $concept($table, 'period_id');
            $table->integer('max_downloads')->nullable();
            $table->bigInteger('max_storage_bytes')->nullable();
            $table->smallInteger('soft_warn_pct')->default(80);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX research_quota_policies_once ON research_quota_policies (institution_id, scope_id, COALESCE(scope_key, \'00000000-0000-0000-0000-000000000000\'), period_id)');
        DB::statement('ALTER TABLE research_quota_policies ADD CONSTRAINT research_quota_policies_limits_check CHECK ((max_downloads IS NULL OR max_downloads >= 0) AND (max_storage_bytes IS NULL OR max_storage_bytes >= 0) AND soft_warn_pct BETWEEN 1 AND 100)');

        Schema::create('research_downloads', function (Blueprint $table) use ($institution) {
            $table->uuid('id')->primary();
            $institution($table);
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('project_id')->nullable()->constrained('research_projects')->nullOnDelete();
            $table->text('entity_table')->nullable();
            $table->uuid('entity_id')->nullable();
            $table->text('entity_title')->nullable();
            $table->timestampTz('created_at');
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('research_notifications', function (Blueprint $table) use ($concept) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $concept($table, 'type_id');
            $table->text('title');
            $table->text('message')->nullable();
            $table->text('link')->nullable();
            // Shown in the Workspace; and whether, and when, it went by e-mail.
            $table->boolean('in_app')->default(true);
            $table->boolean('email_due')->default(false);
            $table->timestampTz('emailed_at')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('created_at');
            $table->index(['user_id', 'read_at']);
        });

        Schema::create('research_notification_preferences', function (Blueprint $table) use ($concept) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $concept($table, 'type_id');
            $table->boolean('email_enabled')->default(true);
            $table->boolean('in_app_enabled')->default(true);
            $concept($table, 'digest_id');
            $table->timestampTz('last_digest_at')->nullable();
            $table->unique(['user_id', 'type_id']);
        });

        // fields: [{key, label, type (text, textarea, date), required}].
        Schema::create('research_document_templates', function (Blueprint $table) use ($concept, $who, $institution) {
            $table->uuid('id')->primary();
            $institution($table);
            $table->text('name');
            $concept($table, 'document_type_id');
            $table->text('description')->nullable();
            $table->jsonb('fields');
            $who($table, 'created_by_id');
            $table->timestampsTz();
        });

        Schema::create('research_documents', function (Blueprint $table) use ($who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('template_id')->constrained('research_document_templates')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('project_id')->nullable()->constrained('research_projects')->nullOnDelete();
            $table->text('title');
            $table->jsonb('values');
            $who($table, 'created_by_id');
            $table->timestampsTz();
        });

        // A print template's text holds {placeholders} only: it is escaped when printed, never run as markup.
        Schema::create('research_print_templates', function (Blueprint $table) use ($concept, $who, $institution) {
            $table->uuid('id')->primary();
            $institution($table);
            $table->text('name');
            $table->string('code', 50);
            $concept($table, 'template_type_id');
            $table->text('description')->nullable();
            $table->text('body');
            $table->string('page_size', 10)->default('A4');
            $table->string('orientation', 10)->default('portrait');
            $table->smallInteger('margin_mm')->default(10);
            $table->smallInteger('copies_default')->default(1);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $who($table, 'created_by_id');
            $table->timestampsTz();
            $table->unique(['institution_id', 'code']);
        });
        DB::statement("ALTER TABLE research_print_templates ADD CONSTRAINT research_print_templates_page_check CHECK (page_size IN ('A4', 'A5', 'A6', 'Letter') AND orientation IN ('portrait', 'landscape'))");

        // constraints: {account_ids, date_from, date_to, max_uses}.
        Schema::create('research_rights_policies', function (Blueprint $table) use ($concept, $who, $institution) {
            $table->uuid('id')->primary();
            $institution($table);
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->cascadeOnDelete();
            $concept($table, 'policy_type_id');
            $concept($table, 'action_id');
            $table->jsonb('constraints')->nullable();
            $table->text('note')->nullable();
            $who($table, 'created_by_id');
            $table->timestampsTz();
        });

        Schema::create('research_access_decisions', function (Blueprint $table) use ($concept) {
            $table->uuid('id')->primary();
            $table->foreignUuid('policy_id')->nullable()->constrained('research_rights_policies')->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->cascadeOnDelete();
            $concept($table, 'action_id');
            $table->boolean('permitted');
            $table->text('rationale');
            $table->timestampTz('evaluated_at');
        });

        Schema::create('research_inbox_items', function (Blueprint $table) use ($concept) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $concept($table, 'kind_id');
            $concept($table, 'origin_id');
            $concept($table, 'status_id');
            $table->text('title')->nullable();
            $table->text('body')->nullable();
            $table->text('source_url')->nullable();
            $table->foreignUuid('file_id')->nullable()->constrained('research_files')->nullOnDelete();
            $table->foreignUuid('project_id')->nullable()->constrained('research_projects')->nullOnDelete();
            $table->timestampTz('triaged_at')->nullable();
            $table->timestampsTz();
        });

        foreach ([['research_lectures', 'type_id', 'lecture-type'], ['research_lectures', 'status_id', 'lecture-status'], ['research_lecture_sections', 'media_type_id', 'lecture-media-type'],
            ['research_lecture_resources', 'resource_type_id', 'lecture-resource-type'], ['training_courses', 'status_id', 'course-status'], ['training_enrolments', 'status_id', 'enrolment-status'],
            ['research_quota_policies', 'scope_id', 'quota-scope'], ['research_quota_policies', 'period_id', 'quota-period'], ['research_notifications', 'type_id', 'notification-type'],
            ['research_notification_preferences', 'type_id', 'notification-type'], ['research_notification_preferences', 'digest_id', 'digest-frequency'],
            ['research_document_templates', 'document_type_id', 'document-type'], ['research_print_templates', 'template_type_id', 'print-template-type'],
            ['research_rights_policies', 'policy_type_id', 'odrl-policy-type'], ['research_rights_policies', 'action_id', 'odrl-action'], ['research_access_decisions', 'action_id', 'odrl-action'],
            ['research_inbox_items', 'kind_id', 'inbox-kind'], ['research_inbox_items', 'origin_id', 'inbox-origin'], ['research_inbox_items', 'status_id', 'inbox-status']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }
    }

    public function down(): void
    {
        foreach (['research_inbox_items', 'research_access_decisions', 'research_rights_policies', 'research_print_templates', 'research_documents', 'research_document_templates',
            'research_notification_preferences', 'research_notifications', 'research_downloads', 'research_quota_policies', 'training_certificates', 'training_progress',
            'training_enrolments', 'training_assessments', 'training_modules', 'training_courses', 'research_lecture_resources', 'research_lecture_sections', 'research_lectures'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
