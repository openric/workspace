<?php

// OpenRiC Workspace - hash chain and signatures on the audit log (ADR 0004).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Each audit row written from now on carries its position in the chain (seq),
// the hash of the row before it, its own hash and an Ed25519 signature, so a
// change made around the application is detected by workspace:audit:verify.
// Rows written before this migration cannot be chained afterwards, because the
// log is append-only; they stay as unchained history, and the check constraint
// (NOT VALID, so it applies to new rows only) makes every new row chained.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_signing_keys', function (Blueprint $table) {
            // kid: first 16 hex characters of SHA-256 over the public key (KeyPair::kid()).
            $table->string('kid', 16)->primary();
            $table->text('public_key');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('retired_at')->nullable();
        });
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_signing_keys_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' OR NEW.kid <> OLD.kid OR NEW.public_key <> OLD.public_key THEN
                    RAISE EXCEPTION 'an audit signing key is kept for verification; it can only be retired'
                        USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER audit_signing_keys_guard BEFORE UPDATE OR DELETE ON audit_signing_keys FOR EACH ROW EXECUTE FUNCTION audit_signing_keys_guard()');

        Schema::table('audit_log', function (Blueprint $table) {
            $table->bigInteger('seq')->nullable()->unique();
            $table->char('prev_hash', 64)->nullable();
            $table->char('entry_hash', 64)->nullable()->unique();
            $table->text('signature')->nullable();
            $table->string('kid', 16)->nullable();
            $table->foreign('kid')->references('kid')->on('audit_signing_keys')->restrictOnDelete();
        });
        DB::statement(<<<'SQL'
            ALTER TABLE audit_log ADD CONSTRAINT audit_log_chained
            CHECK (seq IS NOT NULL AND prev_hash IS NOT NULL AND entry_hash IS NOT NULL AND signature IS NOT NULL AND kid IS NOT NULL)
            NOT VALID
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE audit_log DROP CONSTRAINT IF EXISTS audit_log_chained');
        Schema::table('audit_log', function (Blueprint $table) {
            $table->dropForeign(['kid']);
            $table->dropColumn(['seq', 'prev_hash', 'entry_hash', 'signature', 'kid']);
        });
        Schema::dropIfExists('audit_signing_keys');
        DB::statement('DROP FUNCTION IF EXISTS audit_signing_keys_guard()');
    }
};
