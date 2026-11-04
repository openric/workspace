<?php

// OpenRiC Workspace - the institution's own pages on its public site (roadmap phase 20a), after Heratio's ahg-static-page,
// ahg-articles and ahg-landing-page: static pages (about, privacy, terms and any other) in each language they are
// written in; articles with their attachments, the articles they link to and visitors' comments; landing pages built
// of blocks, the institution's public ones and each user's own dashboards; and the files and images they show, each
// kept in an OCFL object of its own.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['site-article-status', 'article-attachment-kind', 'article-comment-status', 'landing-block-type'] as $vocabulary) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$vocabulary.ttl")));
        }
        Schema::create('static_pages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->text('slug');
            $table->text('source_language');
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['institution_id', 'slug']);
        });
        Schema::create('static_page_translations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('static_page_id')->constrained('static_pages')->cascadeOnDelete();
            $table->text('language');
            $table->text('title');
            // Markdown; any HTML in it is stripped when it is shown.
            $table->text('content')->nullable();
            $table->timestampsTz();
            $table->unique(['static_page_id', 'language']);
        });
        Schema::create('articles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->text('slug');
            $table->text('title');
            $table->text('excerpt')->nullable();
            $table->text('body')->nullable();
            $table->text('attachments_label')->nullable();
            $table->text('author')->nullable();
            $table->text('article_group')->nullable();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->integer('view_count')->default(0);
            $table->timestampTz('published_at')->nullable();
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['institution_id', 'slug']);
            $table->index(['institution_id', 'status_id', 'published_at']);
        });
        Schema::create('site_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            // The article it belongs to, if any: then it is served only while the article is published.
            $table->foreignUuid('article_id')->nullable()->constrained('articles')->cascadeOnDelete();
            $table->text('file_name');
            $table->text('mime_type');
            $table->bigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->text('storage_path');
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        Schema::table('articles', function (Blueprint $table) {
            $table->foreignUuid('cover_file_id')->nullable()->constrained('site_files')->nullOnDelete();
        });
        Schema::create('article_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('article_id')->constrained('articles')->cascadeOnDelete();
            $table->foreignUuid('site_file_id')->constrained('site_files')->cascadeOnDelete();
            $table->foreignUuid('kind_id')->constrained('concepts')->restrictOnDelete();
            $table->text('group_label')->nullable();
            $table->text('title');
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['article_id', 'sort_order']);
        });
        Schema::create('article_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('article_id')->constrained('articles')->cascadeOnDelete();
            $table->foreignUuid('related_article_id')->constrained('articles')->cascadeOnDelete();
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestampTz('created_at');
            $table->unique(['article_id', 'related_article_id']);
            $table->index('related_article_id');
        });
        DB::statement('ALTER TABLE article_links ADD CONSTRAINT article_links_not_itself CHECK (article_id <> related_article_id)');
        Schema::create('article_comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('article_id')->constrained('articles')->cascadeOnDelete();
            $table->text('author_name')->nullable();
            $table->text('body');
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->text('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampsTz();
            $table->index(['article_id', 'status_id']);
            $table->index(['ip', 'created_at']);
        });
        Schema::create('landing_pages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            // A user's own dashboard has its owner; an institution's public page has none.
            $table->foreignUuid('owner_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->text('name');
            $table->text('slug');
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->text('css_classes')->nullable();
            $table->text('custom_css')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['institution_id', 'slug']);
        });
        DB::statement('CREATE UNIQUE INDEX landing_pages_one_default ON landing_pages (institution_id) WHERE is_default AND owner_id IS NULL');
        DB::statement('ALTER TABLE landing_pages ADD CONSTRAINT landing_pages_dashboard_not_default CHECK (owner_id IS NULL OR NOT is_default)');
        Schema::create('landing_page_blocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('landing_page_id')->constrained('landing_pages')->cascadeOnDelete();
            $table->foreignUuid('block_type_id')->constrained('concepts')->restrictOnDelete();
            $table->uuid('parent_block_id')->nullable();
            // Which column of its parent row it sits in: 1, 2 or 3.
            $table->smallInteger('column_slot')->nullable();
            $table->text('title')->nullable();
            $table->integer('position')->default(0);
            $table->jsonb('config')->default('{}');
            $table->text('css_classes')->nullable();
            $table->boolean('full_width')->default(false);
            $table->text('background_color')->nullable();
            $table->text('text_color')->nullable();
            // Space above and below the block, in rem.
            $table->decimal('padding_top', 3, 1)->default(1.5);
            $table->decimal('padding_bottom', 3, 1)->default(1.5);
            $table->smallInteger('col_span')->default(12);
            $table->boolean('is_visible')->default(true);
            $table->timestampsTz();
            $table->index(['landing_page_id', 'parent_block_id', 'position']);
        });
        Schema::table('landing_page_blocks', function (Blueprint $table) {
            $table->foreign('parent_block_id')->references('id')->on('landing_page_blocks')->cascadeOnDelete();
        });
        DB::statement("ALTER TABLE landing_page_blocks ADD CONSTRAINT landing_page_blocks_colours CHECK ((background_color IS NULL OR background_color ~ '^#[0-9A-Fa-f]{6}$') AND (text_color IS NULL OR text_color ~ '^#[0-9A-Fa-f]{6}$'))");
        DB::statement('ALTER TABLE landing_page_blocks ADD CONSTRAINT landing_page_blocks_layout CHECK (col_span BETWEEN 1 AND 12 AND (column_slot IS NULL OR column_slot BETWEEN 1 AND 3) AND padding_top BETWEEN 0 AND 6 AND padding_bottom BETWEEN 0 AND 6)');
        foreach ([['articles', 'status_id', 'site-article-status'], ['article_attachments', 'kind_id', 'article-attachment-kind'], ['article_comments', 'status_id', 'article-comment-status'],
            ['landing_page_blocks', 'block_type_id', 'landing-block-type']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', 'https://workspace.openric.org/vocab/{$vocabulary}/')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_page_blocks');
        Schema::dropIfExists('landing_pages');
        Schema::dropIfExists('article_comments');
        Schema::dropIfExists('article_links');
        Schema::dropIfExists('article_attachments');
        Schema::table('articles', fn (Blueprint $table) => $table->dropConstrainedForeignId('cover_file_id'));
        Schema::dropIfExists('site_files');
        Schema::dropIfExists('articles');
        Schema::dropIfExists('static_page_translations');
        Schema::dropIfExists('static_pages');
    }
};
