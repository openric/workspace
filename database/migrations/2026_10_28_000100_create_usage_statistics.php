<?php

// OpenRiC Workspace - usage statistics of the public catalogue (roadmap phase 8; WS-016).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// PSIS's ahgStatisticsPlugin (Heratio ahg-statistics): ahg_usage_event, the raw events, kept for a while,
// and ahg_statistics_daily, the counts of each day, kept. Unlike PSIS, no address, user agent, referrer,
// session or place is stored: a visitor is a hash keyed by a salt that changes each day and is then
// forgotten (UsageService), so it counts visitors within the day and identifies no one. The event and
// the web crawler are concepts (usage-event, web-crawler), as PSIS's ahg_bot_list is a vocabulary here.

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
        foreach (config('workspace.bundled_vocabularies') as $file) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$file")));
        }
        Schema::create('usage_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('concepts')->restrictOnDelete();
            // What was viewed, or the unit of description whose file was downloaded.
            $table->text('subject_table');
            $table->uuid('subject_id');
            $table->foreignUuid('file_id')->nullable()->constrained('digital_files')->nullOnDelete();
            // The repository that holds it, for the counts by repository.
            $table->uuid('repository_id')->nullable();
            // A program, not a person: counted apart.
            $table->foreignUuid('crawler_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->boolean('signed_in')->default(false);
            $table->char('visitor', 64)->nullable();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->index(['institution_id', 'occurred_at']);
            $table->index('occurred_at');
        });
        Schema::create('usage_daily', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->date('day');
            // Null with a null subject: the whole catalogue that day, whose visitors are each counted once.
            $table->foreignUuid('event_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->text('subject_table')->nullable();
            $table->uuid('subject_id')->nullable();
            $table->uuid('repository_id')->nullable();
            $table->integer('people')->default(0);
            $table->integer('visitors')->default(0);
            $table->integer('signed_in')->default(0);
            $table->integer('crawlers')->default(0);
            $table->index(['institution_id', 'day']);
            $table->index(['subject_table', 'subject_id']);
        });
        DB::statement("CREATE TRIGGER usage_events_event_id_vocabulary BEFORE INSERT OR UPDATE ON usage_events FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('event_id', '".self::VOCAB."usage-event/')");
        DB::statement("CREATE TRIGGER usage_events_crawler_id_vocabulary BEFORE INSERT OR UPDATE ON usage_events FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('crawler_id', '".self::VOCAB."web-crawler/')");
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_daily');
        Schema::dropIfExists('usage_events');
    }
};
