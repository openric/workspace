<?php

// OpenRiC Workspace - heritage at risk (roadmap phase 17d3), after Heratio's ahg-semantic-search: a description
// flagged for prioritised capture, with what threatens it, how urgent it is, why, and where its capture stands, one
// flag a description; flags a federation peer pushes, kept for a curator to accept or decline before any is shown,
// with whether the push was signed by the peer's pinned key; and each institution's choices (whether the public
// catalogue shows its register, whether peers may read it, whether peers may push to it), all off until chosen. Every
// risk, urgency and status is a vocabulary row; the displaced-heritage register keeps nothing, as in Heratio: it is
// read from the provenance and the repository each time.
// Changed from Heratio: a flag and a push belong to an institution, a flag to its description (Heratio's soft
// item_ref) and a push to the peer that sent it; the public register and the network are switched on by the
// institution.
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
        foreach (['endangered-risk', 'endangered-urgency', 'capture-status', 'inbound-flag-status'] as $vocabulary) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$vocabulary.ttl")));
        }

        Schema::create('endangered_settings', function (Blueprint $table) {
            $table->foreignUuid('institution_id')->primary()->constrained('institutions')->cascadeOnDelete();
            // The register, its figures and the network board in the public catalogue.
            $table->boolean('public_register')->default(false);
            // Peers may read the published register (/federation/endangered), signed.
            $table->boolean('share_with_peers')->default(false);
            // Peers may push a flag to it, for a curator to review.
            $table->boolean('accept_pushes')->default(false);
            $table->timestampsTz();
        });

        Schema::create('endangered_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('record_resource_id')->unique()->constrained('record_resources')->cascadeOnDelete();
            $table->foreignUuid('risk_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('urgency_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('capture_status_id')->constrained('concepts')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->foreignUuid('flagged_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['institution_id', 'capture_status_id']);
        });

        // A flag a peer pushed (Heratio's endangered_inbound_flag): never in the register, shown only once accepted.
        Schema::create('endangered_inbound_flags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('peer_id')->nullable()->constrained('federation_peers')->nullOnDelete();
            $table->string('source_peer_base_url', 1024);
            $table->string('source_peer_name', 512)->nullable();
            $table->string('reference', 512);
            // One row a peer and reference: a repeated push updates it.
            $table->char('dedupe_key', 64);
            $table->string('title', 1024)->nullable();
            $table->foreignUuid('risk_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('urgency_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('capture_status_id')->constrained('concepts')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->string('catalogue_url', 1024)->nullable();
            $table->jsonb('payload');
            $table->boolean('peer_verified')->default(false);
            $table->string('key_fingerprint', 128)->nullable();
            $table->foreignUuid('review_status_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('received_at');
            $table->timestampsTz();
            $table->unique(['institution_id', 'dedupe_key']);
            $table->index(['institution_id', 'review_status_id']);
        });

        foreach ([
            ['endangered_items', 'risk_id', 'endangered-risk'], ['endangered_items', 'urgency_id', 'endangered-urgency'], ['endangered_items', 'capture_status_id', 'capture-status'],
            ['endangered_inbound_flags', 'risk_id', 'endangered-risk'], ['endangered_inbound_flags', 'urgency_id', 'endangered-urgency'],
            ['endangered_inbound_flags', 'capture_status_id', 'capture-status'], ['endangered_inbound_flags', 'review_status_id', 'inbound-flag-status'],
        ] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }
    }

    public function down(): void
    {
        foreach (['endangered_inbound_flags', 'endangered_items', 'endangered_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
