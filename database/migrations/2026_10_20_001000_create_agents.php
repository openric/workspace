<?php

// OpenRiC Workspace - authority records: persons, families, corporate bodies, positions and mechanisms (ISAAR(CPF); ADR 0006).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// An agent is an entity of one agent type (agent-type vocabulary). Its names
// are rico:AgentName entities, its dates, identifiers, description values,
// contact points and relations are those of any entity. The row holds what
// ISAAR(CPF) keeps as description control: the description identifier
// (5.4.1), the repository that maintains it (AtoM's "maintained by"), its
// status (5.4.4) and level of detail (5.4.5); and an embargo date, before which
// only those who may update it see it. Like a repository, an agent is marked
// deleted, never removed; a duplicate merged into another names the agent kept.
//
// Existing institutions' default roles are given the authority permissions
// that config workspace.default_roles grants.

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

    private const ENTITY_TABLES_BEFORE = "'record_resources', 'repositories', 'places', 'concepts'";

    /** The tables a relation may connect; a new kind of entity adds its table here, in its own migration. */
    private const ENTITY_TABLES = "'record_resources', 'repositories', 'places', 'concepts', 'agents'";

    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('iri')->unique();
            $table->foreignUuid('agent_type_id')->constrained('concepts')->restrictOnDelete();
            $table->text('description_identifier')->nullable();
            $table->foreignUuid('maintainer_id')->nullable()->constrained('repositories')->restrictOnDelete();
            $table->foreignUuid('description_status_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('description_detail_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->date('embargo_until')->nullable();
            $table->uuid('merged_into_id')->nullable();
            $table->timestampsTz();
            $table->timestampTz('deleted_at')->nullable();
            $table->index(['institution_id', 'agent_type_id']);
        });
        Schema::table('agents', fn (Blueprint $table) => $table->foreign('merged_into_id')->references('id')->on('agents')->restrictOnDelete());
        DB::statement('ALTER TABLE agents ADD CONSTRAINT agents_merged_is_deleted CHECK (merged_into_id IS NULL OR (deleted_at IS NOT NULL AND merged_into_id <> id))');
        DB::statement('CREATE TRIGGER agents_iri_guard BEFORE UPDATE OR DELETE ON agents FOR EACH ROW EXECUTE FUNCTION persistent_iri_guard()');
        foreach (['agent_type_id' => 'agent-type/', 'description_status_id' => 'description-status/', 'description_detail_id' => 'description-detail/'] as $column => $vocabulary) {
            DB::statement("CREATE TRIGGER agents_{$column}_vocabulary BEFORE INSERT OR UPDATE ON agents FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB.$vocabulary."')");
        }
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION agent_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.maintainer_id IS NOT NULL AND (SELECT institution_id FROM repositories WHERE id = NEW.maintainer_id) IS DISTINCT FROM NEW.institution_id THEN
                    RAISE EXCEPTION 'an authority record is maintained by a repository of its institution' USING ERRCODE = 'foreign_key_violation';
                END IF;
                IF NEW.merged_into_id IS NOT NULL AND (SELECT institution_id FROM agents WHERE id = NEW.merged_into_id) IS DISTINCT FROM NEW.institution_id THEN
                    RAISE EXCEPTION 'an agent is merged into an agent of its institution' USING ERRCODE = 'foreign_key_violation';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER agents_guard BEFORE INSERT OR UPDATE ON agents FOR EACH ROW EXECUTE FUNCTION agent_guard()');

        // Pairs a person looked at and found not to be duplicates, kept once for each pair.
        Schema::create('agent_duplicate_dismissals', function (Blueprint $table) {
            $table->foreignUuid('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->foreignUuid('other_id')->constrained('agents')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->primary(['agent_id', 'other_id']);
        });
        DB::statement('ALTER TABLE agent_duplicate_dismissals ADD CONSTRAINT agent_duplicate_dismissals_once CHECK (agent_id < other_id)');

        $this->entityTables(self::ENTITY_TABLES);

        // The concepts the grants cite, then the grants for each institution.
        foreach (config('workspace.bundled_vocabularies') as $file) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$file")));
        }
        foreach (Institution::all() as $institution) {
            DB::transaction(fn () => app(RoleService::class)->grantKind($institution, 'authority'));
        }
    }

    public function down(): void
    {
        $this->entityTables(self::ENTITY_TABLES_BEFORE);
        DB::table('permissions')->where('kind_id', DB::table('concepts')->where('iri', self::VOCAB.'record-kind/authority')->value('id'))->delete();
        Schema::dropIfExists('agent_duplicate_dismissals');
        Schema::dropIfExists('agents');
        DB::statement('DROP FUNCTION IF EXISTS agent_guard()');
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
