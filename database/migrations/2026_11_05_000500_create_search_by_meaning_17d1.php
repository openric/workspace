<?php

// OpenRiC Workspace - search by meaning (roadmap phase 17d1), after Heratio's ahg-semantic-search and the vector search
// it reads: each entry of the search index, as the public sees it or as staff do, with its text turned into a vector
// by the AI gateway's embedding model (nomic-embed-text, 768 dimensions), so a query is matched by meaning as well as
// by its words, filtered by institution, audience, kind, level, repository and years in the same SQL as the full-text
// search; an index on the vectors for the nearest; each institution's choices (searching by meaning, widening a query
// with the synonyms its vocabularies hold or a model suggests, reranking by what a person searched for lately); and an
// account's own recent searches, kept only while its institution reranks by them. The vectors need PostgreSQL's pgvector extension, which a
// superuser creates in each database (INSTALL.md); this migration only checks it is there.
// Changed from Heratio: pgvector in the same database rather than Qdrant, so the vectors obey the same rules as the
// records; the synonyms are the SKOS alternative labels of the institution's vocabularies rather than a separate
// thesaurus table.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public const DIMENSIONS = 768;

    public function up(): void
    {
        DB::table('pg_extension')->where('extname', 'vector')->exists() || throw new RuntimeException(
            'Search by meaning needs pgvector in this database. As a PostgreSQL superuser, run: CREATE EXTENSION IF NOT EXISTS vector; (see INSTALL.md).');

        Schema::create('search_vectors', function (Blueprint $table) {
            $table->foreignUuid('search_entry_id')->primary()->constrained('search_entries')->cascadeOnDelete();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->string('model', 200);
            // The text the vector was made from, hashed, so a changed entry is embedded again.
            $table->char('text_hash', 64);
            $table->timestampTz('embedded_at');
        });
        DB::statement('ALTER TABLE search_vectors ADD COLUMN embedding vector('.self::DIMENSIONS.') NOT NULL');
        DB::statement('CREATE INDEX search_vectors_embedding ON search_vectors USING hnsw (embedding vector_cosine_ops)');

        Schema::create('search_settings', function (Blueprint $table) {
            $table->foreignUuid('institution_id')->primary()->constrained('institutions')->cascadeOnDelete();
            $table->boolean('by_meaning')->default(false);
            $table->boolean('expand_synonyms')->default(true);
            $table->boolean('expand_with_model')->default(false);
            $table->boolean('rerank_history')->default(false);
            $table->smallInteger('expansion_limit')->default(5);
            $table->string('embedding_model', 200)->nullable();
            // How alike an entry must be to be found by meaning (cosine similarity); the nearest are always ranked, the rest left out.
            $table->decimal('min_similarity', 3, 2)->default(0.40);
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE search_settings ADD CONSTRAINT search_settings_expansion_limit CHECK (expansion_limit BETWEEN 0 AND 20)');

        // An account's own recent searches, kept only while its institution reranks by them, the last 25 at most; the
        // search statistics (search_queries) keep no one's name.
        Schema::create('search_history', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('query', 512);
            $table->timestampTz('executed_at')->useCurrent();
            $table->index(['user_id', 'executed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_history');
        Schema::dropIfExists('search_settings');
        Schema::dropIfExists('search_vectors');
    }
};
