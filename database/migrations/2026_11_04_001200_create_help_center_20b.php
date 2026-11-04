<?php

// OpenRiC Workspace - the Help Center (roadmap phase 20b), after Heratio's ahg-help: the guides that ship with the
// Workspace in docs/help, each read into an article with its sections for searching, the articles one links to (from
// its text, or added by hand), and a record of what was last read in, so the library is read again when the files change.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_articles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('slug')->unique();
            $table->text('title');
            $table->text('category');
            $table->text('subcategory')->nullable();
            // The file it was read from, and the Heratio guide it was adapted from.
            $table->text('source_file');
            $table->text('heratio_source')->nullable();
            $table->text('body_markdown');
            $table->text('body_html');
            $table->text('body_text');
            $table->jsonb('toc')->default('[]');
            $table->integer('word_count')->default(0);
            $table->integer('sort_order')->default(100);
            // Shown only to who is signed in, as Heratio's admin categories are.
            $table->boolean('staff_only')->default(false);
            // The screens it explains, by route name or pattern, for help in context.
            $table->jsonb('routes')->default('[]');
            $table->text('tags')->nullable();
            $table->timestampsTz();
            $table->index(['category', 'sort_order']);
        });
        DB::statement("ALTER TABLE help_articles ADD COLUMN search tsvector GENERATED ALWAYS AS (setweight(to_tsvector('english', title), 'A') || setweight(to_tsvector('english', body_text), 'B')) STORED");
        DB::statement('CREATE INDEX help_articles_search ON help_articles USING gin (search)');
        Schema::create('help_sections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('help_article_id')->constrained('help_articles')->cascadeOnDelete();
            $table->text('heading');
            $table->text('anchor');
            $table->smallInteger('level')->default(2);
            $table->text('body_text')->nullable();
            $table->integer('sort_order')->default(0);
        });
        DB::statement("ALTER TABLE help_sections ADD COLUMN search tsvector GENERATED ALWAYS AS (setweight(to_tsvector('english', heading), 'A') || setweight(to_tsvector('english', coalesce(body_text, '')), 'B')) STORED");
        DB::statement('CREATE INDEX help_sections_search ON help_sections USING gin (search)');
        Schema::create('help_article_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('help_article_id')->constrained('help_articles')->cascadeOnDelete();
            $table->foreignUuid('related_article_id')->constrained('help_articles')->cascadeOnDelete();
            // Added by hand, not found in the article's text, so reading the library in again keeps it.
            $table->boolean('manual')->default(false);
            $table->timestampTz('created_at');
            $table->unique(['help_article_id', 'related_article_id']);
        });
        DB::statement('ALTER TABLE help_article_links ADD CONSTRAINT help_article_links_not_itself CHECK (help_article_id <> related_article_id)');
        Schema::create('help_library_state', function (Blueprint $table) {
            $table->smallInteger('id')->primary();
            $table->char('fingerprint', 64);
            $table->integer('articles');
            $table->timestampTz('read_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_library_state');
        Schema::dropIfExists('help_article_links');
        Schema::dropIfExists('help_sections');
        Schema::dropIfExists('help_articles');
    }
};
