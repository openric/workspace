<?php

// OpenRiC Workspace - OCFL as the one object store (roadmap phase 15b, ADR 0022): a file's place may change once, when
// workspace:ocfl:migrate moves it from the old layout into its OCFL object, the same bytes under the same checksum.
// Nothing else may change it: the command says so for its own transaction only, with SET LOCAL.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->guard("(NEW.sha256, NEW.size_bytes, NEW.instantiation_id, NEW.use_id) IS DISTINCT FROM (OLD.sha256, OLD.size_bytes, OLD.instantiation_id, OLD.use_id)
                        OR (NEW.storage_path IS DISTINCT FROM OLD.storage_path AND current_setting('workspace.moving_files', true) IS DISTINCT FROM 'on')");
    }

    public function down(): void
    {
        $this->guard('(NEW.sha256, NEW.size_bytes, NEW.storage_path, NEW.instantiation_id, NEW.use_id) IS DISTINCT FROM (OLD.sha256, OLD.size_bytes, OLD.storage_path, OLD.instantiation_id, OLD.use_id)');
    }

    private function guard(string $changed): void
    {
        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION digital_file_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF (SELECT institution_id FROM instantiations WHERE id = NEW.instantiation_id) IS DISTINCT FROM NEW.institution_id THEN
                    RAISE EXCEPTION 'a file belongs to an instantiation of its institution' USING ERRCODE = 'foreign_key_violation';
                END IF;
                -- What was received is never changed: a different file is a new upload.
                IF TG_OP = 'UPDATE' AND ($changed) THEN
                    RAISE EXCEPTION 'a file''s content, place and use do not change; upload a new one' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END \$\$
        SQL);
    }
};
