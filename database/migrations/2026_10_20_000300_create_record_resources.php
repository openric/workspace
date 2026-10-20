<?php

// OpenRiC Workspace - record sets, records and record parts (ISAD(G); RiC-CM record resources).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The hierarchy is parent_id plus a position among siblings: a navigational
// view, not the whole model (SPECIFICATION.md). Moving a unit changes neither
// its id nor its IRI (WS-002). The database refuses a cycle, a parent in
// another institution, a level or date type from the wrong vocabulary.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const VOCAB = 'https://workspace.openric.org/vocab/';

    public function up(): void
    {
        Schema::create('record_resources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('iri')->unique();
            // ISAD(G) 3.1.1 Reference code, as the institution writes it.
            $table->string('identifier', 255)->nullable();
            $table->foreignUuid('level_id')->constrained('concepts')->restrictOnDelete();
            // The holder (rico:hasOrHadHolder): the repository that keeps the unit.
            $table->foreignUuid('repository_id')->constrained('repositories')->restrictOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->integer('position')->default(0);
            $table->timestampsTz();
            $table->timestampTz('deleted_at')->nullable();
            $table->index(['parent_id', 'position']);
            $table->index(['institution_id', 'repository_id']);
        });
        Schema::table('record_resources', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('record_resources')->restrictOnDelete();
        });
        DB::statement('CREATE TRIGGER record_resources_iri_guard BEFORE UPDATE OR DELETE ON record_resources FOR EACH ROW EXECUTE FUNCTION persistent_iri_guard()');
        DB::statement("CREATE TRIGGER record_resources_level_vocabulary BEFORE INSERT OR UPDATE ON record_resources FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('level_id', '".self::VOCAB."level-of-description/')");

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION record_resources_hierarchy() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.parent_id IS NULL THEN
                    RETURN NEW;
                END IF;
                IF NOT EXISTS (SELECT 1 FROM record_resources p WHERE p.id = NEW.parent_id AND p.institution_id = NEW.institution_id) THEN
                    RAISE EXCEPTION 'the parent of % must belong to the same institution', NEW.iri USING ERRCODE = 'check_violation';
                END IF;
                IF EXISTS (
                    WITH RECURSIVE ancestors(id) AS (
                        SELECT NEW.parent_id
                        UNION
                        SELECT r.parent_id FROM record_resources r JOIN ancestors a ON r.id = a.id WHERE r.parent_id IS NOT NULL
                    )
                    SELECT 1 FROM ancestors WHERE id = NEW.id
                ) THEN
                    RAISE EXCEPTION 'moving % there would make it its own ancestor', NEW.iri USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER record_resources_hierarchy BEFORE INSERT OR UPDATE OF parent_id ON record_resources FOR EACH ROW EXECUTE FUNCTION record_resources_hierarchy()');

        // ISAD(G) 3.1.3 as structured dates: a type from the OpenRiC date-type vocabulary and
        // ISO 8601 endpoints (rico:beginningDate, rico:endDate); the written form is a language value.
        Schema::create('record_dates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->restrictOnDelete();
            $table->foreignUuid('date_type_id')->constrained('concepts')->restrictOnDelete();
            $table->date('begin_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE record_dates ADD CONSTRAINT record_dates_order CHECK (begin_date IS NULL OR end_date IS NULL OR begin_date <= end_date)');
        DB::statement('ALTER TABLE record_dates ADD CONSTRAINT record_dates_any CHECK (begin_date IS NOT NULL OR end_date IS NOT NULL)');
        DB::statement("CREATE TRIGGER record_dates_type_vocabulary BEFORE INSERT OR UPDATE ON record_dates FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('date_type_id', 'https://openric.org/vocab/date-type/')");
    }

    public function down(): void
    {
        Schema::dropIfExists('record_dates');
        Schema::dropIfExists('record_resources');
        DB::statement('DROP FUNCTION IF EXISTS record_resources_hierarchy()');
    }
};
