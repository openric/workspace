<?php

// OpenRiC Workspace - the report builder (roadmap phase 8; WS-016).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// PSIS's ahgReportBuilderPlugin and Heratio's ahg-reports: custom_report (a saved report: what it is on,
// its columns, filters and sort, its status), report_section (what it is made of, in order) and
// report_template (a structure to start from). The data source is a kind of entity (EntityKinds), its
// columns the elements of that kind's standard, never a table or SQL. Status and section type are concepts.
// The bundled templates are rows with no institution.

use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const VOCAB = 'https://workspace.openric.org/vocab/';

    public function up(): void
    {
        foreach (config('workspace.bundled_vocabularies') as $file) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$file")));
        }
        Schema::create('report_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Null: bundled with the Workspace, for every institution.
            $table->foreignUuid('institution_id')->nullable()->constrained('institutions')->cascadeOnDelete();
            $table->text('name');
            $table->text('description')->nullable();
            // {source, columns, filters, sort, sections: [{type, title, content, config}]}
            $table->jsonb('structure');
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        Schema::create('custom_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->text('name');
            $table->text('description')->nullable();
            // A kind of entity (EntityKinds): record, agent, repository, activity, place, instantiation or container.
            $table->text('source');
            $table->jsonb('columns');
            $table->jsonb('filters')->default('{}');
            $table->jsonb('sort')->default('{}');
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            // Shared: every account that may read its kind sees it; otherwise only its owner.
            $table->boolean('shared')->default(false);
            $table->foreignUuid('template_id')->nullable()->constrained('report_templates')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['institution_id', 'updated_at']);
        });
        Schema::create('custom_report_sections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_id')->constrained('custom_reports')->cascadeOnDelete();
            $table->foreignUuid('type_id')->constrained('concepts')->restrictOnDelete();
            $table->text('title')->nullable();
            $table->text('content')->nullable();
            $table->jsonb('config')->default('{}');
            $table->integer('position')->default(0);
            $table->timestampsTz();
            $table->index(['report_id', 'position']);
        });
        DB::statement("CREATE TRIGGER custom_reports_status_id_vocabulary BEFORE INSERT OR UPDATE ON custom_reports FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('status_id', '".self::VOCAB."report-status/')");
        DB::statement("CREATE TRIGGER custom_report_sections_type_id_vocabulary BEFORE INSERT OR UPDATE ON custom_report_sections FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('type_id', '".self::VOCAB."report-section-type/')");

        foreach (json_decode(file_get_contents(resource_path('report-templates.json')), true) as $template) {
            DB::table('report_templates')->insert(['id' => (string) Str::uuid7(), 'name' => $template['name'], 'description' => $template['description'],
                'structure' => json_encode($template['structure']), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_report_sections');
        Schema::dropIfExists('custom_reports');
        Schema::dropIfExists('report_templates');
    }
};
