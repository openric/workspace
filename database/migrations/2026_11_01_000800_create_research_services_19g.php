<?php

// OpenRiC Workspace - research services, part 19g (roadmap phase 19, ADR 0017): the studios of a research project and
// research journals, after Heratio's ahg-research: research_writing_doc, research_writing_section and
// research_writing_version (the writing studio); research_review_comment (the review studio); research_submission,
// research_submission_requirement and research_submission_response (the publication studio, here
// research_publication_*, as researcher_submissions are the researcher's submissions to the catalogue);
// research_method_template and research_method_protocol (the method studio, its templates seeded with Heratio's);
// research_question_brief and research_question_brief_version (the question builder); research_argument and
// research_argument_step (the argument builder); research_source_triage; and research_journal,
// research_journal_issue, research_journal_article and the directory research_target_journal.
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

    private const VOCABULARIES = ['writing-doc-type', 'writing-status', 'publication-status', 'protocol-status', 'brief-status', 'argument-slot', 'triage-category', 'read-status',
        'journal-kind', 'journal-status', 'issue-status', 'article-status', 'target-journal-status'];

    /** The question builder's fields (Heratio's QuestionBuilderService::FIELDS). */
    private const BRIEF = ['broad_topic', 'problem_statement', 'research_gap', 'primary_question', 'secondary_questions', 'hypothesis', 'scope_boundaries', 'key_definitions', 'assumptions', 'bias_risks'];

    public function up(): void
    {
        foreach (self::VOCABULARIES as $name) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$name.ttl")));
        }
        $concept = fn (Blueprint $table, string $column) => $table->foreignUuid($column)->constrained('concepts')->restrictOnDelete();
        $optional = fn (Blueprint $table, string $column) => $table->foreignUuid($column)->nullable()->constrained('concepts')->restrictOnDelete();
        $who = fn (Blueprint $table, string $column) => $table->foreignUuid($column)->nullable()->constrained('users')->nullOnDelete();
        $project = fn (Blueprint $table) => $table->foreignUuid('project_id')->constrained('research_projects')->cascadeOnDelete();

        Schema::create('research_target_journals', function (Blueprint $table) use ($concept) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->text('title');
            foreach (['subtitle', 'publisher', 'homepage_url', 'submission_url', 'languages', 'subject_scope', 'article_types', 'accreditation', 'reference_style', 'structure_notes',
                'peer_review', 'apc_amount', 'turnaround', 'notes'] as $text) {
                $table->text($text)->nullable();
            }
            $table->string('issn', 9)->nullable();
            $table->string('eissn', 9)->nullable();
            // Heratio's per-market tag of an accreditation, such as ZA for the DHET lists.
            $table->string('accreditation_market', 8)->nullable();
            $table->integer('max_words')->nullable();
            $table->integer('abstract_max_words')->nullable();
            $table->boolean('open_access')->default(false);
            $concept($table, 'status_id');
            $table->timestampsTz();
        });

        Schema::create('research_writing_docs', function (Blueprint $table) use ($concept, $who, $project) {
            $table->uuid('id')->primary();
            $project($table);
            $table->text('title');
            $concept($table, 'doc_type_id');
            $concept($table, 'status_id');
            $who($table, 'created_by_id');
            $table->timestampsTz();
        });

        Schema::create('research_writing_sections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('doc_id')->constrained('research_writing_docs')->cascadeOnDelete();
            $table->text('heading')->nullable();
            $table->text('body')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
        });

        Schema::create('research_writing_versions', function (Blueprint $table) use ($who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('doc_id')->constrained('research_writing_docs')->cascadeOnDelete();
            $table->integer('version_no');
            $table->jsonb('snapshot');
            $table->text('note')->nullable();
            $who($table, 'created_by_id');
            $table->timestampTz('created_at')->nullable();
            $table->unique(['doc_id', 'version_no']);
        });

        Schema::create('research_review_comments', function (Blueprint $table) use ($who, $project) {
            $table->uuid('id')->primary();
            $project($table);
            $table->foreignUuid('claim_id')->nullable()->constrained('research_claims')->cascadeOnDelete();
            $table->uuid('thread_id')->nullable();
            $who($table, 'author_id');
            $table->text('body');
            $table->boolean('resolved')->default(false);
            $table->timestampsTz();
        });
        Schema::table('research_review_comments', fn (Blueprint $table) => $table->foreign('thread_id')->references('id')->on('research_review_comments')->cascadeOnDelete());

        Schema::create('research_publication_submissions', function (Blueprint $table) use ($concept, $who, $project) {
            $table->uuid('id')->primary();
            $project($table);
            $table->foreignUuid('target_journal_id')->nullable()->constrained('research_target_journals')->nullOnDelete();
            $table->text('venue_name');
            $concept($table, 'status_id');
            $table->text('manuscript_title')->nullable();
            $table->date('submitted_at')->nullable();
            $table->date('decision_at')->nullable();
            $table->text('doi')->nullable();
            $table->text('repository_url')->nullable();
            $table->text('notes')->nullable();
            $who($table, 'created_by_id');
            $table->timestampsTz();
        });

        Schema::create('research_publication_requirements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('submission_id')->constrained('research_publication_submissions')->cascadeOnDelete();
            $table->text('label');
            $table->boolean('met')->default(false);
            $table->text('note')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
        });

        Schema::create('research_publication_responses', function (Blueprint $table) use ($who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('submission_id')->constrained('research_publication_submissions')->cascadeOnDelete();
            $table->text('reviewer_label')->nullable();
            $table->text('point')->nullable();
            $table->text('response')->nullable();
            $table->text('revision_note')->nullable();
            $who($table, 'created_by_id');
            $table->timestampsTz();
        });

        Schema::create('research_method_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // None for the templates every institution has.
            $table->foreignUuid('institution_id')->nullable()->constrained('institutions')->cascadeOnDelete();
            $table->string('code', 64);
            $table->text('name');
            $table->text('discipline')->nullable();
            $table->text('description')->nullable();
            $table->jsonb('guidance');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(100);
            $table->timestampsTz();
            $table->unique(['institution_id', 'code']);
        });

        Schema::create('research_method_protocols', function (Blueprint $table) use ($concept, $who, $project) {
            $table->uuid('id')->primary();
            $project($table);
            $table->foreignUuid('template_id')->constrained('research_method_templates')->restrictOnDelete();
            $table->text('title');
            $table->jsonb('fields');
            $concept($table, 'status_id');
            $who($table, 'created_by_id');
            $table->timestampsTz();
        });

        Schema::create('research_question_briefs', function (Blueprint $table) use ($concept, $who, $project) {
            $table->uuid('id')->primary();
            $project($table);
            $table->integer('current_version')->default(0);
            $concept($table, 'status_id');
            $who($table, 'created_by_id');
            $table->timestampsTz();
            $table->unique('project_id');
        });

        Schema::create('research_question_brief_versions', function (Blueprint $table) use ($who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('brief_id')->constrained('research_question_briefs')->cascadeOnDelete();
            $table->integer('version_no');
            foreach (self::BRIEF as $field) {
                $table->text($field)->nullable();
            }
            $table->text('change_reason')->nullable();
            $who($table, 'created_by_id');
            $table->timestampTz('created_at')->nullable();
            $table->unique(['brief_id', 'version_no']);
        });

        Schema::create('research_arguments', function (Blueprint $table) use ($who, $project) {
            $table->uuid('id')->primary();
            $project($table);
            $table->text('title')->nullable();
            $table->text('central_thesis')->nullable();
            $who($table, 'created_by_id');
            $table->timestampsTz();
            $table->unique('project_id');
        });

        Schema::create('research_argument_steps', function (Blueprint $table) use ($concept) {
            $table->uuid('id')->primary();
            $table->foreignUuid('argument_id')->constrained('research_arguments')->cascadeOnDelete();
            $concept($table, 'slot_id');
            $table->foreignUuid('claim_id')->nullable()->constrained('research_claims')->nullOnDelete();
            $table->text('note')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
        });

        Schema::create('research_source_triage', function (Blueprint $table) use ($concept, $optional, $who, $project) {
            $table->uuid('id')->primary();
            $project($table);
            $concept($table, 'source_type_id');
            $table->uuid('source_id');
            $optional($table, 'triage_category_id');
            $concept($table, 'read_status_id');
            $table->text('notes')->nullable();
            $who($table, 'updated_by_id');
            $table->timestampsTz();
            $table->unique(['project_id', 'source_type_id', 'source_id']);
        });

        Schema::create('research_journals', function (Blueprint $table) use ($concept, $who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            // Its editor among the researchers; none for a journal of the institution.
            $who($table, 'user_id');
            $concept($table, 'kind_id');
            $table->text('title');
            foreach (['subtitle', 'publisher', 'description', 'aims_scope', 'editor_name', 'editor_email', 'doi'] as $text) {
                $table->text($text)->nullable();
            }
            $table->string('issn', 9)->nullable();
            $table->string('eissn', 9)->nullable();
            $table->foreignUuid('target_journal_id')->nullable()->constrained('research_target_journals')->nullOnDelete();
            $table->foreignUuid('cover_record_id')->nullable()->constrained('record_resources')->nullOnDelete();
            $concept($table, 'status_id');
            $table->timestampsTz();
        });

        Schema::create('research_journal_issues', function (Blueprint $table) use ($concept) {
            $table->uuid('id')->primary();
            $table->foreignUuid('journal_id')->constrained('research_journals')->cascadeOnDelete();
            $table->text('volume')->nullable();
            $table->text('number')->nullable();
            $table->text('title')->nullable();
            $table->date('issue_date')->nullable();
            $table->text('description')->nullable();
            $concept($table, 'status_id');
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
        });

        Schema::create('research_journal_articles', function (Blueprint $table) use ($concept) {
            $table->uuid('id')->primary();
            $table->foreignUuid('journal_id')->constrained('research_journals')->cascadeOnDelete();
            $table->foreignUuid('issue_id')->nullable()->constrained('research_journal_issues')->nullOnDelete();
            $table->text('title');
            foreach (['authors', 'abstract', 'keywords', 'body_markdown', 'reference_style', 'doi'] as $text) {
                $table->text($text)->nullable();
            }
            $table->foreignUuid('target_journal_id')->nullable()->constrained('research_target_journals')->nullOnDelete();
            $table->integer('word_count')->default(0);
            $concept($table, 'status_id');
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
        });

        foreach ([['research_target_journals', 'status_id', 'target-journal-status'], ['research_writing_docs', 'doc_type_id', 'writing-doc-type'],
            ['research_writing_docs', 'status_id', 'writing-status'], ['research_publication_submissions', 'status_id', 'publication-status'],
            ['research_method_protocols', 'status_id', 'protocol-status'], ['research_question_briefs', 'status_id', 'brief-status'],
            ['research_argument_steps', 'slot_id', 'argument-slot'], ['research_source_triage', 'source_type_id', 'claim-source-type'],
            ['research_source_triage', 'triage_category_id', 'triage-category'], ['research_source_triage', 'read_status_id', 'read-status'],
            ['research_journals', 'kind_id', 'journal-kind'], ['research_journals', 'status_id', 'journal-status'], ['research_journal_issues', 'status_id', 'issue-status'],
            ['research_journal_articles', 'status_id', 'article-status']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }

        foreach (json_decode(file_get_contents(resource_path('research/method-templates.json')), true) as $t) {
            DB::table('research_method_templates')->insert(['id' => (string) Str::uuid(), 'institution_id' => null, 'code' => $t['code'], 'name' => $t['name'], 'discipline' => $t['discipline'],
                'description' => $t['description'], 'guidance' => json_encode($t['guidance'], JSON_UNESCAPED_UNICODE), 'is_active' => true, 'sort_order' => $t['sort_order'],
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (['research_journal_articles', 'research_journal_issues', 'research_journals', 'research_source_triage', 'research_argument_steps', 'research_arguments',
            'research_question_brief_versions', 'research_question_briefs', 'research_method_protocols', 'research_method_templates', 'research_publication_responses',
            'research_publication_requirements', 'research_publication_submissions', 'research_review_comments', 'research_writing_versions', 'research_writing_sections',
            'research_writing_docs', 'research_target_journals'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
