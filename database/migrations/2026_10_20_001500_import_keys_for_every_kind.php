<?php

// OpenRiC Workspace - import keys for every kind of entity an import makes, and imports with no holding repository (WS-015).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// An import of authority records, repositories, subjects or places keys each
// entity to its source as an import of descriptions keys each unit, so the
// key names the table and row it became. A key is unique within its source
// and kind: AtoM numbers its actors, repositories and terms apart from its
// descriptions only by convention. Only an import of descriptions needs the
// repository that holds its top-level units.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_keys', function (Blueprint $table) {
            $table->text('subject_table')->nullable();
            $table->uuid('subject_id')->nullable();
        });
        DB::statement("UPDATE import_keys SET subject_table = 'record_resources', subject_id = record_resource_id");
        Schema::table('import_keys', function (Blueprint $table) {
            $table->dropUnique(['institution_id', 'source', 'key']);
            $table->dropConstrainedForeignId('record_resource_id');
        });
        DB::statement('ALTER TABLE import_keys ALTER COLUMN subject_table SET NOT NULL, ALTER COLUMN subject_id SET NOT NULL');
        DB::statement("ALTER TABLE import_keys ADD CONSTRAINT import_keys_subject_table CHECK (subject_table IN ('record_resources', 'agents', 'repositories', 'places', 'concepts'))");
        Schema::table('import_keys', function (Blueprint $table) {
            $table->unique(['institution_id', 'source', 'subject_table', 'key']);
            $table->index(['subject_table', 'subject_id']);
        });
        DB::statement('ALTER TABLE imports ALTER COLUMN repository_id DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement("DELETE FROM import_keys WHERE subject_table <> 'record_resources'");
        Schema::table('import_keys', function (Blueprint $table) {
            $table->dropUnique(['institution_id', 'source', 'subject_table', 'key']);
            $table->dropIndex(['subject_table', 'subject_id']);
            $table->foreignUuid('record_resource_id')->nullable()->constrained('record_resources')->restrictOnDelete();
        });
        DB::statement('UPDATE import_keys SET record_resource_id = subject_id');
        DB::statement('ALTER TABLE import_keys DROP CONSTRAINT import_keys_subject_table');
        Schema::table('import_keys', function (Blueprint $table) {
            $table->dropColumn(['subject_table', 'subject_id']);
            $table->unique(['institution_id', 'source', 'key']);
        });
        DB::statement('ALTER TABLE imports ALTER COLUMN repository_id SET NOT NULL');
    }
};
