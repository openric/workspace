<?php

// OpenRiC Workspace - duplicate archival descriptions found, reviewed and merged (roadmap phase 22b), after Heratio's
// ahg-dedupe (ahg_duplicate_rule, ahg_duplicate_detection, ahg_merge_log); a merged unit, like a merged agent, keeps
// its IRI and points to the unit it was merged into.
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
        foreach (['duplicate-rule-type', 'duplicate-status'] as $name) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$name.ttl")));
        }
        Schema::create('duplicate_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('repository_id')->nullable()->constrained('repositories')->nullOnDelete();
            $table->text('name');
            $table->foreignUuid('rule_type_id')->constrained('concepts')->restrictOnDelete();
            $table->decimal('threshold', 5, 4)->default(0.8);
            $table->boolean('is_enabled')->default(true);
            $table->integer('priority')->default(100);
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE duplicate_rules ADD CONSTRAINT duplicate_rules_threshold_check CHECK (threshold > 0 AND threshold <= 1)');
        Schema::create('duplicate_detections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('record_a_id')->constrained('record_resources')->cascadeOnDelete();
            $table->foreignUuid('record_b_id')->constrained('record_resources')->cascadeOnDelete();
            $table->decimal('similarity_score', 5, 4);
            $table->foreignUuid('rule_type_id')->constrained('concepts')->restrictOnDelete();
            $table->jsonb('details')->nullable();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->boolean('auto_detected')->default(true);
            $table->foreignUuid('kept_id')->nullable()->constrained('record_resources')->nullOnDelete();
            $table->jsonb('moved')->nullable();
            $table->timestampTz('detected_at');
            $table->unique(['record_a_id', 'record_b_id']);
        });
        DB::statement('ALTER TABLE duplicate_detections ADD CONSTRAINT duplicate_detections_order_check CHECK (record_a_id < record_b_id)');
        Schema::table('record_resources', fn (Blueprint $table) => $table->uuid('merged_into_id')->nullable());
        Schema::table('record_resources', fn (Blueprint $table) => $table->foreign('merged_into_id')->references('id')->on('record_resources')->restrictOnDelete());
        DB::statement('ALTER TABLE record_resources ADD CONSTRAINT record_resources_merged_is_deleted CHECK (merged_into_id IS NULL OR (deleted_at IS NOT NULL AND merged_into_id <> id))');
        foreach ([['duplicate_rules', 'rule_type_id', 'duplicate-rule-type'], ['duplicate_detections', 'rule_type_id', 'duplicate-rule-type'], ['duplicate_detections', 'status_id', 'duplicate-status']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE record_resources DROP CONSTRAINT IF EXISTS record_resources_merged_is_deleted');
        Schema::table('record_resources', fn (Blueprint $table) => $table->dropForeign(['merged_into_id']));
        Schema::table('record_resources', fn (Blueprint $table) => $table->dropColumn('merged_into_id'));
        Schema::dropIfExists('duplicate_detections');
        Schema::dropIfExists('duplicate_rules');
    }
};
