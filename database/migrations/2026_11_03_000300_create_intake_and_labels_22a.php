<?php

// OpenRiC Workspace - intake and labels (roadmap phase 22a): column mappings kept by name, after PSIS's ahgIngestPlugin
// (ingest_mapping); watched folders, which take in what is dropped in them, an FTP or SFTP drop among them, after PSIS's
// ingest_watch_folder and Heratio's ahg-scan, with the log of every file they and the scan API took in (Heratio's scan
// inbox); label templates after Heratio's ahg-label (label_template). SharePoint intake waits for a pilot (Dr Johan
// Pieterse's choice 2B of 3 November 2026).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const VOCAB = 'https://workspace.openric.org/vocab/';

    public function up(): void
    {
        foreach (['label-page-size', 'label-barcode-source', 'label-qr-target', 'intake-outcome'] as $name) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$name.ttl")));
        }
        $institution = fn (Blueprint $table) => $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
        $who = fn (Blueprint $table, string $column) => $table->foreignUuid($column)->nullable()->constrained('users')->nullOnDelete();

        Schema::create('import_mappings', function (Blueprint $table) use ($institution, $who) {
            $table->uuid('id')->primary();
            $institution($table);
            $table->text('name');
            $table->text('format');
            // Each heading: the column it becomes, a value for empty cells, a transform.
            $table->jsonb('columns');
            $who($table, 'created_by_id');
            $table->timestampsTz();
            $table->unique(['institution_id', 'name']);
        });

        Schema::create('watch_folders', function (Blueprint $table) use ($institution, $who) {
            $table->uuid('id')->primary();
            $institution($table);
            $table->text('label');
            // A folder under the installation's intake root (WORKSPACE_INTAKE_ROOT), named relative to it.
            $table->text('path');
            // What a table or description file is imported as, and where.
            $table->text('format')->default('csv');
            $table->text('language')->default('en');
            $table->foreignUuid('repository_id')->constrained('repositories')->restrictOnDelete();
            $table->foreignUuid('parent_id')->nullable()->constrained('record_resources')->nullOnDelete();
            $table->foreignUuid('import_mapping_id')->nullable()->constrained('import_mappings')->nullOnDelete();
            // A single file becomes a record of this level under the parent, its title from a sidecar or its name.
            $table->foreignUuid('level_id')->constrained('concepts')->restrictOnDelete();
            $table->boolean('auto_commit')->default(true);
            // Whose permissions the folder works with, as PSIS's user_id.
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->timestampTz('last_scan_at')->nullable();
            $table->text('last_status')->nullable();
            $table->integer('files_taken')->default(0);
            $who($table, 'created_by_id');
            $table->timestampsTz();
            $table->unique(['institution_id', 'path']);
        });

        Schema::create('intake_files', function (Blueprint $table) use ($institution) {
            $table->uuid('id')->primary();
            $institution($table);
            $table->foreignUuid('watch_folder_id')->nullable()->constrained('watch_folders')->nullOnDelete();
            $table->foreignUuid('api_key_id')->nullable()->constrained('api_keys')->nullOnDelete();
            $table->text('file_name');
            $table->char('sha256', 64)->nullable();
            $table->bigInteger('size_bytes')->nullable();
            $table->foreignUuid('outcome_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('import_id')->nullable()->constrained('imports')->nullOnDelete();
            $table->foreignUuid('record_resource_id')->nullable()->constrained('record_resources')->nullOnDelete();
            $table->text('message')->nullable();
            $table->timestampTz('created_at');
            $table->index(['institution_id', 'created_at']);
        });

        Schema::create('label_templates', function (Blueprint $table) use ($institution, $who) {
            $table->uuid('id')->primary();
            $institution($table);
            $table->text('name');
            $table->foreignUuid('page_size_id')->constrained('concepts')->restrictOnDelete();
            $table->smallInteger('columns')->default(3);
            $table->smallInteger('rows')->default(8);
            $table->decimal('label_width_mm', 6, 2)->default(63.50);
            $table->decimal('label_height_mm', 6, 2)->default(33.90);
            $table->decimal('margin_mm', 5, 2)->default(10.00);
            $table->decimal('gutter_mm', 5, 2)->default(2.50);
            $table->smallInteger('font_size_pt')->default(9);
            $table->boolean('show_title')->default(true);
            $table->boolean('show_identifier')->default(true);
            $table->boolean('show_repository')->default(false);
            $table->boolean('show_barcode')->default(true);
            $table->foreignUuid('barcode_source_id')->constrained('concepts')->restrictOnDelete();
            $table->boolean('show_qr')->default(false);
            $table->foreignUuid('qr_target_id')->constrained('concepts')->restrictOnDelete();
            $table->boolean('is_default')->default(false);
            $who($table, 'created_by_id');
            $table->timestampsTz();
            $table->unique(['institution_id', 'name']);
        });
        DB::statement('ALTER TABLE label_templates ADD CONSTRAINT label_templates_grid_check CHECK (columns BETWEEN 1 AND 10 AND rows BETWEEN 1 AND 30 AND label_width_mm > 0 AND label_height_mm > 0 AND font_size_pt BETWEEN 5 AND 24)');

        foreach ([['watch_folders', 'level_id', 'level-of-description'], ['intake_files', 'outcome_id', 'intake-outcome'], ['label_templates', 'page_size_id', 'label-page-size'],
            ['label_templates', 'barcode_source_id', 'label-barcode-source'], ['label_templates', 'qr_target_id', 'label-qr-target']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }
    }

    public function down(): void
    {
        foreach (['label_templates', 'intake_files', 'watch_folders', 'import_mappings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
