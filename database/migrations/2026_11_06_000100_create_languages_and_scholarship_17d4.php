<?php

// OpenRiC Workspace - the languages of a collection and generative scholarship (roadmap phase 17d4), after Heratio's
// ahg-semantic-search: a community glossary of a language, and transcriptions, corrections, translations and notes
// people contribute about a record in a language, each shown once reviewed and its contributor named only with consent;
// the discoveries a model makes in the catalogue graph around a record, each kept with the verified links it rests on,
// its confidence and the receipt of the call; the same across federation peers; and research leads curated from the
// discoveries, shown in the public catalogue only once a curator publishes them. Every status and kind is a vocabulary
// row.
// Changed from Heratio: everything belongs to an institution and, but for the glossary, to one of its descriptions;
// a discovery keeps its inference (phase 17a); the discoveries are for staff, only the published leads public.
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
        foreach (['community-contribution-status', 'language-contribution-type', 'research-lead-status'] as $vocabulary) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$vocabulary.ttl")));
        }
        $moderated = function (Blueprint $table) {
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('contributed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('moderated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('moderated_at')->nullable();
            $table->timestampsTz();
        };

        // A community glossary entry (Heratio's language_revival_glossary).
        Schema::create('language_glossary', function (Blueprint $table) use ($moderated) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            // The language as a BCP 47 primary subtag, such as af or zu.
            $table->string('language', 16);
            $table->string('term', 512);
            $table->text('meaning');
            $table->text('usage_example')->nullable();
            $table->string('source', 512)->nullable();
            $table->string('contributor_name', 255)->nullable();
            $moderated($table);
            $table->index(['institution_id', 'language', 'status_id']);
        });

        // A transcription, correction, translation or note about a record in a language (Heratio's
        // language_transcription_contribution).
        Schema::create('language_contributions', function (Blueprint $table) use ($moderated) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->cascadeOnDelete();
            $table->string('language', 16);
            $table->foreignUuid('type_id')->constrained('concepts')->restrictOnDelete();
            $table->text('body');
            $table->string('source', 512)->nullable();
            // Kept only when the contributor consented to be named.
            $table->string('contributor_name', 255)->nullable();
            $table->boolean('credit_consent')->default(false);
            $moderated($table);
            $table->index(['record_resource_id', 'status_id']);
            $table->index(['institution_id', 'language', 'status_id']);
        });
        DB::statement('ALTER TABLE language_contributions ADD CONSTRAINT language_contributions_named_with_consent CHECK (contributor_name IS NULL OR credit_consent)');

        // A discovery around one description (Heratio's ahg_scholarship_discovery): the verified links, what the model
        // drew from them, and how far to trust it.
        Schema::create('scholarship_discoveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('record_resource_id')->unique()->constrained('record_resources')->cascadeOnDelete();
            $table->string('title', 1024)->nullable();
            $table->text('summary')->nullable();
            $table->unsignedInteger('connection_count')->default(0);
            $table->unsignedSmallInteger('confidence')->default(0);
            $table->jsonb('evidence');
            $table->foreignUuid('inference_id')->nullable()->constrained('ai_inferences')->nullOnDelete();
            $table->timestampTz('generated_at');
            $table->timestampsTz();
            $table->index(['institution_id', 'confidence']);
        });

        // The same across federation peers (Heratio's ahg_scholarship_federated_discovery), kept for a day.
        Schema::create('scholarship_federated_discoveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('record_resource_id')->unique()->constrained('record_resources')->cascadeOnDelete();
            $table->jsonb('terms');
            $table->jsonb('connections');
            $table->jsonb('peer_stats');
            $table->unsignedInteger('connection_count')->default(0);
            $table->boolean('ai_available')->default(false);
            $table->foreignUuid('inference_id')->nullable()->constrained('ai_inferences')->nullOnDelete();
            $table->timestampTz('generated_at');
            $table->timestampsTz();
        });

        // A research lead (Heratio's research_lead), promoted from a discovery and curated.
        Schema::create('research_leads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('record_resource_id')->unique()->constrained('record_resources')->cascadeOnDelete();
            $table->foreignUuid('discovery_id')->nullable()->constrained('scholarship_discoveries')->nullOnDelete();
            $table->string('headline', 1024)->nullable();
            $table->text('lead_text')->nullable();
            $table->text('why_it_matters')->nullable();
            $table->unsignedInteger('connection_count')->default(0);
            $table->unsignedSmallInteger('confidence')->default(0);
            $table->jsonb('evidence');
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->boolean('ai_labelled')->default(true);
            $table->foreignUuid('inference_id')->nullable()->constrained('ai_inferences')->nullOnDelete();
            $table->foreignUuid('curated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('generated_at');
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->index(['institution_id', 'status_id', 'confidence']);
        });

        foreach ([
            ['language_glossary', 'status_id', 'community-contribution-status'], ['language_contributions', 'status_id', 'community-contribution-status'],
            ['language_contributions', 'type_id', 'language-contribution-type'], ['research_leads', 'status_id', 'research-lead-status'],
        ] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }
    }

    public function down(): void
    {
        foreach (['research_leads', 'scholarship_federated_discoveries', 'scholarship_discoveries', 'language_contributions', 'language_glossary'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
