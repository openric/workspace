<?php

// OpenRiC Workspace - display standards (governance/HERATIO-PARITY.md, "Archival description", 29 October 2026).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// AtoM's and Heratio's display standard (information_object.display_standard_id): the standard an archival
// description is shown and edited in, ISAD(G), DACS, RAD, Dublin Core or MODS (display-standard vocabulary); none
// shows it in ISAD(G). Each standard's elements are the display-element vocabulary, and the elements DACS, RAD and
// MODS have that ISAD(G) does not are description elements of their own (other-standards). record_resource_types
// holds the types those standards give a unit from their own lists, as Heratio's materialTypeIds, dcTypeIds and
// modsTypeIds: RAD's general material designation, the DCMI type and the MODS type of resource.

use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const VOCAB = 'https://workspace.openric.org/vocab/';

    public function up(): void
    {
        foreach (config('workspace.bundled_vocabularies') as $file) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$file")));
        }
        Schema::table('record_resources', fn (Blueprint $table) => $table->foreignUuid('display_standard_id')->nullable()->constrained('concepts')->restrictOnDelete());
        DB::statement("CREATE TRIGGER record_resources_display_standard_id_vocabulary BEFORE INSERT OR UPDATE ON record_resources FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('display_standard_id', '".self::VOCAB."display-standard/')");

        Schema::create('record_resource_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->cascadeOnDelete();
            $table->foreignUuid('type_id')->constrained('concepts')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['record_resource_id', 'type_id']);
        });
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION record_resource_type_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM concepts c JOIN vocabularies v ON v.id = c.vocabulary_id WHERE c.id = NEW.type_id AND v.iri IN (
                        'https://workspace.openric.org/vocab/rad-material-designation/', 'https://workspace.openric.org/vocab/dcmi-type/',
                        'https://workspace.openric.org/vocab/mods-resource-type/')) THEN
                    RAISE EXCEPTION 'a unit''s type is a RAD general material designation, a DCMI type or a MODS type of resource' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$
            SQL);
        DB::statement('CREATE TRIGGER record_resource_types_guard BEFORE INSERT OR UPDATE ON record_resource_types FOR EACH ROW EXECUTE FUNCTION record_resource_type_guard()');
    }

    public function down(): void
    {
        Schema::dropIfExists('record_resource_types');
        DB::statement('DROP FUNCTION IF EXISTS record_resource_type_guard()');
        DB::statement('DROP TRIGGER IF EXISTS record_resources_display_standard_id_vocabulary ON record_resources');
        Schema::table('record_resources', fn (Blueprint $table) => $table->dropConstrainedForeignId('display_standard_id'));
    }
};
