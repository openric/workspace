<?php

// OpenRiC Workspace - names and identifiers as entities (rico:Name, rico:Identifier; ADR 0006).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A name belongs to the entity it names (subject_table, subject_id), with a
// name type from the name-type vocabulary, its language, and the same draft
// and published states as description values; the dates it was used are
// rico:Date entities of the name (date properties used-from and used-to). An
// identifier has an identifier type (rico:hasIdentifierType). An entity has
// at most one authorised name in each language and one local identifier, and
// a repository's identifiers are unique in its institution.
//
// The titles, authorised and other names of v0.0.5 to v0.0.10, kept as
// description values, and the identifier columns of repositories and units
// of description are converted; then the columns go.

use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const VOCAB = 'https://workspace.openric.org/vocab/';

    /** Description elements that held names in v0.0.5 to v0.0.10 => name type. */
    private const NAME_ELEMENTS = ['title' => 'authorised', 'authorised-name' => 'authorised', 'other-name' => 'other'];

    public function up(): void
    {
        Schema::create('names', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('iri')->unique();
            $table->text('subject_table');
            $table->uuid('subject_id');
            $table->foreignUuid('name_type_id')->constrained('concepts')->restrictOnDelete();
            $table->text('value');
            // BCP 47 (RFC 5646).
            $table->string('language', 35);
            $table->boolean('is_original')->default(true);
            $table->foreignUuid('state_id')->constrained('concepts')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['subject_table', 'subject_id', 'name_type_id', 'language', 'value']);
            $table->index(['institution_id', 'value']);
        });
        Schema::create('identifiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('iri')->unique();
            $table->text('subject_table');
            $table->uuid('subject_id');
            $table->foreignUuid('identifier_type_id')->constrained('concepts')->restrictOnDelete();
            $table->string('value', 255);
            $table->timestampsTz();
            $table->unique(['subject_table', 'subject_id', 'identifier_type_id', 'value']);
            $table->index(['institution_id', 'value']);
        });
        DB::statement("ALTER TABLE names ADD CONSTRAINT names_value_not_blank CHECK (btrim(value) <> '')");
        DB::statement("ALTER TABLE identifiers ADD CONSTRAINT identifiers_value_not_blank CHECK (btrim(value) <> '')");
        DB::statement("CREATE UNIQUE INDEX identifiers_repository_unique ON identifiers (institution_id, identifier_type_id, value) WHERE subject_table = 'repositories'");

        // At most one row of the given concept for each subject (and, with a third
        // argument, for each value of that column). The advisory lock makes two
        // concurrent writers for one subject take turns, so the check cannot race.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION one_per_subject() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                type_id uuid := (to_jsonb(NEW) ->> TG_ARGV[0])::uuid;
                per text := CASE WHEN TG_NARGS > 2 THEN to_jsonb(NEW) ->> TG_ARGV[2] END;
                clash boolean;
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM concepts WHERE id = type_id AND iri = TG_ARGV[1]) THEN
                    RETURN NEW;
                END IF;
                PERFORM pg_advisory_xact_lock(hashtext(TG_TABLE_NAME || NEW.subject_id::text));
                EXECUTE format('SELECT EXISTS (SELECT 1 FROM %I WHERE subject_table = $1 AND subject_id = $2 AND %I = $3 AND id <> $4 %s)',
                        TG_TABLE_NAME, TG_ARGV[0], CASE WHEN TG_NARGS > 2 THEN format('AND %I = $5', TG_ARGV[2]) ELSE '' END)
                    INTO clash USING NEW.subject_table, NEW.subject_id, type_id, NEW.id, per;
                IF clash THEN
                    RAISE EXCEPTION 'only one % of type % for each subject%', TG_TABLE_NAME, TG_ARGV[1],
                        CASE WHEN TG_NARGS > 2 THEN ' and ' || TG_ARGV[2] ELSE '' END USING ERRCODE = 'unique_violation';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        foreach (['names', 'identifiers'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_iri_immutable BEFORE UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION iri_immutable()");
        }
        DB::statement("CREATE TRIGGER names_type_vocabulary BEFORE INSERT OR UPDATE ON names FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('name_type_id', '".self::VOCAB."name-type/')");
        DB::statement("CREATE TRIGGER names_state_vocabulary BEFORE INSERT OR UPDATE ON names FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('state_id', '".self::VOCAB."language-value-state/')");
        DB::statement("CREATE TRIGGER names_one_authorised BEFORE INSERT OR UPDATE ON names FOR EACH ROW EXECUTE FUNCTION one_per_subject('name_type_id', '".self::VOCAB."name-type/authorised', 'language')");
        DB::statement("CREATE TRIGGER identifiers_type_vocabulary BEFORE INSERT OR UPDATE ON identifiers FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('identifier_type_id', '".self::VOCAB."identifier-type/')");
        DB::statement("CREATE TRIGGER identifiers_one_local BEFORE INSERT OR UPDATE ON identifiers FOR EACH ROW EXECUTE FUNCTION one_per_subject('identifier_type_id', '".self::VOCAB."identifier-type/local')");

        $this->convert();
        Schema::table('repositories', fn (Blueprint $table) => $table->dropColumn('identifier'));
        Schema::table('record_resources', fn (Blueprint $table) => $table->dropColumn('identifier'));
    }

    public function down(): void
    {
        throw new RuntimeException('Names and identifiers as entities cannot be undone by migration; restore the dump deploy.sh took before this release.');
    }

    private function convert(): void
    {
        $values = DB::table('description_values as v')->join('concepts as e', 'e.id', '=', 'v.element_id')->join('institutions as i', 'i.id', '=', 'v.institution_id')
            ->whereIn('e.iri', array_map(fn ($n) => self::VOCAB.'description-element/'.$n, array_keys(self::NAME_ELEMENTS)))
            ->orderBy('v.created_at')->get(['v.*', 'e.notation as element', 'i.base_iri']);
        $identified = fn (string $table) => DB::table("$table as s")->join('institutions as i', 'i.id', '=', 's.institution_id')
            ->whereNotNull('s.identifier')->where('s.identifier', '<>', '')->get(['s.id', 's.institution_id', 's.identifier', 'i.base_iri']);
        $identifiers = ['repositories' => $identified('repositories'), 'record_resources' => $identified('record_resources')];
        if ($values->isEmpty() && $identifiers['repositories']->isEmpty() && $identifiers['record_resources']->isEmpty()) {
            return;
        }
        foreach (['name-type.ttl', 'identifier-type.ttl'] as $file) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$file")));
        }
        $concept = fn (string $iri) => DB::table('concepts')->where('iri', $iri)->value('id');

        $seen = [];
        foreach ($values as $row) {
            $type = self::NAME_ELEMENTS[$row->element];
            // An authorised name is one line; an other-name value may hold several, one per line.
            $lines = $type === 'authorised' ? [trim($row->value)] : array_filter(array_map('trim', preg_split('/\R/', $row->value)));
            foreach ($lines as $line) {
                $key = "$row->subject_table|$row->subject_id|$type|$row->language|$line";
                if ($line === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $id = (string) Str::uuid7();
                DB::table('names')->insert([
                    'id' => $id, 'institution_id' => $row->institution_id, 'iri' => $row->base_iri.'/name/'.$id,
                    'subject_table' => $row->subject_table, 'subject_id' => $row->subject_id,
                    'name_type_id' => $concept(self::VOCAB.'name-type/'.$type), 'value' => $line, 'language' => $row->language,
                    'is_original' => $row->is_original, 'state_id' => $row->state_id,
                    'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
                ]);
            }
        }
        DB::table('description_values')->whereIn('id', $values->pluck('id'))->delete();

        $local = $concept(self::VOCAB.'identifier-type/local');
        foreach ($identifiers as $table => $rows) {
            foreach ($rows as $row) {
                $id = (string) Str::uuid7();
                DB::table('identifiers')->insert([
                    'id' => $id, 'institution_id' => $row->institution_id, 'iri' => $row->base_iri.'/identifier/'.$id,
                    'subject_table' => $table, 'subject_id' => $row->id, 'identifier_type_id' => $local, 'value' => trim($row->identifier),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }
};
