<?php

// OpenRiC Workspace - repatriation claims (roadmap phase 17d2), after Heratio's ahg-semantic-search: a claim that an
// object a description holds belongs with a community elsewhere, documented as a request and where the dialogue
// around it stands, never as a legal determination; who claims it (a community, institution or nation, perhaps an
// authority record), the place it comes from (perhaps a place), who holds it now, the evidence and how to reach the
// claimant; every change of its status, kept; the dialogue between the holding institution and the claimant, a
// message shared on the joint record or kept internal; links that let a claimant's representative read the shared
// record and write in it without an account, of which only the hash is kept; knowledge people contribute about the
// object (oral history, provenance, corrections, the source community), shown once reviewed, its contributor named
// only with consent; and each institution's choices (whether the public may lodge a claim, whether staff and
// claimants are told). Every status, party, visibility and kind is a vocabulary row; managing claims is a
// permission.
// Changed from Heratio: a claim belongs to an institution and its description (Heratio's soft item_ref); the claimant
// and the place of origin may be an authority record and a place as well as their names; the contact email is its
// own field, not found in free text; a link's token is kept hashed, shown once when it is made.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Access\RoleService;
use App\Models\Institution;
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
        foreach (['repatriation-claim-status', 'repatriation-party', 'repatriation-message-visibility', 'repatriation-knowledge-type', 'repatriation-knowledge-status', 'permission'] as $vocabulary) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$vocabulary.ttl")));
        }

        Schema::create('repatriation_settings', function (Blueprint $table) {
            $table->foreignUuid('institution_id')->primary()->constrained('institutions')->cascadeOnDelete();
            // Whether the public catalogue shows the register of claims (the counts, and each claim on a published
            // description as a virtual return, where anyone may contribute knowledge). Off until the institution chooses.
            $table->boolean('public_register')->default(false);
            // Whether anyone may lodge a claim about a published description from the public catalogue.
            $table->boolean('public_lodging')->default(false);
            // Whether those who manage claims, and a claimant with an email, are told of a new claim and each change of status.
            $table->boolean('notify')->default(true);
            $table->timestampsTz();
        });

        Schema::create('repatriation_claims', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->restrictOnDelete();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->string('claimant_community', 512)->nullable();
            $table->foreignUuid('claimant_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->string('origin_place', 512)->nullable();
            $table->foreignUuid('origin_place_id')->nullable()->constrained('places')->nullOnDelete();
            $table->string('current_holder', 512)->nullable();
            $table->text('evidence_summary')->nullable();
            $table->string('contact', 512)->nullable();
            $table->string('contact_email', 255)->nullable();
            $table->text('notes')->nullable();
            // Lodged from the public catalogue, with the submitter's consent, rather than registered by staff.
            $table->boolean('lodged_publicly')->default(false);
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['institution_id', 'status_id']);
            $table->index('record_resource_id');
        });

        // Every change of a claim's status, with who made it and why (Heratio's repatriation_claim_status_log).
        Schema::create('repatriation_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('claim_id')->constrained('repatriation_claims')->cascadeOnDelete();
            $table->foreignUuid('from_status_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('to_status_id')->constrained('concepts')->restrictOnDelete();
            $table->string('note', 1024)->nullable();
            $table->foreignUuid('changed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('changed_by_name', 255)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['claim_id', 'id']);
        });

        // A link to a claim's shared record, for a claimant's representative without an account (Heratio's
        // repatriation_claim_access). Only the token's SHA-256 is kept.
        Schema::create('repatriation_shares', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('claim_id')->constrained('repatriation_claims')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('grantee_name', 255)->nullable();
            $table->foreignUuid('grantee_role_id')->constrained('concepts')->restrictOnDelete();
            $table->boolean('can_message')->default(true);
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        // The dialogue on a claim (Heratio's repatriation_claim_message): not moderated, between named parties.
        Schema::create('repatriation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('claim_id')->constrained('repatriation_claims')->cascadeOnDelete();
            $table->foreignUuid('author_role_id')->constrained('concepts')->restrictOnDelete();
            $table->string('author_name', 255)->nullable();
            $table->foreignUuid('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('share_id')->nullable()->constrained('repatriation_shares')->nullOnDelete();
            $table->text('body');
            $table->foreignUuid('visibility_id')->constrained('concepts')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['claim_id', 'id']);
        });

        // Knowledge contributed about the object a claim concerns (Heratio's repatriation_knowledge_contribution).
        Schema::create('repatriation_knowledge', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('claim_id')->constrained('repatriation_claims')->cascadeOnDelete();
            $table->foreignUuid('type_id')->constrained('concepts')->restrictOnDelete();
            $table->text('body');
            $table->string('source', 512)->nullable();
            // Kept only when the contributor consented to be named.
            $table->string('contributor_name', 255)->nullable();
            $table->boolean('credit_consent')->default(false);
            $table->foreignUuid('contributed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('moderated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('moderated_at')->nullable();
            $table->timestampsTz();
            $table->index(['claim_id', 'status_id']);
            $table->index(['institution_id', 'status_id']);
        });
        DB::statement('ALTER TABLE repatriation_knowledge ADD CONSTRAINT repatriation_knowledge_named_with_consent CHECK (contributor_name IS NULL OR credit_consent)');

        foreach ([
            ['repatriation_claims', 'status_id', 'repatriation-claim-status'], ['repatriation_status_changes', 'from_status_id', 'repatriation-claim-status'],
            ['repatriation_status_changes', 'to_status_id', 'repatriation-claim-status'], ['repatriation_shares', 'grantee_role_id', 'repatriation-party'],
            ['repatriation_messages', 'author_role_id', 'repatriation-party'], ['repatriation_messages', 'visibility_id', 'repatriation-message-visibility'],
            ['repatriation_knowledge', 'type_id', 'repatriation-knowledge-type'], ['repatriation_knowledge', 'status_id', 'repatriation-knowledge-status'],
        ] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }

        foreach (Institution::all() as $institution) {
            DB::transaction(fn () => app(RoleService::class)->grantKind($institution, 'institution', ['manage-repatriation']));
        }
    }

    public function down(): void
    {
        foreach (['repatriation_knowledge', 'repatriation_messages', 'repatriation_shares', 'repatriation_status_changes', 'repatriation_claims', 'repatriation_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
