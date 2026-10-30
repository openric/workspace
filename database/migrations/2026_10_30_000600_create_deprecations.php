<?php

// OpenRiC Workspace - the deprecation register of the Governance profile (section 4): an IRI, once published, is
// permanent. A deleted or merged entity keeps its row (persistent_iri_guard forbids DELETE) and gets a row here: its
// IRI, its class and published name as they were, whether the public could read it, the reason and its successor.
// After Heratio's ric_deprecated_entity (RicDeprecationService), with the Workspace's UUIDs and tables (roadmap phase
// 11b, ADR 0013).
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
        Schema::create('deprecations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('subject_table');
            $table->uuid('subject_id');
            $table->text('iri')->unique();
            $table->text('ric_class');
            $table->text('name')->nullable();
            $table->boolean('was_public')->default(false);
            $table->text('reason')->nullable();
            $table->text('replaced_by_iri')->nullable();
            $table->foreignUuid('deprecated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('deprecated_at');
            $table->unique(['subject_table', 'subject_id']);
        });
        DB::statement("ALTER TABLE deprecations ADD CONSTRAINT deprecations_subject_table CHECK (subject_table IN ('record_resources', 'agents', 'repositories', 'activities', 'places'))");
        DB::statement('ALTER TABLE deprecations ADD CONSTRAINT deprecations_not_itself CHECK (replaced_by_iri IS DISTINCT FROM iri)');

        // What was deleted or merged before the register: not known to have been public, so not answered as deprecated.
        $classes = ['record_resources' => 'rico:RecordResource', 'agents' => 'rico:Agent', 'repositories' => 'rico:CorporateBody', 'activities' => 'rico:Activity', 'places' => 'rico:Place'];
        foreach ($classes as $table => $class) {
            $merged = in_array($table, ['agents', 'places'], true) ? "(SELECT k.iri FROM $table k WHERE k.id = t.merged_into_id)" : 'NULL';
            DB::statement("INSERT INTO deprecations (id, institution_id, subject_table, subject_id, iri, ric_class, reason, replaced_by_iri, deprecated_at)
                SELECT gen_random_uuid(), t.institution_id, '$table', t.id, t.iri, '$class', CASE WHEN $merged IS NULL THEN 'deleted' ELSE 'merged' END, $merged, t.deleted_at
                FROM $table t WHERE t.deleted_at IS NOT NULL AND t.iri IS NOT NULL");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('deprecations');
    }
};
