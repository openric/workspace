<?php

// OpenRiC Workspace - research services, part 19i (roadmap phase 19, ADR 0017): the analysis of a research project,
// after Heratio's ahg-research: research_snapshot and research_snapshot_item, research_timeline_event,
// research_map_point, research_source_assessment, research_entity_resolution, research_cross_fonds_query,
// research_field_watch and research_field_alert, research_report with research_report_section,
// research_report_template and research_peer_review, and the research_ric_map of Heratio's RiC bridge. The system
// report templates are seeded with PSIS's.
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

    private const VOCABULARIES = ['snapshot-status', 'timeline-date-type', 'source-kind', 'source-form', 'source-completeness', 'resolution-status', 'field-alert-type',
        'report-template-type', 'research-report-status', 'research-report-section-type', 'peer-review-status'];

    /** PSIS's system report templates (ahgResearchPlugin, 149_phase2_reports.sql; Heratio keeps the table but seeds none): name, description and sections. */
    public const TEMPLATES = [
        'research-summary' => ['Research Summary', 'General research summary report', ['title-page', 'toc', 'heading:Introduction', 'text:Background', 'text:Methodology', 'text:Findings',
            'text:Conclusion', 'bibliography']],
        'genealogical' => ['Genealogical Report', 'Family history research report', ['title-page', 'toc', 'heading:Family Overview', 'text:Origins', 'text:Family Timeline',
            'text:Notable Members', 'collection-list', 'bibliography']],
        'historical' => ['Historical Analysis', 'Historical research analysis', ['title-page', 'toc', 'heading:Historical Context', 'text:Primary Sources', 'text:Analysis',
            'text:Interpretation', 'annotation-list', 'bibliography']],
        'source-analysis' => ['Source Analysis', 'Archival source analysis report', ['title-page', 'toc', 'heading:Source Description', 'text:Provenance', 'text:Content Analysis',
            'text:Reliability Assessment', 'annotation-list', 'bibliography']],
        'finding-aid' => ['Finding Aid', 'Collection finding aid', ['title-page', 'toc', 'heading:Collection Overview', 'text:Administrative History', 'text:Scope and Content',
            'collection-list', 'text:Access Conditions']],
        'custom' => ['Custom Report', 'Blank report with no predefined sections', []],
    ];

    public function up(): void
    {
        foreach (self::VOCABULARIES as $name) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$name.ttl")));
        }
        $concept = fn (Blueprint $table, string $column) => $table->foreignUuid($column)->constrained('concepts')->restrictOnDelete();
        $who = fn (Blueprint $table, string $column) => $table->foreignUuid($column)->nullable()->constrained('users')->nullOnDelete();
        $project = fn (Blueprint $table) => $table->foreignUuid('project_id')->constrained('research_projects')->cascadeOnDelete();

        Schema::create('research_snapshots', function (Blueprint $table) use ($concept, $who, $project) {
            $table->uuid('id')->primary();
            $project($table);
            $who($table, 'user_id');
            $table->text('title');
            $table->text('description')->nullable();
            $table->string('hash_sha256', 64)->nullable();
            $table->jsonb('query_state')->nullable();
            $table->jsonb('rights_state')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->integer('item_count')->default(0);
            $concept($table, 'status_id');
            $table->timestampTz('frozen_at')->nullable();
            $table->text('citation_id')->nullable();
            $table->timestampsTz();
        });

        Schema::create('research_snapshot_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('snapshot_id')->constrained('research_snapshots')->cascadeOnDelete();
            // The record, while it is there; its IRI, title and reference code as they were.
            $table->foreignUuid('record_id')->nullable()->constrained('record_resources')->nullOnDelete();
            $table->text('record_iri');
            $table->text('title')->nullable();
            $table->text('reference_code')->nullable();
            $table->text('language')->nullable();
            $table->jsonb('metadata_version')->nullable();
            $table->jsonb('rights_snapshot')->nullable();
            $table->integer('sort_order')->default(0);
        });

        Schema::create('research_timeline_events', function (Blueprint $table) use ($concept, $who, $project) {
            $table->uuid('id')->primary();
            $project($table);
            $who($table, 'user_id');
            $table->text('label');
            $table->text('description')->nullable();
            $table->date('date_start');
            $table->date('date_end')->nullable();
            $concept($table, 'date_type_id');
            $table->text('source_table')->nullable();
            $table->uuid('source_id')->nullable();
            $table->integer('position')->default(0);
            $table->string('color', 7)->nullable();
            $table->timestampsTz();
        });

        Schema::create('research_map_points', function (Blueprint $table) use ($who, $project) {
            $table->uuid('id')->primary();
            $project($table);
            $who($table, 'user_id');
            $table->text('label');
            $table->text('description')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->text('place_name')->nullable();
            $table->date('date_valid_from')->nullable();
            $table->date('date_valid_to')->nullable();
            $table->text('source_table')->nullable();
            $table->uuid('source_id')->nullable();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE research_map_points ADD CONSTRAINT research_map_points_on_earth CHECK (latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180)');

        Schema::create('research_source_assessments', function (Blueprint $table) use ($concept, $who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('record_id')->constrained('record_resources')->cascadeOnDelete();
            $who($table, 'user_id');
            $concept($table, 'source_kind_id');
            $concept($table, 'source_form_id');
            $concept($table, 'completeness_id');
            $table->smallInteger('trust_score')->nullable();
            $table->text('rationale')->nullable();
            $table->text('bias_context')->nullable();
            $table->timestampTz('assessed_at')->nullable();
            $table->index(['record_id', 'assessed_at']);
        });
        DB::statement('ALTER TABLE research_source_assessments ADD CONSTRAINT research_source_assessments_trust CHECK (trust_score IS NULL OR trust_score BETWEEN 0 AND 100)');

        Schema::create('research_entity_resolutions', function (Blueprint $table) use ($concept, $who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('project_id')->nullable()->constrained('research_projects')->nullOnDelete();
            $table->text('entity_a_table');
            $table->uuid('entity_a_id');
            $table->text('entity_b_table');
            $table->uuid('entity_b_id');
            $table->decimal('confidence', 5, 4)->nullable();
            $table->text('match_method')->nullable();
            $concept($table, 'status_id');
            $who($table, 'proposer_id');
            $who($table, 'resolver_id');
            $table->timestampTz('resolved_at')->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('evidence')->nullable();
            $table->timestampsTz();
        });

        Schema::create('research_cross_fonds_queries', function (Blueprint $table) use ($who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $who($table, 'user_id');
            $table->text('query_text');
            $table->jsonb('fonds_ids')->nullable();
            $table->integer('results_count')->default(0);
            $table->integer('elapsed_ms')->nullable();
            $table->timestampTz('created_at')->nullable();
        });

        Schema::create('research_field_watches', function (Blueprint $table) use ($who, $project) {
            $table->uuid('id')->primary();
            $project($table);
            $table->text('doi');
            $table->text('title')->nullable();
            $table->text('source_ref')->nullable();
            $who($table, 'added_by_id');
            $table->timestampTz('last_checked_at')->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->unique(['project_id', 'doi']);
        });

        Schema::create('research_field_alerts', function (Blueprint $table) use ($concept, $project) {
            $table->uuid('id')->primary();
            $project($table);
            $table->foreignUuid('watch_id')->nullable()->constrained('research_field_watches')->nullOnDelete();
            $concept($table, 'alert_type_id');
            $table->text('title')->nullable();
            $table->text('detail')->nullable();
            $table->text('url')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestampTz('detected_at')->nullable();
        });

        Schema::create('research_report_templates', function (Blueprint $table) use ($concept) {
            $table->uuid('id')->primary();
            // None for the system templates every institution has.
            $table->foreignUuid('institution_id')->nullable()->constrained('institutions')->cascadeOnDelete();
            $concept($table, 'template_type_id');
            $table->text('name');
            $table->text('description')->nullable();
            $table->jsonb('sections_config');
            $table->boolean('is_system')->default(false);
            $table->timestampsTz();
        });

        Schema::create('research_reports', function (Blueprint $table) use ($concept) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('project_id')->nullable()->constrained('research_projects')->nullOnDelete();
            $table->text('title');
            $concept($table, 'template_type_id');
            $table->text('description')->nullable();
            $concept($table, 'status_id');
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
        });

        Schema::create('research_report_sections', function (Blueprint $table) use ($concept) {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_id')->constrained('research_reports')->cascadeOnDelete();
            $concept($table, 'section_type_id');
            $table->text('title')->nullable();
            $table->text('content')->nullable();
            $table->foreignUuid('bibliography_id')->nullable()->constrained('bibliographies')->nullOnDelete();
            $table->foreignUuid('collection_id')->nullable()->constrained('research_collections')->nullOnDelete();
            $table->jsonb('settings')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
        });

        Schema::create('research_peer_reviews', function (Blueprint $table) use ($concept, $who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_id')->constrained('research_reports')->cascadeOnDelete();
            $who($table, 'requested_by_id');
            $table->foreignUuid('reviewer_id')->constrained('users')->cascadeOnDelete();
            $concept($table, 'status_id');
            $table->text('feedback')->nullable();
            $table->smallInteger('rating')->nullable();
            $table->timestampTz('requested_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
        });
        DB::statement('ALTER TABLE research_peer_reviews ADD CONSTRAINT research_peer_reviews_rating CHECK (rating IS NULL OR rating BETWEEN 1 AND 5)');

        Schema::create('research_ric_map', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            // A project or an output, and the activity of the catalogue that describes it.
            $table->text('entity_table');
            $table->uuid('entity_id');
            $table->foreignUuid('activity_id')->constrained('activities')->cascadeOnDelete();
            $table->timestampsTz();
            $table->unique(['entity_table', 'entity_id']);
        });

        foreach ([['research_snapshots', 'status_id', 'snapshot-status'], ['research_timeline_events', 'date_type_id', 'timeline-date-type'],
            ['research_source_assessments', 'source_kind_id', 'source-kind'], ['research_source_assessments', 'source_form_id', 'source-form'],
            ['research_source_assessments', 'completeness_id', 'source-completeness'], ['research_entity_resolutions', 'status_id', 'resolution-status'],
            ['research_field_alerts', 'alert_type_id', 'field-alert-type'], ['research_report_templates', 'template_type_id', 'report-template-type'],
            ['research_reports', 'template_type_id', 'report-template-type'], ['research_reports', 'status_id', 'research-report-status'],
            ['research_report_sections', 'section_type_id', 'research-report-section-type'], ['research_peer_reviews', 'status_id', 'peer-review-status']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }

        foreach (self::TEMPLATES as $type => [$name, $description, $sections]) {
            DB::table('research_report_templates')->insert(['id' => (string) Str::uuid(), 'institution_id' => null, 'is_system' => true, 'name' => $name, 'description' => $description,
                'template_type_id' => DB::table('concepts')->where('iri', self::VOCAB."report-template-type/$type")->value('id'),
                'sections_config' => json_encode($sections), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (['research_ric_map', 'research_peer_reviews', 'research_report_sections', 'research_reports', 'research_report_templates', 'research_field_alerts', 'research_field_watches',
            'research_cross_fonds_queries', 'research_entity_resolutions', 'research_source_assessments', 'research_map_points', 'research_timeline_events', 'research_snapshot_items',
            'research_snapshots'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
