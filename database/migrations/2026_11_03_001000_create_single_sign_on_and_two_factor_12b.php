<?php

// OpenRiC Workspace - single sign-on and two-factor sign-in (roadmap phase 12b, ADR 0021): an institution's OpenID
// Connect providers, after AtoM's arOidcPlugin, each sign-in linked to its account by the provider's subject; a
// sign-in nobody knows becomes a request for an account that an administrator approves, on Dr Johan Pieterse's
// choice of 3 November 2026; and TOTP two-factor sign-in with recovery codes under the institution's policy, after
// Heratio's ahg-security-clearance.
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
        foreach (['sign-in-match', 'two-factor-policy'] as $name) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$name.ttl")));
        }
        Schema::create('sign_in_providers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            // What the button says: "Sign in with <label>".
            $table->text('label');
            $table->text('issuer_url');
            $table->text('client_id');
            // Encrypted by the model.
            $table->text('client_secret');
            $table->jsonb('scopes');
            $table->foreignUuid('match_id')->constrained('concepts')->restrictOnDelete();
            // Roles from a claim, AtoM's set_groups_from_attributes: where the claim is, and which value gives which role.
            $table->text('roles_claim')->nullable();
            $table->jsonb('role_map')->nullable();
            $table->boolean('accepts_requests')->default(true);
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        Schema::create('user_identities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('provider_id')->constrained('sign_in_providers')->cascadeOnDelete();
            $table->text('subject');
            $table->text('email')->nullable();
            $table->timestampTz('last_signed_in_at')->nullable();
            $table->timestampsTz();
            $table->unique(['provider_id', 'subject']);
        });
        Schema::table('registration_requests', function (Blueprint $table) {
            $table->foreignUuid('provider_id')->nullable()->constrained('sign_in_providers')->nullOnDelete();
            $table->text('subject')->nullable();
        });
        Schema::table('users', function (Blueprint $table) {
            // Encrypted by the model; recovery codes are kept as hashes.
            $table->text('two_factor_secret')->nullable();
            $table->timestampTz('two_factor_confirmed_at')->nullable();
            $table->jsonb('two_factor_recovery_codes')->nullable();
            // The last time step a code was used at, so a code is never used twice (RFC 6238 5.2).
            $table->bigInteger('two_factor_last_step')->nullable();
        });
        Schema::table('institutions', function (Blueprint $table) {
            $table->foreignUuid('two_factor_policy_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->integer('two_factor_grace_days')->default(14);
            // When the policy last changed: the grace period runs from then.
            $table->timestampTz('two_factor_policy_since')->nullable();
        });
        foreach ([['sign_in_providers', 'match_id', 'sign-in-match'], ['institutions', 'two_factor_policy_id', 'two-factor-policy']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', 'https://workspace.openric.org/vocab/{$vocabulary}/')");
        }
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS institutions_two_factor_policy_id_vocabulary ON institutions');
        Schema::table('institutions', fn (Blueprint $table) => $table->dropConstrainedForeignId('two_factor_policy_id'));
        Schema::table('institutions', fn (Blueprint $table) => $table->dropColumn(['two_factor_grace_days', 'two_factor_policy_since']));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['two_factor_secret', 'two_factor_confirmed_at', 'two_factor_recovery_codes', 'two_factor_last_step']));
        Schema::table('registration_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('provider_id');
            $table->dropColumn('subject');
        });
        Schema::dropIfExists('user_identities');
        Schema::dropIfExists('sign_in_providers');
    }
};
