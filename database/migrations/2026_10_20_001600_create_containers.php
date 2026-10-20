<?php

// OpenRiC Workspace - physical storage: containers and storage places (openricx:Container, extension ontology 1.6.0).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A container, such as a box, a folder or a map drawer, is an entity with its
// own persistent IRI, names, values, identifiers (a barcode among them) and
// relations: what it holds (openricx:isOrWasStoredIn, from a unit of
// description, an instantiation or a smaller container) and where it is kept
// (rico:hasOrHadLocation, to a storage place). Both are dated relations, so the
// dates of a container's locations are its movements, and the audit log keeps
// every change. A storage place is a rico:Place whose type comes from the
// bundled storage-place-type vocabulary or one of the institution's own;
// RiC-O 1.1 has no container class, so the extension ontology supplies one.
// The facts Heratio keeps for collection management (dimensions, capacity,
// environment, status, security level) are columns, their kinds vocabulary
// rows. A container is marked deleted, never removed.

use App\Access\RoleService;
use App\Models\Institution;
use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const VOCAB = 'https://workspace.openric.org/vocab/';

    private const ENTITY_TABLES_BEFORE = "'record_resources', 'repositories', 'places', 'concepts', 'agents', 'activities', 'instantiations'";

    private const ENTITY_TABLES = "'record_resources', 'repositories', 'places', 'concepts', 'agents', 'activities', 'instantiations', 'containers'";

    private const NAMED_BEFORE = "'record_resources', 'repositories', 'places', 'agents', 'activities', 'instantiations'";

    private const NAMED = "'record_resources', 'repositories', 'places', 'agents', 'activities', 'instantiations', 'containers'";

    public function up(): void
    {
        // The vocabularies the checks below name, and the record kind the grants cite.
        foreach (config('workspace.bundled_vocabularies') as $file) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$file")));
        }

        Schema::create('containers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('iri')->unique();
            $table->foreignUuid('container_type_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('status_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('security_level_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->decimal('width_cm', 8, 1)->nullable();
            $table->decimal('height_cm', 8, 1)->nullable();
            $table->decimal('depth_cm', 8, 1)->nullable();
            $table->decimal('capacity', 10, 2)->nullable();
            $table->decimal('capacity_used', 10, 2)->nullable();
            $table->foreignUuid('capacity_unit_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->boolean('climate_controlled')->nullable();
            $table->decimal('temperature_min', 5, 1)->nullable();
            $table->decimal('temperature_max', 5, 1)->nullable();
            $table->decimal('humidity_min', 5, 1)->nullable();
            $table->decimal('humidity_max', 5, 1)->nullable();
            $table->timestampsTz();
            $table->timestampTz('deleted_at')->nullable();
            $table->index(['institution_id', 'container_type_id']);
        });
        DB::statement('ALTER TABLE containers ADD CONSTRAINT containers_sizes CHECK ((width_cm IS NULL OR width_cm > 0) AND (height_cm IS NULL OR height_cm > 0) AND (depth_cm IS NULL OR depth_cm > 0)
            AND (capacity IS NULL OR capacity >= 0) AND (capacity_used IS NULL OR capacity_used >= 0) AND ((capacity IS NULL AND capacity_used IS NULL) OR capacity_unit_id IS NOT NULL)
            AND (temperature_min IS NULL OR temperature_max IS NULL OR temperature_min <= temperature_max)
            AND (humidity_min IS NULL OR (humidity_min >= 0 AND humidity_min <= 100)) AND (humidity_max IS NULL OR (humidity_max >= 0 AND humidity_max <= 100))
            AND (humidity_min IS NULL OR humidity_max IS NULL OR humidity_min <= humidity_max))');
        DB::statement('CREATE TRIGGER containers_iri_guard BEFORE UPDATE OR DELETE ON containers FOR EACH ROW EXECUTE FUNCTION persistent_iri_guard()');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION in_kind(concept uuid, bundled text, kind text, institution uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
                SELECT concept IS NULL OR EXISTS (
                    SELECT 1 FROM concepts c JOIN vocabularies v ON v.id = c.vocabulary_id LEFT JOIN concepts k ON k.id = v.kind_id
                    WHERE c.id = concept AND (v.iri = bundled OR (kind IS NOT NULL AND c.institution_id = institution AND k.iri = kind)))
            $$
        SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION container_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT in_kind(NEW.container_type_id, 'https://workspace.openric.org/vocab/container-type/', 'https://workspace.openric.org/vocab/concept-scheme-kind/container-type', NEW.institution_id) THEN
                    RAISE EXCEPTION 'a container type is a concept of the container-type vocabulary or of one of the institution''s container-type vocabularies' USING ERRCODE = 'foreign_key_violation';
                END IF;
                IF NOT in_kind(NEW.status_id, 'https://workspace.openric.org/vocab/container-status/', NULL, NEW.institution_id)
                   OR NOT in_kind(NEW.security_level_id, 'https://workspace.openric.org/vocab/storage-security-level/', NULL, NEW.institution_id)
                   OR NOT in_kind(NEW.capacity_unit_id, 'https://workspace.openric.org/vocab/capacity-unit/', NULL, NEW.institution_id) THEN
                    RAISE EXCEPTION 'a container''s status, security level and capacity unit come from their vocabularies' USING ERRCODE = 'foreign_key_violation';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER containers_guard BEFORE INSERT OR UPDATE ON containers FOR EACH ROW EXECUTE FUNCTION container_guard()');
        DB::statement('CREATE CONSTRAINT TRIGGER containers_has_authorised_name AFTER INSERT OR UPDATE ON containers DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION keeps_authorised_name('.self::NAMED.')');
        $this->named(self::NAMED);
        $this->entityTables(self::ENTITY_TABLES);
        $this->placeGuard(true);

        foreach (Institution::all() as $institution) {
            DB::transaction(fn () => app(RoleService::class)->grantKind($institution, 'storage'));
        }
    }

    public function down(): void
    {
        $this->placeGuard(false);
        $this->entityTables(self::ENTITY_TABLES_BEFORE);
        $this->named(self::NAMED_BEFORE);
        DB::table('permissions')->where('kind_id', DB::table('concepts')->where('iri', self::VOCAB.'record-kind/storage')->value('id'))->delete();
        DB::statement('DROP TRIGGER IF EXISTS containers_has_authorised_name ON containers');
        Schema::dropIfExists('containers');
        DB::statement('DROP FUNCTION IF EXISTS container_guard()');
        DB::statement('DROP FUNCTION IF EXISTS in_kind(uuid, text, text, uuid)');
    }

    /**
     * A place's type: a concept of one of the institution's place-type vocabularies, and now also of the
     * bundled storage-place-type vocabulary or one of the institution's storage-place-type vocabularies.
     */
    private function placeGuard(bool $storage): void
    {
        $definition = DB::selectOne("SELECT pg_get_functiondef('place_guard()'::regprocedure) AS def")->def;
        $before = "AND k.iri = 'https://workspace.openric.org/vocab/concept-scheme-kind/place-type') THEN";
        $after = "AND k.iri IN ('https://workspace.openric.org/vocab/concept-scheme-kind/place-type', 'https://workspace.openric.org/vocab/concept-scheme-kind/storage-place-type'))\n"
            ."                   AND NOT EXISTS (SELECT 1 FROM concepts c JOIN vocabularies v ON v.id = c.vocabulary_id WHERE c.id = NEW.place_type_id AND v.iri = 'https://workspace.openric.org/vocab/storage-place-type/') THEN";
        $plain = "one of the institution''s place-type vocabularies'";
        $wider = "one of the institution''s place-type or storage-place-type vocabularies, or of the storage-place-type vocabulary'";
        [$from, $to] = $storage ? [[$before, $plain], [$after, $wider]] : [[$after, $wider], [$before, $plain]];
        $changed = str_replace($from, $to, $definition);
        if ($changed === $definition) {
            throw new RuntimeException('place_guard() is not as this migration expects');
        }
        DB::unprepared($changed);
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
