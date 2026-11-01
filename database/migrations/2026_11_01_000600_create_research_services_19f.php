<?php

// OpenRiC Workspace - research services, part 19f (roadmap phase 19, ADR 0017): research projects and working
// together on them, after Heratio's ahg-research: research_project, research_project_collaborator,
// research_project_resource, research_project_milestone with research_milestone, research_team_member,
// research_output, research_activity_log, research_discussion, research_evidence_comment,
// research_collaboration_presence, research_comment, research_clipboard_project and research_export_log. The
// project (Heratio's project_id) is added to the bookings, saved searches, collections, annotations, diary entries,
// bibliographies, submissions and research workspace files of 19b to 19e, and a notebook records the project it was promoted to.
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

    private const VOCABULARIES = ['research-project-type', 'research-project-status', 'research-project-visibility', 'project-role', 'project-resource-type', 'link-type',
        'milestone-type', 'milestone-status', 'team-role', 'team-status', 'output-type', 'output-identifier-type', 'output-status', 'project-activity-type', 'project-export-format'];

    /** The tables of 19c to 19e that Heratio ties to a project. */
    private const OF_A_PROJECT = ['research_collections', 'research_annotations', 'research_diary_entries', 'bibliographies', 'researcher_submissions', 'research_workspace_files',
        'saved_searches', 'reading_room_bookings'];

    public function up(): void
    {
        foreach (self::VOCABULARIES as $name) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$name.ttl")));
        }
        $concept = fn (Blueprint $table, string $column) => $table->foreignUuid($column)->constrained('concepts')->restrictOnDelete();
        $who = fn (Blueprint $table, string $column) => $table->foreignUuid($column)->nullable()->constrained('users')->nullOnDelete();
        $project = fn (Blueprint $table) => $table->foreignUuid('project_id')->constrained('research_projects')->cascadeOnDelete();

        Schema::create('research_projects', function (Blueprint $table) use ($concept) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('owner_id')->constrained('users')->restrictOnDelete();
            $table->text('title');
            $table->text('description')->nullable();
            $concept($table, 'project_type_id');
            // Heratio's institution: where the researcher studies or works, as written.
            $table->text('affiliation')->nullable();
            $table->text('supervisor')->nullable();
            $table->text('funding_source')->nullable();
            $table->text('grant_number')->nullable();
            $table->text('ethics_approval')->nullable();
            $table->date('start_date')->nullable();
            $table->date('expected_end_date')->nullable();
            $table->date('actual_end_date')->nullable();
            $concept($table, 'status_id');
            $concept($table, 'visibility_id');
            $table->string('share_token_hash', 64)->nullable()->unique();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
        });

        Schema::create('research_project_members', function (Blueprint $table) use ($concept, $who, $project) {
            $table->uuid('id')->primary();
            $project($table);
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $concept($table, 'role_id');
            $concept($table, 'status_id');
            $who($table, 'invited_by_id');
            $table->timestampTz('invited_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->text('notes')->nullable();
            $table->unique(['project_id', 'user_id']);
        });

        Schema::create('research_project_resources', function (Blueprint $table) use ($concept, $who, $project) {
            $table->uuid('id')->primary();
            $project($table);
            $concept($table, 'resource_type_id');
            $table->uuid('resource_id')->nullable();
            $table->foreignUuid('record_id')->nullable()->constrained('record_resources')->nullOnDelete();
            $table->foreignUuid('file_id')->nullable()->constrained('research_files')->nullOnDelete();
            $table->text('external_url')->nullable();
            $table->foreignUuid('link_type_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->jsonb('link_metadata')->nullable();
            $table->text('title')->nullable();
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->text('tags')->nullable();
            $who($table, 'added_by_id');
            $table->integer('sort_order')->default(0);
            $table->timestampTz('added_at')->nullable();
        });

        Schema::create('research_project_milestones', function (Blueprint $table) use ($concept, $who, $project) {
            $table->uuid('id')->primary();
            $project($table);
            $table->text('title');
            $concept($table, 'milestone_type_id');
            $table->text('description')->nullable();
            $table->text('deliverable')->nullable();
            $table->date('due_date')->nullable();
            $table->date('completed_date')->nullable();
            $who($table, 'completed_by_id');
            $concept($table, 'status_id');
            $table->smallInteger('progress_pct')->default(0);
            $table->integer('sort_order')->default(0);
            $who($table, 'created_by_id');
            $table->timestampsTz();
        });

        Schema::create('research_project_team_members', function (Blueprint $table) use ($concept, $who, $project) {
            $table->uuid('id')->primary();
            $project($table);
            $table->text('person_name');
            // The account, when the member has one; a member need not.
            $who($table, 'user_id');
            $concept($table, 'role_id');
            $table->text('affiliation')->nullable();
            $table->text('email')->nullable();
            $table->string('orcid', 19)->nullable();
            $table->boolean('is_lead')->default(false);
            $table->text('contribution_note')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $concept($table, 'status_id');
            $who($table, 'created_by_id');
            $table->timestampsTz();
        });

        Schema::create('research_project_outputs', function (Blueprint $table) use ($concept, $who, $project) {
            $table->uuid('id')->primary();
            $project($table);
            $concept($table, 'output_type_id');
            $table->text('title');
            $table->text('authors')->nullable();
            $table->text('venue')->nullable();
            $table->foreignUuid('identifier_type_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->text('identifier')->nullable();
            $table->text('identifier_url')->nullable();
            $table->date('output_date')->nullable();
            $concept($table, 'status_id');
            $table->text('notes')->nullable();
            $who($table, 'created_by_id');
            $table->timestampsTz();
        });

        Schema::create('research_project_activity', function (Blueprint $table) use ($concept, $who, $project) {
            $table->uuid('id')->primary();
            $project($table);
            $who($table, 'user_id');
            $concept($table, 'activity_type_id');
            $table->text('entity_table')->nullable();
            $table->uuid('entity_id')->nullable();
            $table->text('entity_title')->nullable();
            $table->jsonb('details')->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->index(['project_id', 'created_at']);
        });

        Schema::create('research_discussions', function (Blueprint $table) use ($who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('workspace_id')->nullable()->constrained('research_workspaces')->cascadeOnDelete();
            $table->foreignUuid('project_id')->nullable()->constrained('research_projects')->cascadeOnDelete();
            $table->uuid('parent_id')->nullable();
            $who($table, 'user_id');
            $table->text('subject')->nullable();
            $table->text('content');
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_resolved')->default(false);
            $who($table, 'resolved_by_id');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
        });
        Schema::table('research_discussions', fn (Blueprint $table) => $table->foreign('parent_id')->references('id')->on('research_discussions')->cascadeOnDelete());
        DB::statement('ALTER TABLE research_discussions ADD CONSTRAINT research_discussions_of_one CHECK ((workspace_id IS NULL) <> (project_id IS NULL))');

        Schema::create('research_evidence_comments', function (Blueprint $table) use ($who, $project) {
            $table->uuid('id')->primary();
            $project($table);
            $table->foreignUuid('collection_id')->nullable()->constrained('research_collections')->cascadeOnDelete();
            $table->foreignUuid('item_id')->nullable()->constrained('research_collection_items')->cascadeOnDelete();
            $table->uuid('parent_id')->nullable();
            $who($table, 'author_id');
            $table->text('body');
            $table->boolean('is_resolved')->default(false);
            $who($table, 'resolved_by_id');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
            $table->index(['project_id', 'created_at']);
        });
        Schema::table('research_evidence_comments', fn (Blueprint $table) => $table->foreign('parent_id')->references('id')->on('research_evidence_comments')->cascadeOnDelete());

        Schema::create('research_project_presence', function (Blueprint $table) use ($project) {
            $table->uuid('id')->primary();
            $project($table);
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('cursor_target')->nullable();
            $table->string('user_color', 7)->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->unique(['project_id', 'user_id']);
        });

        Schema::create('research_comments', function (Blueprint $table) use ($who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            // What the comment is on: an annotation, a diary entry or a collection (Heratio's research_comment.entity_type).
            $table->text('subject_table');
            $table->uuid('subject_id');
            $table->uuid('parent_id')->nullable();
            $who($table, 'user_id');
            $table->text('content');
            $table->boolean('is_resolved')->default(false);
            $who($table, 'resolved_by_id');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
            $table->index(['subject_table', 'subject_id']);
        });
        Schema::table('research_comments', fn (Blueprint $table) => $table->foreign('parent_id')->references('id')->on('research_comments')->cascadeOnDelete());

        Schema::create('research_project_clipboard', function (Blueprint $table) use ($project) {
            $table->uuid('id')->primary();
            $project($table);
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('record_id')->constrained('record_resources')->cascadeOnDelete();
            $table->boolean('is_pinned')->default(false);
            $table->text('notes')->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->unique(['project_id', 'user_id', 'record_id']);
        });

        Schema::create('research_project_exports', function (Blueprint $table) use ($concept, $who, $project) {
            $table->uuid('id')->primary();
            $project($table);
            $concept($table, 'format_id');
            $who($table, 'exported_by_id');
            $table->timestampTz('exported_at')->nullable();
        });

        foreach (self::OF_A_PROJECT as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->foreignUuid('project_id')->nullable()->constrained('research_projects')->nullOnDelete());
        }
        Schema::table('research_notebooks', function (Blueprint $table) {
            $table->foreignUuid('promoted_to_project_id')->nullable()->constrained('research_projects')->nullOnDelete();
            $table->timestampTz('promoted_at')->nullable();
        });

        foreach ([['research_projects', 'project_type_id', 'research-project-type'], ['research_projects', 'status_id', 'research-project-status'],
            ['research_projects', 'visibility_id', 'research-project-visibility'], ['research_project_members', 'role_id', 'project-role'],
            ['research_project_members', 'status_id', 'membership-status'], ['research_project_resources', 'resource_type_id', 'project-resource-type'],
            ['research_project_resources', 'link_type_id', 'link-type'], ['research_project_milestones', 'milestone_type_id', 'milestone-type'],
            ['research_project_milestones', 'status_id', 'milestone-status'], ['research_project_team_members', 'role_id', 'team-role'],
            ['research_project_team_members', 'status_id', 'team-status'], ['research_project_outputs', 'output_type_id', 'output-type'],
            ['research_project_outputs', 'identifier_type_id', 'output-identifier-type'], ['research_project_outputs', 'status_id', 'output-status'],
            ['research_project_activity', 'activity_type_id', 'project-activity-type'], ['research_project_exports', 'format_id', 'project-export-format']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }
    }

    public function down(): void
    {
        Schema::table('research_notebooks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('promoted_to_project_id');
            $table->dropColumn('promoted_at');
        });
        foreach (self::OF_A_PROJECT as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->dropConstrainedForeignId('project_id'));
        }
        foreach (['research_project_exports', 'research_project_clipboard', 'research_comments', 'research_project_presence', 'research_evidence_comments', 'research_discussions',
            'research_project_activity', 'research_project_outputs', 'research_project_team_members', 'research_project_milestones', 'research_project_resources',
            'research_project_members', 'research_projects'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
