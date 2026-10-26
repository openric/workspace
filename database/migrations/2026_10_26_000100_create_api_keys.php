<?php

// OpenRiC Workspace - API keys (ADR 0007; Heratio's ahg_api_key and ahg_api_rate_limit).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A key belongs to one account of one institution. Only the SHA-256 of the key
// is kept, with its first eight characters to tell keys apart; the key itself
// is shown once, when it is made. Its scopes are concepts of the api-scope
// vocabulary. A revoked key keeps its row, so the audit log can still name it.
// Requests are counted per key and hour, for the hourly and daily limits.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 100);
            $table->char('key_hash', 64)->unique();
            $table->string('prefix', 8);
            $table->integer('hourly_limit');
            $table->integer('daily_limit')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignUuid('revoked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['user_id', 'revoked_at']);
        });
        DB::statement('ALTER TABLE api_keys ADD CONSTRAINT api_keys_limits_positive CHECK (hourly_limit > 0 AND (daily_limit IS NULL OR daily_limit > 0))');

        Schema::create('api_key_scopes', function (Blueprint $table) {
            $table->foreignUuid('api_key_id')->constrained('api_keys')->cascadeOnDelete();
            $table->foreignUuid('scope_id')->constrained('concepts')->restrictOnDelete();
            $table->primary(['api_key_id', 'scope_id']);
        });

        Schema::create('api_key_usage', function (Blueprint $table) {
            $table->foreignUuid('api_key_id')->constrained('api_keys')->cascadeOnDelete();
            $table->timestampTz('hour');
            $table->integer('requests')->default(0);
            $table->primary(['api_key_id', 'hour']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_key_usage');
        Schema::dropIfExists('api_key_scopes');
        Schema::dropIfExists('api_keys');
    }
};
