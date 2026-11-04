<?php

// OpenRiC Workspace - federation (roadmap phase 16a), after Heratio's ahg-federation: an institution's peers (federation_peer
// and federation_peer_search as one), what it shares with them (federation_share_setting), and its federated searches,
// cached and logged (federation_search_cache, federation_search_log). Each institution chooses its own peers: another
// institution on this installation, another Workspace or Heratio, or (from 16b) an OAI-PMH, DSpace or Alma catalogue.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHECKED = [['federation_peers', 'type_id', 'federation-peer-type'], ['federation_peers', 'last_search_status_id', 'federation-search-status'],
        ['federation_peers', 'last_verification_id', 'federation-verification']];

    public function up(): void
    {
        foreach (['federation-peer-type', 'federation-search-status', 'federation-verification'] as $name) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$name.ttl")));
        }
        Schema::create('federation_share_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->unique()->constrained('institutions')->cascadeOnDelete();
            $table->boolean('share_enabled')->default(false);
            // Only these levels of description are shared; none named, every level. Only what is published is ever shared.
            $table->jsonb('levels')->nullable();
            $table->timestampsTz();
        });
        Schema::create('federation_peers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->text('name');
            $table->foreignUuid('type_id')->constrained('concepts')->restrictOnDelete();
            $table->text('base_url')->nullable();
            $table->text('search_url')->nullable();
            // A peer on this installation: the institution searched, here.
            $table->foreignUuid('peer_institution_id')->nullable()->constrained('institutions')->cascadeOnDelete();
            $table->text('api_key')->nullable();
            $table->text('description')->nullable();
            $table->text('contact_email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('search_enabled')->default(true);
            $table->integer('timeout_ms')->default(5000);
            $table->integer('max_results')->default(20);
            $table->integer('priority')->default(100);
            $table->timestampTz('last_search_at')->nullable();
            $table->foreignUuid('last_search_status_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->integer('avg_response_ms')->nullable();
            // Trust on first use: the key id the peer first signed with; another one later is not trusted.
            $table->text('pinned_key_id')->nullable();
            $table->timestampTz('key_pinned_at')->nullable();
            $table->foreignUuid('last_verification_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['institution_id', 'is_active']);
        });
        DB::statement('ALTER TABLE federation_peers ADD CONSTRAINT federation_peers_limits CHECK (timeout_ms BETWEEN 500 AND 60000 AND max_results BETWEEN 1 AND 200)');
        DB::statement('ALTER TABLE federation_peers ADD CONSTRAINT federation_peers_not_self CHECK (peer_institution_id IS NULL OR peer_institution_id <> institution_id)');
        Schema::create('federation_search_cache', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('peer_id')->constrained('federation_peers')->cascadeOnDelete();
            $table->char('query_hash', 64);
            $table->jsonb('results');
            $table->integer('result_count');
            $table->text('verification')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('expires_at');
            $table->unique(['peer_id', 'query_hash']);
        });
        Schema::create('federation_search_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->text('query_text');
            $table->char('query_hash', 64);
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            foreach (['peers_queried', 'peers_responded', 'peers_timeout', 'peers_error', 'total_results', 'total_time_ms'] as $column) {
                $table->integer($column)->default(0);
            }
            $table->timestampTz('created_at');
            $table->index(['institution_id', 'created_at']);
        });
        foreach (self::CHECKED as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', 'https://workspace.openric.org/vocab/{$vocabulary}/')");
        }
    }

    public function down(): void
    {
        foreach (['federation_search_log', 'federation_search_cache', 'federation_peers', 'federation_share_settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
