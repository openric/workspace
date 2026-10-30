<?php

// OpenRiC Workspace - appraisal, valuation, containers and rights of accessions, after PSIS's ahgAccessionManagePlugin
// AccessionAppraisalService and AccessionContainerService and Heratio's accession appraisal, valuation, containers,
// rights and settings (roadmap phase 9d; ADR 0010).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// An appraisal and its scored criteria, the appraisal templates and the valuations (GRAP 103, IPSAS 45) are rows of the
// accession. Its containers are the Workspace's containers, linked by the storage relation; they gain PSIS's condition,
// weight and item count. Its rights are the Workspace's rights (rico:Rule), now held by an accession too, with what they
// do to access, their conditions, whether they pass to the descriptions made from it, and the right a copy came from.

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
        foreach (config('workspace.bundled_vocabularies') as $file) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$file")));
        }

        Schema::create('accession_appraisals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('accession_id')->constrained('accessions')->cascadeOnDelete();
            $table->foreignUuid('type_id')->constrained('concepts')->restrictOnDelete();
            // The appraiser, an authority record; who recorded it, an account.
            $table->foreignUuid('appraiser_id')->nullable()->constrained('agents')->restrictOnDelete();
            $table->foreignUuid('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('monetary_value', 15, 2)->nullable();
            $table->char('currency', 3)->default('ZAR');
            $table->foreignUuid('significance_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('recommendation_id')->constrained('concepts')->restrictOnDelete();
            $table->text('summary')->nullable();
            $table->text('detailed_notes')->nullable();
            $table->date('appraised_on')->nullable();
            $table->timestampsTz();
            $table->index('accession_id');
        });
        Schema::create('appraisal_criteria', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('appraisal_id')->constrained('accession_appraisals')->cascadeOnDelete();
            $table->text('name');
            $table->smallInteger('score')->nullable();
            $table->decimal('weight', 4, 2)->default(1);
            $table->text('notes')->nullable();
            $table->integer('position')->default(0);
            $table->timestampsTz();
        });
        // A template's criteria: [{name, weight, description}], copied into an appraisal when applied.
        Schema::create('appraisal_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->text('name');
            $table->text('description')->nullable();
            $table->foreignUuid('sector_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->jsonb('criteria');
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();
        });
        Schema::create('accession_valuations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('accession_id')->constrained('accessions')->cascadeOnDelete();
            $table->foreignUuid('type_id')->constrained('concepts')->restrictOnDelete();
            $table->decimal('monetary_value', 15, 2);
            $table->char('currency', 3)->default('ZAR');
            $table->date('valued_on');
            $table->text('valuer')->nullable();
            $table->foreignUuid('method_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->text('reference_document')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['accession_id', 'valued_on']);
        });
        DB::statement("ALTER TABLE accession_appraisals ADD CONSTRAINT accession_appraisals_amount CHECK ((monetary_value IS NULL OR monetary_value >= 0) AND currency ~ '^[A-Z]{3}$')");
        DB::statement("ALTER TABLE accession_valuations ADD CONSTRAINT accession_valuations_amount CHECK (monetary_value >= 0 AND currency ~ '^[A-Z]{3}$')");
        DB::statement("ALTER TABLE appraisal_criteria ADD CONSTRAINT appraisal_criteria_values CHECK (btrim(name) <> '' AND (score IS NULL OR score BETWEEN 1 AND 5) AND weight > 0)");
        DB::statement("ALTER TABLE appraisal_templates ADD CONSTRAINT appraisal_templates_name CHECK (btrim(name) <> '')");
        DB::statement('CREATE UNIQUE INDEX appraisal_templates_one_default ON appraisal_templates (institution_id) WHERE is_default');

        Schema::table('containers', function (Blueprint $table) {
            $table->foreignUuid('condition_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->decimal('weight_kg', 8, 2)->nullable();
            $table->integer('item_count')->nullable();
        });
        DB::statement('ALTER TABLE containers ADD CONSTRAINT containers_weight_items CHECK ((weight_kg IS NULL OR weight_kg > 0) AND (item_count IS NULL OR item_count >= 0))');

        DB::statement('ALTER TABLE rights DROP CONSTRAINT rights_subject');
        DB::statement("ALTER TABLE rights ADD CONSTRAINT rights_subject CHECK (subject_table IN ('record_resources', 'instantiations', 'accessions'))");
        Schema::table('rights', function (Blueprint $table) {
            $table->foreignUuid('access_restriction_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->text('conditions')->nullable();
            // An accession's right passes to the descriptions made from it (Heratio's inherit_to_children).
            $table->boolean('inherit_to_children')->default(true);
            // A description's right copied from its accession's (Heratio's accession_rights_inherited).
            $table->foreignUuid('inherited_from_id')->nullable()->constrained('rights')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE rights ADD CONSTRAINT rights_inherited_by_description CHECK (inherited_from_id IS NULL OR subject_table = 'record_resources')");

        // Heratio's accession_require_appraisal, accession_allow_container_barcodes and accession_rights_inheritance_enabled,
        // as their install seeds them: no appraisal required, barcodes on containers, rights passed down.
        Schema::table('institutions', function (Blueprint $table) {
            $table->boolean('accession_requires_appraisal')->default(false);
            $table->boolean('accession_container_barcodes')->default(true);
            $table->boolean('accession_rights_inheritance')->default(true);
        });

        foreach ([['accession_appraisals', 'type_id', 'appraisal-type'], ['accession_appraisals', 'significance_id', 'appraisal-significance'],
            ['accession_appraisals', 'recommendation_id', 'appraisal-recommendation'], ['appraisal_templates', 'sector_id', 'appraisal-sector'],
            ['accession_valuations', 'type_id', 'valuation-type'], ['accession_valuations', 'method_id', 'valuation-method'],
            ['containers', 'condition_id', 'container-condition'], ['rights', 'access_restriction_id', 'rights-access']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS containers_condition_id_vocabulary ON containers');
        DB::statement('DROP TRIGGER IF EXISTS rights_access_restriction_id_vocabulary ON rights');
        Schema::table('institutions', fn (Blueprint $table) => $table->dropColumn(['accession_requires_appraisal', 'accession_container_barcodes', 'accession_rights_inheritance']));
        DB::statement('ALTER TABLE rights DROP CONSTRAINT rights_inherited_by_description');
        Schema::table('rights', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inherited_from_id');
            $table->dropConstrainedForeignId('access_restriction_id');
            $table->dropColumn(['conditions', 'inherit_to_children']);
        });
        DB::statement('ALTER TABLE rights DROP CONSTRAINT rights_subject');
        DB::statement("ALTER TABLE rights ADD CONSTRAINT rights_subject CHECK (subject_table IN ('record_resources', 'instantiations'))");
        DB::statement('ALTER TABLE containers DROP CONSTRAINT containers_weight_items');
        Schema::table('containers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('condition_id');
            $table->dropColumn(['weight_kg', 'item_count']);
        });
        foreach (['accession_valuations', 'appraisal_templates', 'appraisal_criteria', 'accession_appraisals'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
