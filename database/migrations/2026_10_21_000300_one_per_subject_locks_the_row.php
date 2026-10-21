<?php

// OpenRiC Workspace - the one-per-subject check locks the subject's row, not an advisory key.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// one_per_subject() made concurrent writers for one subject take turns with a
// transaction-level advisory lock on a key per subject. Those locks live in
// PostgreSQL's shared lock table until the transaction ends, so an import,
// one transaction, took one for every unit, agent and place it wrote: a
// finding aid of about 450 units from the Archives nationales filled the
// table ("out of shared memory", max_locks_per_transaction) and was refused.
// A row lock on the subject gives the same turns and is kept on the row
// itself, so a transaction can hold any number. FOR NO KEY UPDATE, so rows
// that reference the subject can still be written.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement($this->function("EXECUTE format('SELECT 1 FROM %I WHERE id = \$1 FOR NO KEY UPDATE', NEW.subject_table) USING NEW.subject_id;"));
    }

    public function down(): void
    {
        DB::statement($this->function('PERFORM pg_advisory_xact_lock(hashtext(TG_TABLE_NAME || NEW.subject_id::text));'));
    }

    private function function(string $lock): string
    {
        return <<<SQL
            CREATE OR REPLACE FUNCTION one_per_subject() RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE
                type_id uuid := (to_jsonb(NEW) ->> TG_ARGV[0])::uuid;
                per text := CASE WHEN TG_NARGS > 2 THEN to_jsonb(NEW) ->> TG_ARGV[2] END;
                clash boolean;
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM concepts WHERE id = type_id AND iri = TG_ARGV[1]) THEN
                    RETURN NEW;
                END IF;
                $lock
                EXECUTE format('SELECT EXISTS (SELECT 1 FROM %I WHERE subject_table = \$1 AND subject_id = \$2 AND %I = \$3 AND id <> \$4 %s)',
                        TG_TABLE_NAME, TG_ARGV[0], CASE WHEN TG_NARGS > 2 THEN format('AND %I = \$5', TG_ARGV[2]) ELSE '' END)
                    INTO clash USING NEW.subject_table, NEW.subject_id, type_id, NEW.id, per;
                IF clash THEN
                    RAISE EXCEPTION 'only one % of type % for each subject%', TG_TABLE_NAME, TG_ARGV[1],
                        CASE WHEN TG_NARGS > 2 THEN ' and ' || TG_ARGV[2] ELSE '' END USING ERRCODE = 'unique_violation';
                END IF;
                RETURN NEW;
            END \$\$
        SQL;
    }
};
