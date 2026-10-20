<?php

// OpenRiC Workspace - activities as RiC entities (rico:Activity; ADR 0006, WS-003).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// An activity, such as the creation of a series of minute books or a
// marriage, is an entity with its own persistent IRI, names, values, dates,
// identifiers and relations: who performed it, who took part and in what
// role, what it resulted in, what documents it, where it took place, and the
// activities it is part of. RiC-O 1.1 has one class, rico:Activity; its kind
// is an activity type (rico:hasActivityType), a concept of the OpenRiC
// activity-type vocabulary or of one of the institution's own activity-type
// vocabularies (mapping section 6.5). An activity is marked deleted, never
// removed, and keeps an authorised name like every described entity.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ENTITY_TABLES_BEFORE = "'record_resources', 'repositories', 'places', 'concepts', 'agents'";

    private const ENTITY_TABLES = "'record_resources', 'repositories', 'places', 'concepts', 'agents', 'activities'";

    private const NAMED_BEFORE = "'record_resources', 'repositories', 'places', 'agents'";

    private const NAMED = "'record_resources', 'repositories', 'places', 'agents', 'activities'";

    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('iri')->unique();
            $table->foreignUuid('activity_type_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->timestampsTz();
            $table->timestampTz('deleted_at')->nullable();
            $table->index(['institution_id', 'activity_type_id']);
        });
        DB::statement('CREATE TRIGGER activities_iri_guard BEFORE UPDATE OR DELETE ON activities FOR EACH ROW EXECUTE FUNCTION persistent_iri_guard()');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION activity_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.activity_type_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM concepts c JOIN vocabularies v ON v.id = c.vocabulary_id LEFT JOIN concepts k ON k.id = v.kind_id
                    WHERE c.id = NEW.activity_type_id
                      AND (v.iri = 'https://openric.org/vocab/activity-type/'
                           OR (c.institution_id = NEW.institution_id AND k.iri = 'https://workspace.openric.org/vocab/concept-scheme-kind/activity-type'))) THEN
                    RAISE EXCEPTION 'an activity type is a concept of the OpenRiC activity-type vocabulary or of one of the institution''s activity-type vocabularies'
                        USING ERRCODE = 'foreign_key_violation';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER activities_guard BEFORE INSERT OR UPDATE ON activities FOR EACH ROW EXECUTE FUNCTION activity_guard()');
        DB::statement('CREATE CONSTRAINT TRIGGER activities_has_authorised_name AFTER INSERT OR UPDATE ON activities DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION keeps_authorised_name('.self::NAMED.')');
        $this->named(self::NAMED);
        $this->entityTables(self::ENTITY_TABLES);
    }

    public function down(): void
    {
        $this->entityTables(self::ENTITY_TABLES_BEFORE);
        $this->named(self::NAMED_BEFORE);
        DB::statement('DROP TRIGGER IF EXISTS activities_has_authorised_name ON activities');
        Schema::dropIfExists('activities');
        DB::statement('DROP FUNCTION IF EXISTS activity_guard()');
    }

    /** The tables whose rows must keep an authorised name, for the trigger on names. */
    private function named(string $tables): void
    {
        DB::statement('DROP TRIGGER IF EXISTS names_keep_authorised ON names');
        DB::statement("CREATE CONSTRAINT TRIGGER names_keep_authorised AFTER UPDATE OR DELETE ON names DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION keeps_authorised_name($tables)");
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
