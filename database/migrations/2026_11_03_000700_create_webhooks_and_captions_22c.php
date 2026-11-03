<?php

// OpenRiC Workspace - webhooks and caption tracks (roadmap phase 22c, ADR 0020): webhooks after Heratio's ahg-api
// (ahg_webhook, ahg_webhook_delivery), telling another system that an entity changed without sending what it says;
// caption tracks after Heratio's ahg-media-streaming (caption_track), WebVTT subtitles, captions, descriptions and
// chapters on sound and video.
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
        foreach (['webhook-event', 'webhook-delivery-status'] as $name) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$name.ttl")));
        }
        Schema::create('webhooks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('name');
            $table->text('url');
            $table->text('secret');
            // Event notations and entity kinds, each checked against its list when saved.
            $table->jsonb('events');
            $table->jsonb('entity_kinds');
            $table->boolean('is_active')->default(true);
            $table->integer('failure_count')->default(0);
            $table->timestampTz('last_triggered_at')->nullable();
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('webhook_id')->constrained('webhooks')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('concepts')->restrictOnDelete();
            $table->text('entity_kind');
            $table->uuid('entity_id');
            $table->jsonb('payload');
            $table->integer('response_code')->nullable();
            $table->text('response_body')->nullable();
            $table->integer('attempt_count')->default(0);
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->timestampTz('next_retry_at')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('delivered_at')->nullable();
            $table->index(['webhook_id', 'created_at']);
        });
        Schema::create('caption_tracks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('instantiation_id')->constrained('instantiations')->cascadeOnDelete();
            $table->text('language');
            $table->text('kind');
            $table->text('label');
            $table->text('storage_path');
            $table->char('sha256', 64);
            $table->integer('cue_count')->default(0);
            $table->boolean('is_default')->default(false);
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['instantiation_id', 'language', 'kind']);
        });
        DB::statement("ALTER TABLE caption_tracks ADD CONSTRAINT caption_tracks_kind_check CHECK (kind IN ('subtitles', 'captions', 'descriptions', 'chapters'))");
        foreach ([['webhook_deliveries', 'event_id', 'webhook-event'], ['webhook_deliveries', 'status_id', 'webhook-delivery-status']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', 'https://workspace.openric.org/vocab/{$vocabulary}/')");
        }
    }

    public function down(): void
    {
        foreach (['caption_tracks', 'webhook_deliveries', 'webhooks'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
