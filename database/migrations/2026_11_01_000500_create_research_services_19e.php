<?php

// OpenRiC Workspace - research services, part 19e (roadmap phase 19, ADR 0017): researcher submissions, after
// Heratio's ahg-researcher-manage and PSIS's ahgResearcherPlugin: researcher_submission, researcher_submission_item,
// researcher_submission_file and researcher_submission_review. A submission belongs to an account; its research
// project (Heratio's project_id) arrives with 19f. Those who may review submissions are granted it here.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

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
        foreach (['submission-status', 'submission-item-type', 'submission-source', 'submission-review-action', 'permission'] as $name) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$name.ttl")));
        }
        $concept = fn (Blueprint $table, string $column) => $table->foreignUuid($column)->constrained('concepts')->restrictOnDelete();

        Schema::create('researcher_submissions', function (Blueprint $table) use ($concept) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('researcher_id')->nullable()->constrained('researchers')->nullOnDelete();
            $table->text('title');
            $table->text('description')->nullable();
            // Where its descriptions go: a repository and a description to put them under.
            $table->foreignUuid('repository_id')->nullable()->constrained('repositories')->nullOnDelete();
            $table->foreignUuid('parent_record_id')->nullable()->constrained('record_resources')->nullOnDelete();
            $concept($table, 'source_type_id');
            $table->foreignUuid('source_file_id')->nullable()->constrained('research_files')->nullOnDelete();
            $table->boolean('include_images')->default(true);
            $concept($table, 'status_id');
            $table->integer('total_items')->default(0);
            $table->integer('total_files')->default(0);
            $table->bigInteger('total_file_size')->default(0);
            $table->text('return_comment')->nullable();
            $table->text('reject_comment')->nullable();
            $table->foreignUuid('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            // The import that put it in the catalogue: its report says what became of each item.
            $table->foreignUuid('import_id')->nullable()->constrained('imports')->nullOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->index(['institution_id', 'status_id']);
        });

        Schema::create('researcher_submission_items', function (Blueprint $table) use ($concept) {
            $table->uuid('id')->primary();
            $table->foreignUuid('submission_id')->constrained('researcher_submissions')->cascadeOnDelete();
            $table->uuid('parent_item_id')->nullable();
            $concept($table, 'item_type_id');
            $table->text('title');
            $table->text('identifier')->nullable();
            // A level of the bundled vocabulary or of the institution's own, as a record's is (checked by the service).
            $table->foreignUuid('level_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->text('scope_and_content')->nullable();
            $table->text('extent_and_medium')->nullable();
            $table->text('date_display')->nullable();
            $table->date('date_start')->nullable();
            $table->date('date_end')->nullable();
            // Access points as Heratio keeps them: comma-separated names.
            $table->text('creators')->nullable();
            $table->text('subjects')->nullable();
            $table->text('places')->nullable();
            $table->text('genres')->nullable();
            $table->text('access_conditions')->nullable();
            $table->text('reproduction_conditions')->nullable();
            $table->text('notes')->nullable();
            $table->text('repository_name')->nullable();
            $table->text('repository_address')->nullable();
            $table->text('repository_contact')->nullable();
            // A note's record, already in the catalogue.
            $table->foreignUuid('reference_record_id')->nullable()->constrained('record_resources')->nullOnDelete();
            $table->integer('sort_order')->default(0);
            // What publishing made of it: a description, an authority record or a repository.
            $table->text('published_table')->nullable();
            $table->uuid('published_id')->nullable();
            $table->timestampsTz();
            $table->index('submission_id');
        });

        // A description inside another of the same submission; the key needs the table first.
        Schema::table('researcher_submission_items', fn (Blueprint $table) => $table->foreign('parent_item_id')->references('id')->on('researcher_submission_items')->nullOnDelete());

        Schema::create('researcher_submission_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('item_id')->constrained('researcher_submission_items')->cascadeOnDelete();
            // Null for a file an exchange file names but does not carry (Heratio's placeholder).
            $table->foreignUuid('file_id')->nullable()->constrained('research_files')->nullOnDelete();
            $table->text('original_name');
            $table->text('caption')->nullable();
            $table->integer('sort_order')->default(0);
            $table->foreignUuid('published_instantiation_id')->nullable()->constrained('instantiations')->nullOnDelete();
            $table->timestampTz('created_at')->nullable();
        });

        Schema::create('researcher_submission_reviews', function (Blueprint $table) use ($concept) {
            $table->uuid('id')->primary();
            $table->foreignUuid('submission_id')->constrained('researcher_submissions')->cascadeOnDelete();
            $table->foreignUuid('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $concept($table, 'action_id');
            $table->text('comment')->nullable();
            $table->timestampTz('created_at')->nullable();
        });

        foreach ([['researcher_submissions', 'source_type_id', 'submission-source'], ['researcher_submissions', 'status_id', 'submission-status'],
            ['researcher_submission_items', 'item_type_id', 'submission-item-type'],
            ['researcher_submission_reviews', 'action_id', 'submission-review-action']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }
        foreach (Institution::all() as $institution) {
            DB::transaction(fn () => app(RoleService::class)->grantKind($institution, 'institution', ['review-submissions']));
        }
    }

    public function down(): void
    {
        foreach (['researcher_submission_reviews', 'researcher_submission_files', 'researcher_submission_items', 'researcher_submissions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
