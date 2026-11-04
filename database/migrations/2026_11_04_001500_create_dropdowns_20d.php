<?php

// OpenRiC Workspace - the Dropdown manager (roadmap phase 20d), after Heratio's ahg-dropdown-manage: what an institution
// makes of the bundled vocabularies its forms offer, without changing them for anyone else: its own labels for a term in
// any language, the order its terms are offered in, the term chosen by default, and, for the vocabularies open to it,
// terms it hides. Terms an institution adds to an open vocabulary are concepts of that vocabulary that are its own
// (concepts.institution_id): the concept guard now lets such a concept sit in a bundled vocabulary, and a code is unique
// in a vocabulary for the bundled terms and for each institution's own, so two institutions may each add the same one.
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
        Schema::create('vocabulary_overlays', function (Blueprint $table) {
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('concept_id')->constrained('concepts')->cascadeOnDelete();
            // The institution's own labels, by language: {"en": "...", "af": "..."}.
            $table->jsonb('labels')->default('{}');
            $table->integer('sort_order')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('hidden')->default(false);
            $table->foreignUuid('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->primary(['institution_id', 'concept_id']);
        });
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION concept_belongs() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                step uuid := NEW.broader_id;
                depth int := 0;
            BEGIN
                -- An institution's own term in a bundled (installation-wide) vocabulary is allowed (phase 20d).
                IF NEW.institution_id IS DISTINCT FROM (SELECT institution_id FROM vocabularies WHERE id = NEW.vocabulary_id)
                   AND (NEW.institution_id IS NULL OR (SELECT institution_id FROM vocabularies WHERE id = NEW.vocabulary_id) IS NOT NULL) THEN
                    RAISE EXCEPTION 'a concept belongs to the institution of its vocabulary' USING ERRCODE = 'foreign_key_violation';
                END IF;
                IF NEW.institution_id IS NULL THEN
                    RETURN NEW;
                END IF;
                IF NEW.broader_id IS NOT NULL AND (SELECT vocabulary_id FROM concepts WHERE id = NEW.broader_id) IS DISTINCT FROM NEW.vocabulary_id THEN
                    RAISE EXCEPTION 'a broader concept is in the same vocabulary' USING ERRCODE = 'check_violation';
                END IF;
                WHILE step IS NOT NULL LOOP
                    IF step = NEW.id OR depth > 1000 THEN
                        RAISE EXCEPTION 'a concept cannot be broader than itself' USING ERRCODE = 'check_violation';
                    END IF;
                    SELECT broader_id INTO step FROM concepts WHERE id = step;
                    depth := depth + 1;
                END LOOP;
                IF NEW.replaced_by_id IS NOT NULL AND (NEW.replaced_by_id = NEW.id
                   OR (SELECT institution_id FROM concepts WHERE id = NEW.replaced_by_id) IS DISTINCT FROM NEW.institution_id) THEN
                    RAISE EXCEPTION 'a concept is replaced by another concept of its institution' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        Schema::table('concepts', fn (Blueprint $table) => $table->dropUnique(['vocabulary_id', 'notation']));
        DB::statement('CREATE UNIQUE INDEX concepts_bundled_notation_unique ON concepts (vocabulary_id, notation) WHERE institution_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX concepts_own_notation_unique ON concepts (vocabulary_id, institution_id, notation) WHERE institution_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('vocabulary_overlays');
        DB::statement('DROP INDEX IF EXISTS concepts_own_notation_unique');
        DB::statement('DROP INDEX IF EXISTS concepts_bundled_notation_unique');
        Schema::table('concepts', fn (Blueprint $table) => $table->unique(['vocabulary_id', 'notation']));
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION concept_belongs() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                step uuid := NEW.broader_id;
                depth int := 0;
            BEGIN
                IF NEW.institution_id IS DISTINCT FROM (SELECT institution_id FROM vocabularies WHERE id = NEW.vocabulary_id) THEN
                    RAISE EXCEPTION 'a concept belongs to the institution of its vocabulary' USING ERRCODE = 'foreign_key_violation';
                END IF;
                IF NEW.institution_id IS NULL THEN
                    RETURN NEW;
                END IF;
                IF NEW.broader_id IS NOT NULL AND (SELECT vocabulary_id FROM concepts WHERE id = NEW.broader_id) IS DISTINCT FROM NEW.vocabulary_id THEN
                    RAISE EXCEPTION 'a broader concept is in the same vocabulary' USING ERRCODE = 'check_violation';
                END IF;
                WHILE step IS NOT NULL LOOP
                    IF step = NEW.id OR depth > 1000 THEN
                        RAISE EXCEPTION 'a concept cannot be broader than itself' USING ERRCODE = 'check_violation';
                    END IF;
                    SELECT broader_id INTO step FROM concepts WHERE id = step;
                    depth := depth + 1;
                END LOOP;
                IF NEW.replaced_by_id IS NOT NULL AND (NEW.replaced_by_id = NEW.id
                   OR (SELECT institution_id FROM concepts WHERE id = NEW.replaced_by_id) IS DISTINCT FROM NEW.institution_id) THEN
                    RAISE EXCEPTION 'a concept is replaced by another concept of its institution' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$
        SQL);
    }
};
