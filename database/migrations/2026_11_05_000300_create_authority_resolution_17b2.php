<?php

// OpenRiC Workspace - authority resolution (roadmap phase 17b2), after Heratio's ahg-authority-resolution: a name a
// description mentions, proposed as an agent or a place (by spaCy or through the API), is a mention with the context
// it stands in (the passage, the other names, dates and places near it, the words of role around it); the authority
// records and places it may be are its candidates, ranked by name and by evidence (dates, places, relations, roles,
// hierarchy, scale, the fonds' usual places); what an archivist decides is kept as it was seen, every candidate and
// its evidence frozen with it; a mention may be parked until there is more to go on, and found again when a new
// candidate appears; a rejected name is kept as feedback for the named-entity model; and a new record may be filled
// from outside authorities (VIAF, Wikidata, GeoNames and others), each field with the source it came from.
// Changed from Heratio: a mention is a proposal of the review queue rather than a row of a NER table, so a name sent
// through the API is resolved the same way; candidates are the institution's own authority records and places; the
// settings are per institution; and every state, decision, source and kind is a vocabulary row.
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
        foreach (['mention-state', 'authority-decision', 'lookup-source', 'role-language-kind'] as $vocabulary) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$vocabulary.ttl")));
        }

        // A mention, with its context packet (Heratio's ahg_mention and ahg_mention_context, one to one).
        Schema::create('authority_mentions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('proposal_id')->unique()->constrained('proposals')->cascadeOnDelete();
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->cascadeOnDelete();
            $table->foreignUuid('state_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('assigned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('assigned_at')->nullable();
            $table->text('assignment_note')->nullable();
            // Where the name stands in the text it was found in, in characters.
            $table->integer('offset_start')->nullable();
            $table->integer('offset_end')->nullable();
            $table->integer('paragraph_start')->nullable();
            $table->integer('paragraph_end')->nullable();
            $table->text('text_before')->nullable();
            $table->text('text_after')->nullable();
            $table->integer('occurrences')->default(0);
            $table->string('ner_model', 100)->nullable();
            $table->decimal('confidence', 6, 4)->nullable();
            $table->jsonb('co_occurring')->default('[]');
            $table->jsonb('nearby_dates')->default('[]');
            $table->jsonb('nearby_places')->default('[]');
            $table->jsonb('role_tokens')->default('[]');
            $table->timestampTz('computed_at')->nullable();
            $table->timestampsTz();
            $table->index(['institution_id', 'state_id']);
            $table->index(['assigned_to_id', 'state_id']);
        });

        // The authority records or places a mention may be, ranked (ahg_mention_candidate).
        Schema::create('authority_candidates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('mention_id')->constrained('authority_mentions')->cascadeOnDelete();
            $table->smallInteger('rank');
            $table->text('candidate_table');
            $table->uuid('candidate_id');
            $table->string('display_name', 1000);
            $table->decimal('name_similarity', 6, 4)->default(0);
            // Each dimension's signal (match, conflict, silent, absent) and what it rests on.
            $table->jsonb('signals')->default('{}');
            $table->jsonb('evidence')->default('{}');
            $table->decimal('composite', 6, 4)->nullable();
            $table->timestampTz('computed_at');
            $table->index(['mention_id', 'rank']);
            $table->index(['candidate_table', 'candidate_id']);
        });
        DB::statement("ALTER TABLE authority_candidates ADD CONSTRAINT authority_candidates_table CHECK (candidate_table IN ('agents', 'places'))");

        // Every decision, as it was seen: append-only (ahg_mention_decision).
        Schema::create('authority_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('mention_id')->constrained('authority_mentions')->restrictOnDelete();
            $table->foreignUuid('decision_id')->constrained('concepts')->restrictOnDelete();
            $table->text('chosen_table')->nullable();
            $table->uuid('chosen_id')->nullable();
            $table->decimal('top_score', 6, 4)->nullable();
            $table->text('reason')->nullable();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at');
            $table->jsonb('evidence_snapshot')->default('{}');
            $table->jsonb('candidates_snapshot')->default('[]');
            $table->index(['mention_id', 'decided_at']);
            $table->index(['user_id', 'decided_at']);
        });
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION authority_decisions_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'authority decisions are kept as they were made; % is not allowed', TG_OP USING ERRCODE = 'restrict_violation';
            END $$
        SQL);
        DB::statement('CREATE TRIGGER authority_decisions_append_only BEFORE UPDATE OR DELETE ON authority_decisions FOR EACH ROW EXECUTE FUNCTION authority_decisions_append_only()');

        // The park queue: one row a parked mention (ahg_mention_park).
        Schema::create('authority_parks', function (Blueprint $table) {
            $table->foreignUuid('mention_id')->primary()->constrained('authority_mentions')->cascadeOnDelete();
            $table->foreignUuid('parked_by_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('parked_at');
            $table->text('reason');
            $table->boolean('new_candidate_available')->default(false);
            $table->timestampTz('checked_at')->nullable();
            // The candidates as they were when parked, to tell when a new one appears.
            $table->text('fingerprint')->nullable();
        });

        // A rejected name, kept to train the named-entity model again (ahg_ner_feedback).
        Schema::create('ner_feedback', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('mention_id')->constrained('authority_mentions')->cascadeOnDelete();
            $table->foreignUuid('decision_id')->constrained('authority_decisions')->restrictOnDelete();
            $table->text('source_text');
            $table->string('value', 1000);
            // spaCy's label, as the model is trained on it.
            $table->string('label', 20);
            $table->integer('offset_start')->nullable();
            $table->integer('offset_end')->nullable();
            $table->text('reason');
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('ner_model', 100)->nullable();
            $table->timestampTz('exported_at')->nullable();
            $table->timestampTz('created_at');
            $table->index(['institution_id', 'exported_at']);
        });

        // Outside authorities: each institution's settings, and what they answered (ahg_settings lookup.*, ahg_authority_lookup_cache).
        Schema::create('authority_lookup_settings', function (Blueprint $table) {
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('source_id')->constrained('concepts')->restrictOnDelete();
            $table->boolean('enabled')->default(false);
            $table->integer('rate_limit')->default(60);
            $table->integer('cache_ttl')->default(604800);
            $table->string('username', 200)->nullable();
            $table->smallInteger('precedence')->default(0);
            $table->timestampsTz();
            $table->primary(['institution_id', 'source_id']);
        });
        Schema::create('authority_lookup_cache', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('source_id')->constrained('concepts')->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('query', 500);
            $table->jsonb('payload');
            $table->timestampTz('retrieved_at');
            $table->integer('ttl');
            $table->unique(['source_id', 'kind', 'query']);
        });
        // The institution's own settings: how many candidates, and its words of role (authority_resolution.*).
        Schema::create('authority_settings', function (Blueprint $table) {
            $table->foreignUuid('institution_id')->primary()->constrained('institutions')->cascadeOnDelete();
            $table->smallInteger('candidate_top_n')->default(5);
            $table->jsonb('role_tokens')->default('{}');
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE authority_settings ADD CONSTRAINT authority_settings_top_n CHECK (candidate_top_n BETWEEN 1 AND 50)');

        // Where each field of a new record came from (Heratio's FieldProvenanceWriter).
        Schema::create('authority_field_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->text('subject_table');
            $table->uuid('subject_id');
            $table->string('field', 100);
            $table->text('value');
            // An outside authority, or none when the field came from the mention, its context or the proposal.
            $table->foreignUuid('source_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->string('origin', 30);
            $table->text('source_iri')->nullable();
            $table->string('licence', 500)->nullable();
            $table->text('licence_url')->nullable();
            $table->timestampTz('retrieved_at');
            $table->foreignUuid('decision_id')->nullable()->constrained('authority_decisions')->restrictOnDelete();
            $table->index(['subject_table', 'subject_id']);
        });

        foreach ([['authority_mentions', 'state_id', 'mention-state'], ['authority_decisions', 'decision_id', 'authority-decision'],
            ['authority_lookup_settings', 'source_id', 'lookup-source'], ['authority_lookup_cache', 'source_id', 'lookup-source'],
            ['authority_field_sources', 'source_id', 'lookup-source']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }
    }

    public function down(): void
    {
        foreach (['authority_field_sources', 'authority_settings', 'authority_lookup_cache', 'authority_lookup_settings', 'ner_feedback', 'authority_parks'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('DROP TRIGGER IF EXISTS authority_decisions_append_only ON authority_decisions');
        Schema::dropIfExists('authority_decisions');
        DB::statement('DROP FUNCTION IF EXISTS authority_decisions_append_only()');
        Schema::dropIfExists('authority_candidates');
        Schema::dropIfExists('authority_mentions');
    }
};
