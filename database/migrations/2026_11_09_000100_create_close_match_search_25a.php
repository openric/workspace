<?php

// OpenRiC Workspace - close matches for a search that finds nothing (roadmap phase 25a, after docuteam context's fuzzy
// search; heratio#1583): PostgreSQL's pg_trgm and a trigram index on the indexed titles. pg_trgm is a trusted extension
// (PostgreSQL 13 and later), so the database's owner, which the Workspace connects as, may create it; no superuser.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX IF NOT EXISTS search_entries_title_trgm ON search_entries USING gin (title gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS search_entries_title_trgm');
    }
};
