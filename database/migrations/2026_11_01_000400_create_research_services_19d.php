<?php

// OpenRiC Workspace - research services, part 19d (roadmap phase 19, ADR 0017): the researcher's own workspace, after
// Heratio's ahg-research: research_collection and research_collection_item, research_annotation and
// research_annotation_target, research_notebook and research_notebook_item, research_journal_entry (the research
// diary), and research_workspace with its members, resources and files. Each belongs to an account, as the 19c
// bibliographies do; a project (Heratio's project_id on several of them) arrives with 19f, which adds those columns.
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
        foreach (['annotation-type', 'annotation-visibility', 'selector-type', 'notebook-item-type', 'diary-entry-type', 'research-workspace-visibility',
            'workspace-member-role', 'membership-status', 'workspace-resource-type'] as $name) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$name.ttl")));
        }
        $concept = fn (Blueprint $table, string $column) => $table->foreignUuid($column)->constrained('concepts')->restrictOnDelete();
        $who = fn (Blueprint $table, string $column) => $table->foreignUuid($column)->nullable()->constrained('users')->nullOnDelete();
        $owned = function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
        };

        Schema::create('research_collections', function (Blueprint $table) use ($owned) {
            $owned($table);
            $table->text('name');
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(false);
            $table->string('share_token_hash', 64)->nullable()->unique();
            $table->timestampsTz();
            $table->index('user_id');
        });

        Schema::create('research_collection_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('collection_id')->constrained('research_collections')->cascadeOnDelete();
            // An entity of the Workspace, by table and id, as favourites keep them; or an outside address.
            $table->text('subject_table')->nullable();
            $table->uuid('subject_id')->nullable();
            $table->text('external_uri')->nullable();
            $table->text('title')->nullable();
            $table->text('reference_code')->nullable();
            $table->text('tags')->nullable();
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
            $table->unique(['collection_id', 'subject_table', 'subject_id']);
        });

        Schema::create('research_annotations', function (Blueprint $table) use ($owned, $concept) {
            $owned($table);
            $table->text('subject_table');
            $table->uuid('subject_id');
            $table->foreignUuid('collection_id')->nullable()->constrained('research_collections')->nullOnDelete();
            $table->foreignUuid('instantiation_id')->nullable()->constrained('instantiations')->nullOnDelete();
            $concept($table, 'annotation_type_id');
            $table->text('title')->nullable();
            $table->text('content')->nullable();
            $table->text('canvas_id')->nullable();
            $table->text('tags')->nullable();
            $concept($table, 'visibility_id');
            $table->timestampsTz();
            $table->index(['subject_table', 'subject_id']);
            $table->index('user_id');
        });

        Schema::create('research_annotation_targets', function (Blueprint $table) use ($concept) {
            $table->uuid('id')->primary();
            $table->foreignUuid('annotation_id')->constrained('research_annotations')->cascadeOnDelete();
            $concept($table, 'selector_type_id');
            $table->jsonb('selector');
            $table->text('source_url')->nullable();
            $table->timestampTz('created_at')->nullable();
        });

        Schema::create('research_notebooks', function (Blueprint $table) use ($owned) {
            $owned($table);
            $table->text('title');
            $table->text('summary')->nullable();
            $table->foreignUuid('cover_record_id')->nullable()->constrained('record_resources')->nullOnDelete();
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
            $table->index('user_id');
        });

        Schema::create('research_notebook_items', function (Blueprint $table) use ($concept) {
            $table->uuid('id')->primary();
            $table->foreignUuid('notebook_id')->constrained('research_notebooks')->cascadeOnDelete();
            $concept($table, 'item_type_id');
            $table->text('title')->nullable();
            $table->text('body')->nullable();
            $table->foreignUuid('source_record_id')->nullable()->constrained('record_resources')->nullOnDelete();
            $table->foreignUuid('saved_search_id')->nullable()->constrained('saved_searches')->nullOnDelete();
            $table->jsonb('ai_output_payload')->nullable();
            $table->boolean('pinned')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
        });

        Schema::create('research_diary_entries', function (Blueprint $table) use ($owned, $concept) {
            $owned($table);
            $table->date('entry_date');
            $table->text('title')->nullable();
            $table->text('content');
            $concept($table, 'entry_type_id');
            $table->integer('time_spent_minutes')->nullable();
            $table->text('tags')->nullable();
            $table->boolean('is_private')->default(true);
            // What the entry is about, by table and id: a booking, a request, an annotation, a collection, a search.
            $table->text('related_table')->nullable();
            $table->uuid('related_id')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'entry_date']);
        });

        Schema::create('research_workspaces', function (Blueprint $table) use ($concept) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('owner_id')->constrained('users')->restrictOnDelete();
            $table->text('name');
            $table->text('description')->nullable();
            $concept($table, 'visibility_id');
            $table->jsonb('settings')->nullable();
            $table->timestampsTz();
        });

        Schema::create('research_workspace_members', function (Blueprint $table) use ($concept, $who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('research_workspaces')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $concept($table, 'role_id');
            $concept($table, 'status_id');
            $who($table, 'invited_by_id');
            $table->timestampTz('invited_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->unique(['workspace_id', 'user_id']);
        });

        Schema::create('research_workspace_resources', function (Blueprint $table) use ($concept, $who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('research_workspaces')->cascadeOnDelete();
            $concept($table, 'resource_type_id');
            $table->uuid('resource_id')->nullable();
            $table->text('external_url')->nullable();
            $table->text('title')->nullable();
            $table->text('description')->nullable();
            $who($table, 'added_by_id');
            $table->integer('sort_order')->default(0);
            $table->timestampTz('added_at')->nullable();
        });

        Schema::create('research_workspace_files', function (Blueprint $table) use ($who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('research_workspaces')->cascadeOnDelete();
            $table->foreignUuid('file_id')->constrained('research_files')->cascadeOnDelete();
            $who($table, 'added_by_id');
            $table->timestampTz('created_at')->nullable();
        });

        foreach ([['research_annotations', 'annotation_type_id', 'annotation-type'], ['research_annotations', 'visibility_id', 'annotation-visibility'],
            ['research_annotation_targets', 'selector_type_id', 'selector-type'], ['research_notebook_items', 'item_type_id', 'notebook-item-type'],
            ['research_diary_entries', 'entry_type_id', 'diary-entry-type'], ['research_workspaces', 'visibility_id', 'research-workspace-visibility'],
            ['research_workspace_members', 'role_id', 'workspace-member-role'], ['research_workspace_members', 'status_id', 'membership-status'],
            ['research_workspace_resources', 'resource_type_id', 'workspace-resource-type']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }
    }

    public function down(): void
    {
        foreach (['research_workspace_files', 'research_workspace_resources', 'research_workspace_members', 'research_workspaces', 'research_diary_entries',
            'research_notebook_items', 'research_notebooks', 'research_annotation_targets', 'research_annotations', 'research_collection_items', 'research_collections'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
