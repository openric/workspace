<?php

// OpenRiC Workspace - import keys for activities, which EAC-F imports (roadmap phase 11a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE import_keys DROP CONSTRAINT import_keys_subject_table');
        DB::statement("ALTER TABLE import_keys ADD CONSTRAINT import_keys_subject_table CHECK (subject_table IN ('record_resources', 'agents', 'repositories', 'places', 'concepts', 'activities'))");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM import_keys WHERE subject_table = 'activities'");
        DB::statement('ALTER TABLE import_keys DROP CONSTRAINT import_keys_subject_table');
        DB::statement("ALTER TABLE import_keys ADD CONSTRAINT import_keys_subject_table CHECK (subject_table IN ('record_resources', 'agents', 'repositories', 'places', 'concepts'))");
    }
};
