<?php

// OpenRiC Workspace - the full-text search index (WS-016, roadmap phase 6, ADR 0009).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Two entries per described entity, a public and a staff one (App\Search\SearchIndex),
// each with an unstemmed accent-free vector and a vector stemmed per language. The
// index is derived from the store and can be rebuilt at any time
// (workspace:search:rebuild); this migration builds it for what exists.

use App\Models\Institution;
use App\Search\SearchIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A trusted extension: the database's owner may create it.
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');

        Schema::create('search_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->text('subject_table');
            $table->uuid('subject_id');
            // Only what the public may read (true), or everything (false).
            $table->boolean('public');
            $table->text('title');
            $table->text('body');
            $table->foreignUuid('level_id')->nullable()->constrained('concepts')->nullOnDelete();
            $table->uuid('repository_id')->nullable();
            $table->date('earliest')->nullable();
            $table->date('latest')->nullable();
            $table->timestampTz('updated_at');
            $table->unique(['subject_table', 'subject_id', 'public']);
            $table->index(['institution_id', 'public', 'subject_table']);
        });
        DB::statement('ALTER TABLE search_entries ADD COLUMN plain tsvector NOT NULL');
        DB::statement('ALTER TABLE search_entries ADD COLUMN stemmed tsvector NOT NULL');
        DB::statement('CREATE INDEX search_entries_plain ON search_entries USING gin (plain)');
        DB::statement('CREATE INDEX search_entries_stemmed ON search_entries USING gin (stemmed)');

        foreach (Institution::all() as $institution) {
            app(SearchIndex::class)->rebuild($institution);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('search_entries');
    }
};
