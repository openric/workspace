<?php

// OpenRiC Workspace - reified RiC relations, their evidence and the assertions that hold them (ADR 0006, WS-006).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// One table holds every relation between two entities, in the direction of
// its RiC-O shortcut property: a type from the relation-type vocabulary, a
// source and a target (table and id), and optionally an agent role (OpenRiC
// role-type), a context entity (rico:relationHasContext), a state
// (rico:relationState), a certainty (rico:relationCertainty), a source of
// information (rico:relationSource) and a description. Its dates are rico:Date
// entities of the relation (rico:relationHasDate, beginning, end). A unit of
// description that proves it is linked by rico:evidences.
//
// A relation is held through assertions: who asserted it (an account, an
// import or a model), when, on what basis, its review status, and which
// earlier assertion it supersedes. What was asserted never changes; only the
// review of a proposed assertion is written later.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const VOCAB = 'https://workspace.openric.org/vocab/';

    /** The tables a relation may connect; a new kind of entity adds its table here, in its own migration. */
    private const ENTITY_TABLES = "'record_resources', 'repositories'";

    public function up(): void
    {
        Schema::create('relations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('iri')->unique();
            $table->foreignUuid('relation_type_id')->constrained('concepts')->restrictOnDelete();
            $table->text('source_table');
            $table->uuid('source_id');
            $table->text('target_table');
            $table->uuid('target_id');
            $table->foreignUuid('role_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->text('context_table')->nullable();
            $table->uuid('context_id')->nullable();
            $table->foreignUuid('state_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('certainty_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->text('source_of_information')->nullable();
            $table->text('description')->nullable();
            // BCP 47: the language of the source of information and the description.
            $table->string('language', 35)->nullable();
            $table->timestampsTz();
            $table->index(['source_table', 'source_id']);
            $table->index(['target_table', 'target_id']);
        });
        DB::statement('ALTER TABLE relations ADD CONSTRAINT relations_not_reflexive CHECK (NOT (source_table = target_table AND source_id = target_id))');
        DB::statement('ALTER TABLE relations ADD CONSTRAINT relations_context_whole CHECK ((context_table IS NULL) = (context_id IS NULL))');
        DB::statement('ALTER TABLE relations ADD CONSTRAINT relations_text_language CHECK ((source_of_information IS NULL AND description IS NULL) OR language IS NOT NULL)');
        DB::statement('ALTER TABLE relations ADD CONSTRAINT relations_entity_tables CHECK (source_table IN ('.self::ENTITY_TABLES.') AND target_table IN ('.self::ENTITY_TABLES.') AND (context_table IS NULL OR context_table IN ('.self::ENTITY_TABLES.')))');

        // Source, target and context must exist, in the relation's institution.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION relation_entities_in_institution() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                side text;
                owner uuid;
            BEGIN
                FOREACH side IN ARRAY ARRAY['source', 'target', 'context'] LOOP
                    -- Only the entity tables (the trigger's arguments); relations_entity_tables refuses any other.
                    CONTINUE WHEN (to_jsonb(NEW) ->> (side || '_table') = ANY (TG_ARGV)) IS NOT TRUE;
                    EXECUTE format('SELECT institution_id FROM %I WHERE id = $1', to_jsonb(NEW) ->> (side || '_table'))
                        INTO owner USING (to_jsonb(NEW) ->> (side || '_id'))::uuid;
                    IF owner IS DISTINCT FROM NEW.institution_id THEN
                        RAISE EXCEPTION 'the % of a relation must be an entity of its institution', side USING ERRCODE = 'foreign_key_violation';
                    END IF;
                END LOOP;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER relations_entities BEFORE INSERT OR UPDATE ON relations FOR EACH ROW EXECUTE FUNCTION relation_entities_in_institution('.self::ENTITY_TABLES.')');
        DB::statement('CREATE TRIGGER relations_iri_immutable BEFORE UPDATE ON relations FOR EACH ROW EXECUTE FUNCTION iri_immutable()');
        foreach (['relation_type_id' => self::VOCAB.'relation-type/', 'role_id' => 'https://openric.org/vocab/role-type/',
            'state_id' => self::VOCAB.'relation-state/', 'certainty_id' => self::VOCAB.'relation-certainty/'] as $column => $vocabulary) {
            DB::statement("CREATE TRIGGER relations_{$column}_vocabulary BEFORE INSERT OR UPDATE ON relations FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '{$vocabulary}')");
        }

        Schema::create('relation_evidence', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('relation_id')->constrained('relations')->restrictOnDelete();
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['relation_id', 'record_resource_id']);
        });
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION evidence_in_institution() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF (SELECT institution_id FROM relations WHERE id = NEW.relation_id) IS DISTINCT FROM NEW.institution_id
                   OR (SELECT institution_id FROM record_resources WHERE id = NEW.record_resource_id) IS DISTINCT FROM NEW.institution_id THEN
                    RAISE EXCEPTION 'evidence must be a unit of description of the relation''s institution' USING ERRCODE = 'foreign_key_violation';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER relation_evidence_institution BEFORE INSERT OR UPDATE ON relation_evidence FOR EACH ROW EXECUTE FUNCTION evidence_in_institution()');

        Schema::create('relation_assertions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('iri')->unique();
            $table->foreignUuid('relation_id')->constrained('relations')->restrictOnDelete();
            $table->foreignUuid('origin_id')->constrained('concepts')->restrictOnDelete();
            // The account that asserted it; for an import or a model, its name and version.
            $table->foreignUuid('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('origin_label')->nullable();
            $table->text('basis')->nullable();
            $table->string('basis_language', 35)->nullable();
            $table->timestampTz('asserted_at')->useCurrent();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('reviewed_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->uuid('supersedes_id')->nullable();
            $table->timestampsTz();
            $table->index('relation_id');
        });
        // A self-reference needs the primary key in place first.
        Schema::table('relation_assertions', fn (Blueprint $table) => $table->foreign('supersedes_id')->references('id')->on('relation_assertions')->restrictOnDelete());
        DB::statement('ALTER TABLE relation_assertions ADD CONSTRAINT relation_assertions_asserter CHECK (user_id IS NOT NULL OR origin_label IS NOT NULL)');
        DB::statement('ALTER TABLE relation_assertions ADD CONSTRAINT relation_assertions_basis_language CHECK ((basis IS NULL) = (basis_language IS NULL))');
        DB::statement('ALTER TABLE relation_assertions ADD CONSTRAINT relation_assertions_review_whole CHECK ((reviewed_by_id IS NULL) = (reviewed_at IS NULL))');
        // An assertion is superseded at most once, and only by one about the same relation.
        DB::statement('CREATE UNIQUE INDEX relation_assertions_superseded_once ON relation_assertions (supersedes_id) WHERE supersedes_id IS NOT NULL');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION assertion_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.supersedes_id IS NOT NULL
                   AND (SELECT relation_id FROM relation_assertions WHERE id = NEW.supersedes_id) IS DISTINCT FROM NEW.relation_id THEN
                    RAISE EXCEPTION 'an assertion can only supersede one about the same relation' USING ERRCODE = 'check_violation';
                END IF;
                IF TG_OP = 'UPDATE' AND (NEW.relation_id, NEW.origin_id, NEW.user_id, NEW.origin_label, NEW.basis, NEW.basis_language, NEW.asserted_at, NEW.supersedes_id, NEW.iri, NEW.institution_id)
                   IS DISTINCT FROM (OLD.relation_id, OLD.origin_id, OLD.user_id, OLD.origin_label, OLD.basis, OLD.basis_language, OLD.asserted_at, OLD.supersedes_id, OLD.iri, OLD.institution_id) THEN
                    RAISE EXCEPTION 'what was asserted cannot change; add an assertion that supersedes it' USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER relation_assertions_guard BEFORE INSERT OR UPDATE ON relation_assertions FOR EACH ROW EXECUTE FUNCTION assertion_guard()');
        foreach (['origin_id' => 'assertion-origin/', 'status_id' => 'assertion-status/'] as $column => $vocabulary) {
            DB::statement("CREATE TRIGGER relation_assertions_{$column}_vocabulary BEFORE INSERT OR UPDATE ON relation_assertions FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB.$vocabulary."')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('relation_assertions');
        Schema::dropIfExists('relation_evidence');
        Schema::dropIfExists('relations');
        DB::statement('DROP FUNCTION IF EXISTS assertion_guard()');
        DB::statement('DROP FUNCTION IF EXISTS evidence_in_institution()');
        DB::statement('DROP FUNCTION IF EXISTS relation_entities_in_institution()');
    }
};
