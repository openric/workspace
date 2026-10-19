<?php

// OpenRiC Workspace - vocabularies, concepts and their labels.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Every controlled value in the Workspace is a concept in one of these tables,
// never an enumeration (SPECIFICATION.md, hard constraints; logical model,
// section 2). The database itself refuses to delete a concept or change its
// IRI, so an assertion that cites a concept can always be read back.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vocabularies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('iri')->unique();
            $table->text('title');
            $table->text('source_url')->nullable();
            $table->boolean('institution_may_extend')->default(false);
            $table->timestampTz('imported_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('concepts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vocabulary_id')->constrained('vocabularies')->restrictOnDelete();
            $table->text('iri')->unique();
            $table->text('notation');
            $table->uuid('broader_id')->nullable();
            $table->timestampTz('deprecated_at')->nullable();
            $table->uuid('replaced_by_id')->nullable();
            $table->timestampsTz();
            $table->unique(['vocabulary_id', 'notation']);
        });

        // Links between concepts, added once the primary key exists.
        Schema::table('concepts', function (Blueprint $table) {
            $table->foreign('broader_id')->references('id')->on('concepts')->restrictOnDelete();
            $table->foreign('replaced_by_id')->references('id')->on('concepts')->restrictOnDelete();
        });

        Schema::create('concept_labels', function (Blueprint $table) {
            $table->foreignUuid('concept_id')->constrained('concepts')->cascadeOnDelete();
            // BCP 47 tags are at most 35 characters in practice (RFC 5646, section 4.4.1).
            $table->string('language', 35);
            $table->text('pref_label');
            $table->text('definition')->nullable();
            $table->text('scope_note')->nullable();
            $table->timestampsTz();
            $table->primary(['concept_id', 'language']);
        });

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION concepts_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'concept % cannot be deleted; deprecate it instead', OLD.iri
                        USING ERRCODE = 'restrict_violation';
                END IF;
                IF NEW.iri <> OLD.iri OR NEW.vocabulary_id <> OLD.vocabulary_id THEN
                    RAISE EXCEPTION 'the IRI and vocabulary of concept % cannot change', OLD.iri
                        USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER concepts_guard BEFORE UPDATE OR DELETE ON concepts FOR EACH ROW EXECUTE FUNCTION concepts_guard()');
    }

    public function down(): void
    {
        Schema::dropIfExists('concept_labels');
        Schema::dropIfExists('concepts');
        DB::statement('DROP FUNCTION IF EXISTS concepts_guard()');
        Schema::dropIfExists('vocabularies');
    }
};
