<?php

// OpenRiC Workspace - federation governance, discovery, vocabulary sync and joining (roadmap phase 16c), after Heratio's
// ahg-federation: a peer's trust level, rate limit, the surfaces it may be asked for, and what the last probe of its
// protocol descriptor found (federation_peer's governance columns); whether an institution drops unverified answers and
// which vocabularies it shares; the vocabularies it synchronises with a peer, each run and each change to a shared one
// (federation_vocab_sync, its log and federation_vocab_change); and requests to join its network (federation_join_request).
// Heratio's federation_term_mapping is the concept matches the Workspace already keeps: a concept taken in from a peer
// keeps the peer's IRI as an exact match.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const VOCABULARIES = ['federation-trust-level', 'federation-discovery-status', 'vocabulary-sync-direction', 'vocabulary-conflict-rule', 'vocabulary-change-type', 'join-request-status'];

    private const CHECKED = [['federation_peers', 'trust_level_id', 'federation-trust-level'], ['federation_peers', 'discovery_status_id', 'federation-discovery-status'],
        ['federation_vocabulary_syncs', 'direction_id', 'vocabulary-sync-direction'], ['federation_vocabulary_syncs', 'conflict_rule_id', 'vocabulary-conflict-rule'],
        ['federation_vocabulary_syncs', 'last_status_id', 'harvest-status'], ['federation_vocabulary_sync_log', 'status_id', 'harvest-status'],
        ['federation_vocabulary_changes', 'change_type_id', 'vocabulary-change-type'], ['federation_join_requests', 'status_id', 'join-request-status']];

    public function up(): void
    {
        foreach (self::VOCABULARIES as $name) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$name.ttl")));
        }
        Schema::table('federation_peers', function (Blueprint $table) {
            $table->foreignUuid('trust_level_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->integer('rate_limit_seconds')->nullable();
            // The surfaces it may be asked for (search, union, vocabularies); none named, all it offers.
            $table->jsonb('allowed_surfaces')->nullable();
            $table->foreignUuid('discovery_status_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->text('protocol_version')->nullable();
            $table->jsonb('declared_surfaces')->nullable();
            $table->text('maturity_grade')->nullable();
            $table->jsonb('capabilities')->nullable();
            $table->timestampTz('last_probed_at')->nullable();
        });
        Schema::table('federation_share_settings', function (Blueprint $table) {
            // Drop what a peer answers that is not signed by its pinned key (Heratio's governance require_verified).
            $table->boolean('require_verified')->default(false);
            $table->jsonb('shared_vocabularies')->nullable();
        });
        Schema::create('federation_vocabulary_syncs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('peer_id')->constrained('federation_peers')->cascadeOnDelete();
            $table->foreignUuid('vocabulary_id')->constrained('vocabularies')->cascadeOnDelete();
            // The peer's vocabulary, as its /federation/vocabularies lists it.
            $table->text('remote_vocabulary');
            $table->text('remote_title')->nullable();
            $table->foreignUuid('direction_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('conflict_rule_id')->constrained('concepts')->restrictOnDelete();
            $table->boolean('sync_enabled')->default(true);
            $table->integer('sync_interval_hours')->nullable();
            $table->timestampTz('last_sync_at')->nullable();
            $table->foreignUuid('last_status_id')->nullable()->constrained('concepts')->restrictOnDelete();
            foreach (['last_terms_added', 'last_terms_updated', 'last_conflicts'] as $column) {
                $table->integer($column)->nullable();
            }
            $table->timestampsTz();
            $table->unique(['peer_id', 'remote_vocabulary']);
        });
        Schema::create('federation_vocabulary_sync_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sync_id')->constrained('federation_vocabulary_syncs')->cascadeOnDelete();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            foreach (['terms_added', 'terms_updated', 'terms_skipped', 'conflicts'] as $column) {
                $table->integer($column)->default(0);
            }
            $table->text('error_message')->nullable();
            $table->foreignUuid('initiated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
        });
        Schema::create('federation_vocabulary_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vocabulary_id')->constrained('vocabularies')->cascadeOnDelete();
            $table->uuid('concept_id');
            $table->text('concept_iri');
            $table->foreignUuid('change_type_id')->constrained('concepts')->restrictOnDelete();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at');
            $table->index(['vocabulary_id', 'created_at']);
        });
        Schema::create('federation_join_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->text('institution_name');
            $table->text('contact_name');
            $table->text('contact_email');
            $table->text('base_url');
            $table->text('what_they_share')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignUuid('peer_id')->nullable()->constrained('federation_peers')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['institution_id', 'status_id']);
        });
        foreach (self::CHECKED as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', 'https://workspace.openric.org/vocab/{$vocabulary}/')");
        }
    }

    public function down(): void
    {
        foreach (['federation_join_requests', 'federation_vocabulary_changes', 'federation_vocabulary_sync_log', 'federation_vocabulary_syncs'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('federation_share_settings', fn (Blueprint $table) => $table->dropColumn(['require_verified', 'shared_vocabularies']));
        Schema::table('federation_peers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trust_level_id');
            $table->dropConstrainedForeignId('discovery_status_id');
            $table->dropColumn(['rate_limit_seconds', 'allowed_surfaces', 'protocol_version', 'declared_surfaces', 'maturity_grade', 'capabilities', 'last_probed_at']);
        });
    }
};
