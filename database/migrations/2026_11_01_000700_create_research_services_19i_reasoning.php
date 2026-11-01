<?php

// OpenRiC Workspace - research services, part 19i (roadmap phase 19, ADR 0017): the reasoning of a research project,
// after Heratio's ahg-research: research_assertion with research_claim_meta (the claim ledger) and
// research_assertion_evidence, research_hypothesis and research_hypothesis_evidence, research_contradiction,
// research_decision_log, research_memory_item, and research_analysis_result, research_analysis_result_claim and
// research_analysis_code. Heratio keeps a claim in research_assertion and its ledger fields in a sidecar table;
// here they are one table.
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

    private const VOCABULARIES = ['claim-type', 'claim-status', 'claim-evidence-type', 'confidence-level', 'claim-provenance', 'claim-source-type', 'evidence-relationship',
        'hypothesis-status', 'hypothesis-evidence-relationship', 'contradiction-kind', 'contradiction-severity', 'finding-status', 'contradiction-source', 'decision-type',
        'memory-kind', 'memory-status', 'analysis-result-type', 'analysis-relationship', 'analysis-code-kind'];

    public function up(): void
    {
        foreach (self::VOCABULARIES as $name) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$name.ttl")));
        }
        $concept = fn (Blueprint $table, string $column) => $table->foreignUuid($column)->constrained('concepts')->restrictOnDelete();
        $optional = fn (Blueprint $table, string $column) => $table->foreignUuid($column)->nullable()->constrained('concepts')->restrictOnDelete();
        $who = fn (Blueprint $table, string $column) => $table->foreignUuid($column)->nullable()->constrained('users')->nullOnDelete();
        $project = fn (Blueprint $table) => $table->foreignUuid('project_id')->constrained('research_projects')->cascadeOnDelete();

        Schema::create('research_claims', function (Blueprint $table) use ($concept, $optional, $who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('project_id')->nullable()->constrained('research_projects')->cascadeOnDelete();
            $who($table, 'user_id');
            $concept($table, 'claim_type_id');
            // What it is about: an entity of the Workspace, or only its name (Heratio's subject_type, subject_id, subject_label).
            $table->text('subject_table')->nullable();
            $table->uuid('subject_id')->nullable();
            $table->text('subject_label')->nullable();
            $table->text('predicate')->nullable();
            // The claim in words, or what is asserted of the subject.
            $table->text('object_value');
            $table->text('object_table')->nullable();
            $table->uuid('object_id')->nullable();
            $table->text('object_label')->nullable();
            $concept($table, 'status_id');
            $table->decimal('confidence', 5, 2)->nullable();
            $table->integer('version')->default(1);
            // The claim ledger's own fields (Heratio's research_claim_meta).
            $optional($table, 'evidence_type_id');
            $optional($table, 'confidence_level_id');
            $optional($table, 'provenance_kind_id');
            foreach (['supporting_sources', 'opposing_sources', 'quotations', 'method_theory_link', 'researcher_notes', 'unresolved_weaknesses', 'ethical_concerns'] as $text) {
                $table->text($text)->nullable();
            }
            $table->timestampsTz();
            $table->index('project_id');
        });

        Schema::create('research_claim_evidence', function (Blueprint $table) use ($concept, $who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('claim_id')->constrained('research_claims')->cascadeOnDelete();
            $concept($table, 'source_type_id');
            $table->uuid('source_id');
            $table->jsonb('selector')->nullable();
            $concept($table, 'relationship_id');
            $table->text('note')->nullable();
            $who($table, 'added_by_id');
            $table->timestampTz('created_at')->nullable();
            $table->unique(['claim_id', 'source_type_id', 'source_id', 'relationship_id']);
        });

        Schema::create('research_hypotheses', function (Blueprint $table) use ($concept, $who, $project) {
            $table->uuid('id')->primary();
            $project($table);
            $who($table, 'user_id');
            $table->text('statement');
            $concept($table, 'status_id');
            $table->integer('evidence_count')->default(0);
            $table->text('tags')->nullable();
            $table->timestampsTz();
        });

        Schema::create('research_hypothesis_evidence', function (Blueprint $table) use ($concept, $who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hypothesis_id')->constrained('research_hypotheses')->cascadeOnDelete();
            $concept($table, 'source_type_id');
            $table->uuid('source_id');
            $concept($table, 'relationship_id');
            $table->decimal('confidence', 5, 2)->nullable();
            $table->text('note')->nullable();
            $who($table, 'added_by_id');
            $table->timestampTz('created_at')->nullable();
        });

        Schema::create('research_contradictions', function (Blueprint $table) use ($concept, $project) {
            $table->uuid('id')->primary();
            $project($table);
            $table->foreignUuid('claim_a_id')->constrained('research_claims')->cascadeOnDelete();
            $table->foreignUuid('claim_b_id')->nullable()->constrained('research_claims')->cascadeOnDelete();
            $concept($table, 'kind_id');
            $table->string('signature', 190);
            $table->text('detail')->nullable();
            $concept($table, 'severity_id');
            $concept($table, 'status_id');
            $concept($table, 'detected_by_id');
            $table->timestampsTz();
            $table->unique(['project_id', 'signature']);
        });

        Schema::create('research_decisions', function (Blueprint $table) use ($concept, $who, $project) {
            $table->uuid('id')->primary();
            $project($table);
            $concept($table, 'decision_type_id');
            $table->text('summary');
            $table->text('reason')->nullable();
            $table->text('related_ref')->nullable();
            $table->text('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $who($table, 'created_by_id');
            $table->timestampsTz();
        });

        Schema::create('research_memory_items', function (Blueprint $table) use ($concept) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('project_id')->nullable()->constrained('research_projects')->nullOnDelete();
            $concept($table, 'kind_id');
            $table->text('title');
            $table->text('body')->nullable();
            $table->text('source_ref')->nullable();
            $concept($table, 'status_id');
            $table->timestampsTz();
            $table->index('user_id');
        });

        Schema::create('research_analysis_results', function (Blueprint $table) use ($concept, $who, $project) {
            $table->uuid('id')->primary();
            $project($table);
            $concept($table, 'result_type_id');
            $table->text('title');
            $table->text('source_data_ref')->nullable();
            $table->text('source_data_version')->nullable();
            $table->text('method')->nullable();
            $table->text('code_ref')->nullable();
            $table->timestampTz('generated_at')->nullable();
            $table->text('researcher_decision')->nullable();
            $table->foreignUuid('artifact_file_id')->nullable()->constrained('research_files')->nullOnDelete();
            $who($table, 'created_by_id');
            $table->timestampsTz();
        });

        Schema::create('research_analysis_result_claims', function (Blueprint $table) use ($concept) {
            $table->uuid('id')->primary();
            $table->foreignUuid('result_id')->constrained('research_analysis_results')->cascadeOnDelete();
            $table->foreignUuid('claim_id')->constrained('research_claims')->cascadeOnDelete();
            $concept($table, 'relationship_id');
            $table->text('note')->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->unique(['result_id', 'claim_id']);
        });

        Schema::create('research_analysis_codes', function (Blueprint $table) use ($concept, $who, $project) {
            $table->uuid('id')->primary();
            $project($table);
            $concept($table, 'kind_id');
            $table->text('label');
            $table->text('body')->nullable();
            $who($table, 'created_by_id');
            $table->timestampTz('created_at')->nullable();
        });

        foreach ([['research_claims', 'claim_type_id', 'claim-type'], ['research_claims', 'status_id', 'claim-status'], ['research_claims', 'evidence_type_id', 'claim-evidence-type'],
            ['research_claims', 'confidence_level_id', 'confidence-level'], ['research_claims', 'provenance_kind_id', 'claim-provenance'],
            ['research_claim_evidence', 'source_type_id', 'claim-source-type'], ['research_claim_evidence', 'relationship_id', 'evidence-relationship'],
            ['research_hypotheses', 'status_id', 'hypothesis-status'], ['research_hypothesis_evidence', 'source_type_id', 'claim-source-type'],
            ['research_hypothesis_evidence', 'relationship_id', 'hypothesis-evidence-relationship'], ['research_contradictions', 'kind_id', 'contradiction-kind'],
            ['research_contradictions', 'severity_id', 'contradiction-severity'], ['research_contradictions', 'status_id', 'finding-status'],
            ['research_contradictions', 'detected_by_id', 'contradiction-source'], ['research_decisions', 'decision_type_id', 'decision-type'],
            ['research_memory_items', 'kind_id', 'memory-kind'], ['research_memory_items', 'status_id', 'memory-status'],
            ['research_analysis_results', 'result_type_id', 'analysis-result-type'], ['research_analysis_result_claims', 'relationship_id', 'analysis-relationship'],
            ['research_analysis_codes', 'kind_id', 'analysis-code-kind']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }
    }

    public function down(): void
    {
        foreach (['research_analysis_codes', 'research_analysis_result_claims', 'research_analysis_results', 'research_memory_items', 'research_decisions', 'research_contradictions',
            'research_hypothesis_evidence', 'research_hypotheses', 'research_claim_evidence', 'research_claims'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
