<?php

// OpenRiC Workspace - account roles, role grants and the audit log.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Roles and permissions are concepts (no enumerations), and which role holds
// which permission is data in role_grants. Anything not granted is denied.
//
// Two rules are enforced by the database, not only by the application:
// - a column that cites a concept accepts only a concept from its own
//   vocabulary (concept_in_vocabulary trigger);
// - the audit log is append-only: no row is ever changed or removed.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const VOCAB = 'https://workspace.openric.org/vocab/';

    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION concept_in_vocabulary() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                concept uuid := (to_jsonb(NEW) ->> TG_ARGV[0])::uuid;
            BEGIN
                IF concept IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM concepts c JOIN vocabularies v ON v.id = c.vocabulary_id
                    WHERE c.id = concept AND v.iri = TG_ARGV[1]
                ) THEN
                    RAISE EXCEPTION '%.% must be a concept of %', TG_TABLE_NAME, TG_ARGV[0], TG_ARGV[1]
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$
        SQL);

        Schema::create('account_roles', function (Blueprint $table) {
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('role_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('granted_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('granted_at')->useCurrent();
            $table->primary(['user_id', 'role_id']);
        });
        $this->requireConcept('account_roles', 'role_id', 'account-role/');

        Schema::create('role_grants', function (Blueprint $table) {
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('role_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('permission_id')->constrained('concepts')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['institution_id', 'role_id', 'permission_id']);
        });
        $this->requireConcept('role_grants', 'role_id', 'account-role/');
        $this->requireConcept('role_grants', 'permission_id', 'permission/');

        Schema::create('audit_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // clock_timestamp(), not now(): now() is fixed for a whole transaction, so
            // rows written together would share one time and lose their order.
            $table->timestampTz('occurred_at', 6)->default(DB::raw('clock_timestamp()'));
            $table->foreignUuid('institution_id')->nullable()->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('action_id')->constrained('concepts')->restrictOnDelete();
            $table->text('subject_table')->nullable();
            $table->uuid('subject_id')->nullable();
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->text('request_id')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->index(['institution_id', 'occurred_at']);
            $table->index(['subject_table', 'subject_id']);
        });
        $this->requireConcept('audit_log', 'action_id', 'audit-action/');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_log_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'the audit log is append-only; % is not allowed', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END $$
        SQL);
        DB::statement('CREATE TRIGGER audit_log_append_only BEFORE UPDATE OR DELETE ON audit_log FOR EACH ROW EXECUTE FUNCTION audit_log_append_only()');
        DB::statement('CREATE TRIGGER audit_log_no_truncate BEFORE TRUNCATE ON audit_log FOR EACH STATEMENT EXECUTE FUNCTION audit_log_append_only()');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('role_grants');
        Schema::dropIfExists('account_roles');
        DB::statement('DROP FUNCTION IF EXISTS audit_log_append_only()');
        DB::statement('DROP FUNCTION IF EXISTS concept_in_vocabulary()');
    }

    private function requireConcept(string $table, string $column, string $vocabulary): void
    {
        $iri = self::VOCAB.$vocabulary;
        DB::statement("CREATE TRIGGER {$table}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '{$iri}')");
    }
};
