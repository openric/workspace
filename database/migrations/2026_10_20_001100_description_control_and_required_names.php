<?php

// OpenRiC Workspace - description control on units of description, and a name the database keeps.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A unit of description gets the control fields AtoM keeps beside ISAD(G)
// 3.7: a description identifier, a status and a level of detail.
//
// Every unit, repository, place and agent that is not deleted keeps at least
// one authorised name (a unit's title). The services already refuse to remove
// the last one; the database now refuses it too, at commit, so a change that
// moves names within a transaction (a merge, a split) is checked once it is
// complete.
//
// Existing institutions' default roles are given the new publish permissions
// on repositories, vocabularies and places, and authority records.

use App\Access\RoleService;
use App\Models\Institution;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const VOCAB = 'https://workspace.openric.org/vocab/';

    /** The described entities that must keep an authorised name. */
    private const NAMED = ['record_resources', 'repositories', 'places', 'agents'];

    public function up(): void
    {
        Schema::table('record_resources', function (Blueprint $table) {
            $table->text('description_identifier')->nullable();
            $table->foreignUuid('description_status_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('description_detail_id')->nullable()->constrained('concepts')->restrictOnDelete();
        });
        foreach (['description_status_id' => 'description-status/', 'description_detail_id' => 'description-detail/'] as $column => $vocabulary) {
            DB::statement("CREATE TRIGGER record_resources_{$column}_vocabulary BEFORE INSERT OR UPDATE ON record_resources FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB.$vocabulary."')");
        }

        // Existing rows first: a constraint trigger checks only what changes after it exists.
        foreach (self::NAMED as $table) {
            $missing = DB::table($table)->whereNull('deleted_at')->whereNotExists(fn ($q) => $q->from('names')->whereColumn('names.subject_id', "$table.id")
                ->where('names.subject_table', $table)->whereIn('names.name_type_id', DB::table('concepts')->where('iri', self::VOCAB.'name-type/authorised')->select('id')))->count();
            if ($missing > 0) {
                throw new RuntimeException("$missing rows of $table have no authorised name; give them one before migrating");
            }
        }
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION keeps_authorised_name() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                tbl text;
                sid uuid;
                live boolean;
            BEGIN
                IF TG_TABLE_NAME = 'names' THEN
                    tbl := OLD.subject_table;
                    sid := OLD.subject_id;
                ELSE
                    tbl := TG_TABLE_NAME;
                    sid := NEW.id;
                END IF;
                IF NOT (tbl = ANY (TG_ARGV)) THEN
                    RETURN NULL;
                END IF;
                EXECUTE format('SELECT deleted_at IS NULL FROM %I WHERE id = $1', tbl) INTO live USING sid;
                IF live AND NOT EXISTS (
                    SELECT 1 FROM names n JOIN concepts c ON c.id = n.name_type_id
                    WHERE n.subject_table = tbl AND n.subject_id = sid AND c.iri = 'https://workspace.openric.org/vocab/name-type/authorised') THEN
                    RAISE EXCEPTION 'every % keeps an authorised name or title', tbl USING ERRCODE = 'check_violation';
                END IF;
                RETURN NULL;
            END $$
        SQL);
        $named = "'".implode("', '", self::NAMED)."'";
        DB::statement("CREATE CONSTRAINT TRIGGER names_keep_authorised AFTER UPDATE OR DELETE ON names DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION keeps_authorised_name($named)");
        foreach (self::NAMED as $table) {
            DB::statement("CREATE CONSTRAINT TRIGGER {$table}_has_authorised_name AFTER INSERT OR UPDATE ON {$table} DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION keeps_authorised_name($named)");
        }

        foreach (Institution::all() as $institution) {
            foreach (['repository', 'vocabulary', 'authority'] as $kind) {
                DB::transaction(fn () => app(RoleService::class)->grantKind($institution, $kind));
            }
        }
    }

    public function down(): void
    {
        foreach (self::NAMED as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_has_authorised_name ON {$table}");
        }
        DB::statement('DROP TRIGGER IF EXISTS names_keep_authorised ON names');
        DB::statement('DROP FUNCTION IF EXISTS keeps_authorised_name()');
        DB::table('permissions')->where('action_id', DB::table('concepts')->where('iri', self::VOCAB.'permission/publish')->value('id'))
            ->whereIn('kind_id', DB::table('concepts')->whereIn('iri', [self::VOCAB.'record-kind/repository', self::VOCAB.'record-kind/vocabulary', self::VOCAB.'record-kind/authority'])->select('id'))->delete();
        foreach (['description_status_id', 'description_detail_id'] as $column) {
            DB::statement("DROP TRIGGER IF EXISTS record_resources_{$column}_vocabulary ON record_resources");
        }
        Schema::table('record_resources', function (Blueprint $table) {
            $table->dropConstrainedForeignId('description_status_id');
            $table->dropConstrainedForeignId('description_detail_id');
            $table->dropColumn('description_identifier');
        });
    }
};
