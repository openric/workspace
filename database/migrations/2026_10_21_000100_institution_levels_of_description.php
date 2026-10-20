<?php

// OpenRiC Workspace - an institution's own levels of description.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A unit's level was a concept of the bundled level-of-description
// vocabulary only. As AtoM lets an institution add to its levels of
// description, the level may now also be a concept of one of the
// institution's own vocabularies of kind level-of-description (the same
// in_kind() test containers use for their types).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP TRIGGER record_resources_level_vocabulary ON record_resources');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION record_level_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT in_kind(NEW.level_id, 'https://workspace.openric.org/vocab/level-of-description/', 'https://workspace.openric.org/vocab/concept-scheme-kind/level-of-description', NEW.institution_id) THEN
                    RAISE EXCEPTION 'a level of description is a concept of the level-of-description vocabulary or of one of the institution''s level-of-description vocabularies' USING ERRCODE = 'foreign_key_violation';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER record_resources_level_vocabulary BEFORE INSERT OR UPDATE ON record_resources FOR EACH ROW EXECUTE FUNCTION record_level_guard()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER record_resources_level_vocabulary ON record_resources');
        DB::statement('DROP FUNCTION IF EXISTS record_level_guard()');
        DB::statement("CREATE TRIGGER record_resources_level_vocabulary BEFORE INSERT OR UPDATE ON record_resources FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('level_id', 'https://workspace.openric.org/vocab/level-of-description/')");
    }
};
