<?php

// OpenRiC Workspace - repositories and the language values that describe them.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// An entity row holds identity only: its institution, persistent IRI and
// identifier (logical model, sections 1 and 3). Every descriptive text is a
// language value (section 5): one row per element, language and entity, the
// element and the state being concepts of their own vocabularies. The field
// set follows ISDIAH, as Heratio's ahg-repository-manage does; no code was
// taken from it, because its data access is AtoM's (prohibited-copy rule 3).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const VOCAB = 'https://workspace.openric.org/vocab/';

    public function up(): void
    {
        Schema::create('repositories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('iri')->unique();
            // ISDIAH 5.1.1 Identifier, as the institution uses it.
            $table->string('identifier', 255)->nullable();
            $table->timestampsTz();
            $table->timestampTz('deleted_at')->nullable();
            $table->unique(['institution_id', 'identifier']);
        });
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION persistent_iri_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION '% % cannot be deleted; mark it deleted instead', TG_TABLE_NAME, OLD.iri
                        USING ERRCODE = 'restrict_violation';
                END IF;
                IF NEW.iri <> OLD.iri OR NEW.institution_id <> OLD.institution_id THEN
                    RAISE EXCEPTION 'the persistent IRI and institution of % cannot change', OLD.iri
                        USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER repositories_iri_guard BEFORE UPDATE OR DELETE ON repositories FOR EACH ROW EXECUTE FUNCTION persistent_iri_guard()');

        Schema::create('description_values', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            // The entity described: its table and id. One table serves every kind of entity.
            $table->text('subject_table');
            $table->uuid('subject_id');
            $table->foreignUuid('element_id')->constrained('concepts')->restrictOnDelete();
            // BCP 47, at most 35 characters in practice (RFC 5646, section 4.4.1).
            $table->string('language', 35);
            $table->text('value');
            $table->boolean('is_original')->default(true);
            $table->foreignUuid('state_id')->constrained('concepts')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['subject_table', 'subject_id', 'element_id', 'language']);
            $table->index(['institution_id', 'subject_table', 'subject_id']);
        });
        foreach (['element_id' => 'description-element/', 'state_id' => 'language-value-state/'] as $column => $vocabulary) {
            $iri = self::VOCAB.$vocabulary;
            DB::statement("CREATE TRIGGER description_values_{$column}_vocabulary BEFORE INSERT OR UPDATE ON description_values FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '{$iri}')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('description_values');
        Schema::dropIfExists('repositories');
        DB::statement('DROP FUNCTION IF EXISTS persistent_iri_guard()');
    }
};
