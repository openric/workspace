<?php

// OpenRiC Workspace - the versions of units of description and authority records (roadmap phase 22b), after Heratio's
// information_object_version and actor_version, one table for both.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('subject_table');
            $table->uuid('subject_id');
            $table->integer('version_number');
            // The whole of what the entity said: values, names, dates, identifiers, relations, where it sat.
            $table->jsonb('snapshot');
            $table->jsonb('changed_fields')->nullable();
            $table->text('change_summary')->nullable();
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_restore')->default(false);
            $table->integer('restored_from_version')->nullable();
            $table->timestampTz('created_at');
            $table->unique(['subject_table', 'subject_id', 'version_number']);
        });
        DB::statement("ALTER TABLE entity_versions ADD CONSTRAINT entity_versions_subject_check CHECK (subject_table IN ('record_resources', 'agents') AND version_number > 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_versions');
    }
};
