<?php

// OpenRiC Workspace - the IIIF viewer layouts an account saves, after Heratio's ahg_iiif_workspace, and the IIIF
// validation results, after PSIS's iiif_validation_result (roadmap phase 14b, ADR 0014). A check and its outcome are
// vocabulary rows.
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
        foreach (['iiif-validation-check.ttl', 'iiif-validation-status.ttl'] as $file) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$file")));
        }

        Schema::create('iiif_viewer_workspaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('name');
            // The viewer's windows: each a manifest of this Workspace and the canvas it shows.
            $table->jsonb('windows');
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE iiif_viewer_workspaces ADD CONSTRAINT iiif_viewer_workspaces_values CHECK (btrim(name) <> '' AND jsonb_typeof(windows) = 'array')");
        DB::statement('CREATE UNIQUE INDEX iiif_viewer_workspaces_one_default ON iiif_viewer_workspaces (user_id) WHERE is_default');

        Schema::create('iiif_validation_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->cascadeOnDelete();
            $table->foreignUuid('check_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->text('details');
            // One run of the checks: the results of a record validated together.
            $table->uuid('run_id');
            $table->foreignUuid('validated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('validated_at');
            $table->index(['record_resource_id', 'validated_at']);
            $table->index(['institution_id', 'validated_at']);
        });
        foreach ([['check_id', 'iiif-validation-check'], ['status_id', 'iiif-validation-status']] as [$column, $vocabulary]) {
            DB::statement("CREATE TRIGGER iiif_validation_results_{$column}_vocabulary BEFORE INSERT OR UPDATE ON iiif_validation_results FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('iiif_validation_results');
        Schema::dropIfExists('iiif_viewer_workspaces');
    }
};
