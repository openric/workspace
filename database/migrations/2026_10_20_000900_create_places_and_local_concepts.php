<?php

// OpenRiC Workspace - places and the institution's own vocabularies (ADR 0006, decision 6).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// An institution keeps its own concept schemes in the vocabulary tables, each
// of a kind (concept-scheme-kind vocabulary): subjects, occupation types,
// place types, documentary form types. A local concept has the institution's
// IRI, labels, alternative and hidden labels, notes, a broader concept in the
// same scheme, related concepts and matches to concepts elsewhere. Like every
// concept it is deprecated, never deleted; it may move to another scheme of
// the same kind and institution.
//
// A place (rico:Place) is an entity: its names are rico:PlaceName entities,
// its dates, identifiers and description values are those of any entity; it
// has a place type, coordinates, and the place that contains it. Like a
// repository it is marked deleted, never removed, so its IRI stays.
//
// Relations may now connect places and local concepts too.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const VOCAB = 'https://workspace.openric.org/vocab/';

    private const ENTITY_TABLES_BEFORE = "'record_resources', 'repositories'";

    /** The tables a relation may connect; a new kind of entity adds its table here, in its own migration. */
    private const ENTITY_TABLES = "'record_resources', 'repositories', 'places', 'concepts'";

    public function up(): void
    {
        Schema::table('vocabularies', function (Blueprint $table) {
            // Null for a bundled or published vocabulary; set for an institution's own.
            $table->foreignUuid('institution_id')->nullable()->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('kind_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->text('description')->nullable();
            // BCP 47: the language of the title and description of a local vocabulary.
            $table->string('language', 35)->nullable();
        });
        DB::statement('ALTER TABLE vocabularies ADD CONSTRAINT vocabularies_local_whole CHECK ((institution_id IS NULL) = (kind_id IS NULL) AND (institution_id IS NULL) = (language IS NULL))');
        DB::statement("ALTER TABLE vocabularies ADD CONSTRAINT vocabularies_title_not_blank CHECK (btrim(title) <> '')");
        DB::statement("CREATE TRIGGER vocabularies_kind_id_vocabulary BEFORE INSERT OR UPDATE ON vocabularies FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('kind_id', '".self::VOCAB."concept-scheme-kind/')");

        Schema::table('concepts', function (Blueprint $table) {
            $table->foreignUuid('institution_id')->nullable()->constrained('institutions')->restrictOnDelete();
            $table->index('institution_id');
        });
        // A local concept's code is optional (AtoM's "code"); a bundled one is always known by its notation.
        DB::statement('ALTER TABLE concepts ALTER COLUMN notation DROP NOT NULL');
        DB::statement('ALTER TABLE concepts ADD CONSTRAINT concepts_notation CHECK (institution_id IS NOT NULL OR notation IS NOT NULL)');

        // A concept belongs to its vocabulary's institution; a local one's broader concept is in the
        // same scheme and never itself or below itself; its replacement is a concept of the same institution.
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
        DB::statement('CREATE TRIGGER concepts_belong BEFORE INSERT OR UPDATE ON concepts FOR EACH ROW EXECUTE FUNCTION concept_belongs()');

        // The concept guard, as before, except that a local concept may move to another
        // vocabulary of the same institution and kind (its IRI does not name the vocabulary).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION concepts_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'concept % cannot be deleted; deprecate it instead', OLD.iri
                        USING ERRCODE = 'restrict_violation';
                END IF;
                IF NEW.iri <> OLD.iri OR NEW.institution_id IS DISTINCT FROM OLD.institution_id THEN
                    RAISE EXCEPTION 'the IRI and institution of concept % cannot change', OLD.iri
                        USING ERRCODE = 'restrict_violation';
                END IF;
                IF NEW.vocabulary_id <> OLD.vocabulary_id AND (OLD.institution_id IS NULL OR NOT EXISTS (
                    SELECT 1 FROM vocabularies a JOIN vocabularies b ON b.id = NEW.vocabulary_id
                    WHERE a.id = OLD.vocabulary_id AND a.institution_id = b.institution_id AND a.kind_id = b.kind_id)) THEN
                    RAISE EXCEPTION 'concept % can only move to another vocabulary of the same institution and kind', OLD.iri
                        USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END $$
        SQL);

        Schema::table('concept_labels', function (Blueprint $table) {
            // AtoM's source and display notes, and SKOS's history note (skos:historyNote).
            $table->text('history_note')->nullable();
            $table->text('source_note')->nullable();
            $table->text('display_note')->nullable();
        });

        // skos:altLabel and skos:hiddenLabel: AtoM's "use for".
        Schema::create('concept_alt_labels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('concept_id')->constrained('concepts')->cascadeOnDelete();
            $table->string('language', 35);
            $table->text('label');
            $table->boolean('is_hidden')->default(false);
            $table->timestampsTz();
            $table->unique(['concept_id', 'language', 'label']);
        });
        DB::statement("ALTER TABLE concept_alt_labels ADD CONSTRAINT concept_alt_labels_not_blank CHECK (btrim(label) <> '')");

        // skos:related, symmetric, kept once for each pair.
        Schema::create('concept_related', function (Blueprint $table) {
            $table->foreignUuid('concept_id')->constrained('concepts')->cascadeOnDelete();
            $table->foreignUuid('related_id')->constrained('concepts')->cascadeOnDelete();
            $table->timestampsTz();
            $table->primary(['concept_id', 'related_id']);
        });
        DB::statement('ALTER TABLE concept_related ADD CONSTRAINT concept_related_once CHECK (concept_id < related_id)');

        // skos:exactMatch and the other mapping properties, to a concept in another vocabulary.
        Schema::create('concept_matches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('concept_id')->constrained('concepts')->cascadeOnDelete();
            $table->foreignUuid('match_type_id')->constrained('concepts')->restrictOnDelete();
            $table->text('target_iri');
            $table->text('target_label')->nullable();
            $table->text('target_scheme')->nullable();
            $table->timestampsTz();
            $table->unique(['concept_id', 'target_iri']);
        });
        DB::statement("ALTER TABLE concept_matches ADD CONSTRAINT concept_matches_iri CHECK (target_iri ~ '^https?://[^[:space:]]+$')");
        DB::statement("CREATE TRIGGER concept_matches_match_type_id_vocabulary BEFORE INSERT OR UPDATE ON concept_matches FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('match_type_id', '".self::VOCAB."concept-match-type/')");

        // Related concepts and matches are of local concepts, related ones of the same institution.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION concept_link_local() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                owner uuid := (SELECT institution_id FROM concepts WHERE id = NEW.concept_id);
            BEGIN
                IF owner IS NULL OR (TG_TABLE_NAME = 'concept_related'
                   AND (SELECT institution_id FROM concepts WHERE id = (to_jsonb(NEW) ->> 'related_id')::uuid) IS DISTINCT FROM owner) THEN
                    RAISE EXCEPTION 'only concepts of one institution''s own vocabularies are related or matched here' USING ERRCODE = 'foreign_key_violation';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        foreach (['concept_related', 'concept_matches', 'concept_alt_labels'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_local BEFORE INSERT OR UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION concept_link_local()");
        }

        Schema::create('places', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('iri')->unique();
            $table->foreignUuid('place_type_id')->nullable()->constrained('concepts')->restrictOnDelete();
            // rico:isOrWasContainedBy, the place it lies in now; former containment is a relation.
            $table->uuid('parent_id')->nullable();
            // WGS 84 decimal degrees and metres (rico:Coordinates, rico:latitude, rico:longitude, rico:altitude).
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->decimal('altitude', 8, 2)->nullable();
            $table->timestampsTz();
            $table->timestampTz('deleted_at')->nullable();
            $table->index(['institution_id', 'parent_id']);
        });
        Schema::table('places', fn (Blueprint $table) => $table->foreign('parent_id')->references('id')->on('places')->restrictOnDelete());
        DB::statement('ALTER TABLE places ADD CONSTRAINT places_coordinates_whole CHECK ((latitude IS NULL) = (longitude IS NULL) AND (altitude IS NULL OR latitude IS NOT NULL))');
        DB::statement('ALTER TABLE places ADD CONSTRAINT places_coordinates_range CHECK (latitude IS NULL OR (latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180))');
        DB::statement('ALTER TABLE places ADD CONSTRAINT places_not_own_parent CHECK (parent_id IS NULL OR parent_id <> id)');
        DB::statement('CREATE TRIGGER places_iri_guard BEFORE UPDATE OR DELETE ON places FOR EACH ROW EXECUTE FUNCTION persistent_iri_guard()');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION place_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                step uuid := NEW.parent_id;
                depth int := 0;
            BEGIN
                IF NEW.parent_id IS NOT NULL AND (SELECT institution_id FROM places WHERE id = NEW.parent_id) IS DISTINCT FROM NEW.institution_id THEN
                    RAISE EXCEPTION 'a place lies in a place of its institution' USING ERRCODE = 'foreign_key_violation';
                END IF;
                WHILE step IS NOT NULL LOOP
                    IF step = NEW.id OR depth > 1000 THEN
                        RAISE EXCEPTION 'a place cannot lie in itself or in a place it contains' USING ERRCODE = 'check_violation';
                    END IF;
                    SELECT parent_id INTO step FROM places WHERE id = step;
                    depth := depth + 1;
                END LOOP;
                IF NEW.place_type_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM concepts c JOIN vocabularies v ON v.id = c.vocabulary_id JOIN concepts k ON k.id = v.kind_id
                    WHERE c.id = NEW.place_type_id AND c.institution_id = NEW.institution_id
                      AND k.iri = 'https://workspace.openric.org/vocab/concept-scheme-kind/place-type') THEN
                    RAISE EXCEPTION 'a place type is a concept of one of the institution''s place-type vocabularies' USING ERRCODE = 'foreign_key_violation';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER places_guard BEFORE INSERT OR UPDATE ON places FOR EACH ROW EXECUTE FUNCTION place_guard()');

        $this->entityTables(self::ENTITY_TABLES);
    }

    public function down(): void
    {
        $this->entityTables(self::ENTITY_TABLES_BEFORE);
        Schema::dropIfExists('places');
        DB::statement('DROP FUNCTION IF EXISTS place_guard()');
        Schema::dropIfExists('concept_matches');
        Schema::dropIfExists('concept_related');
        Schema::dropIfExists('concept_alt_labels');
        DB::statement('DROP FUNCTION IF EXISTS concept_link_local()');
        Schema::table('concept_labels', fn (Blueprint $table) => $table->dropColumn(['history_note', 'source_note', 'display_note']));
        DB::statement('DROP TRIGGER IF EXISTS concepts_belong ON concepts');
        DB::statement('DROP FUNCTION IF EXISTS concept_belongs()');
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
        DB::statement('ALTER TABLE concepts DROP CONSTRAINT concepts_notation');
        DB::statement('ALTER TABLE concepts ALTER COLUMN notation SET NOT NULL');
        Schema::table('concepts', fn (Blueprint $table) => $table->dropConstrainedForeignId('institution_id'));
        DB::statement('DROP TRIGGER IF EXISTS vocabularies_kind_id_vocabulary ON vocabularies');
        DB::statement('ALTER TABLE vocabularies DROP CONSTRAINT vocabularies_local_whole');
        DB::statement('ALTER TABLE vocabularies DROP CONSTRAINT vocabularies_title_not_blank');
        Schema::table('vocabularies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kind_id');
            $table->dropConstrainedForeignId('institution_id');
            $table->dropColumn(['description', 'language']);
        });
    }

    /** Which tables relations, and the institution check on their ends, accept. */
    private function entityTables(string $tables): void
    {
        DB::statement('ALTER TABLE relations DROP CONSTRAINT relations_entity_tables');
        DB::statement("ALTER TABLE relations ADD CONSTRAINT relations_entity_tables CHECK (source_table IN ($tables) AND target_table IN ($tables) AND (context_table IS NULL OR context_table IN ($tables)))");
        DB::statement('DROP TRIGGER relations_entities ON relations');
        DB::statement("CREATE TRIGGER relations_entities BEFORE INSERT OR UPDATE ON relations FOR EACH ROW EXECUTE FUNCTION relation_entities_in_institution($tables)");
    }
};
