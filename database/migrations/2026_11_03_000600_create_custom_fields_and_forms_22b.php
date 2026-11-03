<?php

// OpenRiC Workspace - fields an institution adds to its entities, and form templates (roadmap phase 22b), after
// Heratio's ahg-custom-fields (custom_field_definition, custom_field_value) and ahg-forms (ahg_form_template,
// ahg_form_field, ahg_form_assignment). A dropdown draws from one of the institution's vocabularies, not from a list
// of its own.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const KINDS = "('record_resources', 'agents', 'repositories', 'accessions', 'activities')";

    public function up(): void
    {
        app(SkosImporter::class)->import(file_get_contents(resource_path('vocabularies/custom-field-type.ttl')));
        Schema::create('custom_field_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('entity_table');
            $table->string('field_key', 100);
            $table->text('label');
            $table->foreignUuid('field_type_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('vocabulary_id')->nullable()->constrained('vocabularies')->restrictOnDelete();
            $table->text('field_group')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_searchable')->default(false);
            $table->boolean('is_visible_public')->default(true);
            $table->boolean('is_visible_edit')->default(true);
            $table->boolean('is_repeatable')->default(false);
            $table->boolean('include_in_export')->default(true);
            $table->text('default_value')->nullable();
            $table->text('help_text')->nullable();
            $table->text('validation_rule')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['institution_id', 'entity_table', 'field_key']);
        });
        DB::statement('ALTER TABLE custom_field_definitions ADD CONSTRAINT custom_field_definitions_kind_check CHECK (entity_table IN '.self::KINDS." AND field_key ~ '^[a-z][a-z0-9_]*$')");
        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('definition_id')->constrained('custom_field_definitions')->cascadeOnDelete();
            $table->text('subject_table');
            $table->uuid('subject_id');
            $table->text('value_text')->nullable();
            $table->decimal('value_number', 20, 6)->nullable();
            $table->date('value_date')->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->foreignUuid('concept_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->integer('sequence')->default(0);
            $table->timestampsTz();
            $table->index(['subject_table', 'subject_id']);
        });

        Schema::create('form_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('name');
            $table->text('description')->nullable();
            $table->text('entity_table');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('version')->default(1);
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['institution_id', 'name']);
        });
        DB::statement("ALTER TABLE form_templates ADD CONSTRAINT form_templates_kind_check CHECK (entity_table IN ('record_resources', 'agents', 'repositories'))");
        Schema::create('form_template_fields', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('template_id')->constrained('form_templates')->cascadeOnDelete();
            // A description element's notation, or a custom field's key after "custom:".
            $table->text('target');
            $table->text('label')->nullable();
            $table->text('help_text')->nullable();
            $table->text('default_value')->nullable();
            $table->boolean('is_required')->default(false);
            $table->text('section_name')->nullable();
            $table->integer('sort_order')->default(0);
            $table->unique(['template_id', 'target']);
        });
        Schema::create('form_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('template_id')->constrained('form_templates')->cascadeOnDelete();
            $table->foreignUuid('repository_id')->nullable()->constrained('repositories')->cascadeOnDelete();
            $table->foreignUuid('level_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('collection_id')->nullable()->constrained('record_resources')->cascadeOnDelete();
            $table->integer('priority')->default(100);
            $table->boolean('inherit_to_children')->default(true);
            $table->boolean('is_active')->default(true);
        });
        DB::statement("CREATE TRIGGER custom_field_definitions_field_type_id_vocabulary BEFORE INSERT OR UPDATE ON custom_field_definitions FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('field_type_id', 'https://workspace.openric.org/vocab/custom-field-type/')");
    }

    public function down(): void
    {
        foreach (['form_assignments', 'form_template_fields', 'form_templates', 'custom_field_values', 'custom_field_definitions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
