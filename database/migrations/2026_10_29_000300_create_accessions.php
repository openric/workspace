<?php

// OpenRiC Workspace - accessions, after Heratio's ahg-accession-manage and AtoM's accession, with the CAAIS 1.0
// profile Heratio adds (roadmap phase 9a; ADR 0010).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// An accession is an entity of its own kind with a persistent IRI, exported as a rico:Activity of the OpenRiC
// activity type transfer. Its accession number is its local identifier and its other identifiers are identifiers;
// its title and texts are description values (accession elements, numbered after CAAIS); its acquisition date and the
// dates of the material are dates. Its acquisition type, resource type, processing status and priority, and CAAIS 1.1
// the repository that accepts responsibility, are columns. The donor is an authority record taking part in it in the
// donor role, the material's creators are related to it, and the descriptions made from it are what it resulted in:
// relations. CAAIS's repeatable statements are rows: events (5.1, with AtoM's accession events), extents (3.2),
// languages (3.4), preservation requirements (4.3) and the confidentiality of each source (2.1.6). A deaccession
// (AtoM's deaccession) is a row of the accession. CAAIS 7.2's creation and revision dates come from the audit log.

use App\Access\RoleService;
use App\Models\Institution;
use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const VOCAB = 'https://workspace.openric.org/vocab/';

    private const ENTITY_TABLES_BEFORE = "'record_resources', 'repositories', 'places', 'concepts', 'agents', 'activities', 'instantiations', 'containers'";

    private const ENTITY_TABLES = "'record_resources', 'repositories', 'places', 'concepts', 'agents', 'activities', 'instantiations', 'containers', 'accessions'";

    public function up(): void
    {
        foreach (config('workspace.bundled_vocabularies') as $file) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$file")));
        }
        Schema::create('accessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('iri')->unique();
            // CAAIS 1.1: the repository that accepts legal responsibility for the material.
            $table->foreignUuid('repository_id')->nullable()->constrained('repositories')->restrictOnDelete();
            $table->foreignUuid('acquisition_type_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('resource_type_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('processing_status_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('processing_priority_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->timestampsTz();
            $table->timestampTz('deleted_at')->nullable();
        });
        DB::statement('CREATE TRIGGER accessions_iri_guard BEFORE UPDATE OR DELETE ON accessions FOR EACH ROW EXECUTE FUNCTION persistent_iri_guard()');

        $rows = function (string $name, callable $columns) {
            Schema::create($name, function (Blueprint $table) use ($columns) {
                $table->uuid('id')->primary();
                $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
                $table->foreignUuid('accession_id')->constrained('accessions')->cascadeOnDelete();
                $columns($table);
                $table->integer('position')->default(0);
                $table->timestampsTz();
            });
        };
        // CAAIS 5.1 and AtoM's accession events: type, date, agent, note.
        $rows('accession_events', function (Blueprint $table) {
            $table->foreignUuid('event_type_id')->constrained('concepts')->restrictOnDelete();
            $table->date('date')->nullable();
            $table->text('agent')->nullable();
            $table->text('note')->nullable();
        });
        // CAAIS 3.2: extent type, quantity and unit (with "ca."), content type, carrier type, file formats, note.
        $rows('accession_extents', function (Blueprint $table) {
            $table->foreignUuid('extent_type_id')->constrained('concepts')->restrictOnDelete();
            $table->decimal('quantity', 14, 3)->nullable();
            $table->boolean('is_estimate')->default(false);
            $table->foreignUuid('unit_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('content_type_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('carrier_type_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->text('digital_file_formats')->nullable();
            $table->text('note')->nullable();
        });
        // CAAIS 3.4: an ISO 639 language, with a statement for what a code cannot say.
        $rows('accession_languages', function (Blueprint $table) {
            $table->string('language', 8)->nullable();
            $table->text('note')->nullable();
        });
        // CAAIS 4.3: requirement type, requirement, note.
        $rows('accession_preservation_requirements', function (Blueprint $table) {
            $table->foreignUuid('requirement_type_id')->constrained('concepts')->restrictOnDelete();
            $table->text('requirement');
            $table->text('note')->nullable();
        });
        // CAAIS 2.1.6: how a source (a donor taking part in the accession) is to be handled.
        $rows('accession_source_confidentiality', function (Blueprint $table) {
            $table->foreignUuid('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->foreignUuid('confidentiality_id')->constrained('concepts')->restrictOnDelete();
            $table->unique(['accession_id', 'agent_id']);
        });
        // AtoM's deaccession: identifier, scope, date, and description, extent and reason in one language.
        $rows('deaccessions', function (Blueprint $table) {
            $table->string('identifier', 255);
            $table->foreignUuid('scope_id')->constrained('concepts')->restrictOnDelete();
            $table->date('date')->nullable();
            $table->text('description')->nullable();
            $table->text('extent')->nullable();
            $table->text('reason')->nullable();
            $table->string('language', 35);
        });
        DB::statement("ALTER TABLE accession_preservation_requirements ADD CONSTRAINT accession_preservation_requirement CHECK (btrim(requirement) <> '')");
        DB::statement("ALTER TABLE deaccessions ADD CONSTRAINT deaccessions_identifier CHECK (btrim(identifier) <> '')");

        foreach ([['accessions', 'acquisition_type_id', 'acquisition-type'], ['accessions', 'resource_type_id', 'accession-resource-type'],
            ['accessions', 'processing_status_id', 'processing-status'], ['accessions', 'processing_priority_id', 'processing-priority'],
            ['accession_events', 'event_type_id', 'accession-event-type'], ['accession_extents', 'extent_type_id', 'caais-extent-type'],
            ['accession_extents', 'unit_id', 'caais-extent-unit'], ['accession_extents', 'content_type_id', 'caais-content-type'],
            ['accession_extents', 'carrier_type_id', 'caais-carrier-type'], ['accession_preservation_requirements', 'requirement_type_id', 'caais-preservation-type'],
            ['accession_source_confidentiality', 'confidentiality_id', 'caais-source-confidentiality'], ['deaccessions', 'scope_id', 'deaccession-scope']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }

        // An accession takes part in relations: its donors (participation, in the donor role), the creators of its
        // material, and the descriptions it resulted in.
        $this->entityTables(self::ENTITY_TABLES);
        $type = fn (string $notation) => DB::table('concepts')->where('iri', self::VOCAB."relation-type/$notation")->value('id');
        DB::table('relation_type_pairs')->insert([
            ['relation_type_id' => $type('participation'), 'source_kind' => 'accession', 'target_kind' => 'agent'],
            ['relation_type_id' => $type('result'), 'source_kind' => 'accession', 'target_kind' => 'record'],
        ]);
        // It is kept in containers, as AtoM's "Link physical storage".
        DB::table('relation_type_pairs')->insert(['relation_type_id' => $type('storage'), 'source_kind' => 'accession', 'target_kind' => 'container']);
        // Two new bundled types: the creators of its material, and AtoM's accruals.
        $af = json_decode(file_get_contents(lang_path('af.json')), true);
        foreach (['material-creator' => ['agent', 'includes material created by', 'created material in'], 'accrual' => ['accession', 'is an accrual to', 'has the accrual']] as $notation => [$target, $forward, $inverse]) {
            $id = $type($notation);
            DB::table('relation_types')->insert(['concept_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('relation_type_pairs')->insert(['relation_type_id' => $id, 'source_kind' => 'accession', 'target_kind' => $target]);
            foreach (['en' => [$forward, $inverse], 'af' => [$af[$forward], $af[$inverse]]] as $language => [$from, $to]) {
                DB::table('relation_type_phrases')->insert(['id' => (string) Str::uuid7(), 'relation_type_id' => $id, 'language' => $language,
                    'forward' => $from, 'inverse' => $to, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        foreach (Institution::all() as $institution) {
            DB::transaction(fn () => app(RoleService::class)->grantKind($institution, 'accession'));
        }
    }

    public function down(): void
    {
        DB::table('relation_types')->whereIn('concept_id', DB::table('concepts')->whereIn('iri', [self::VOCAB.'relation-type/material-creator', self::VOCAB.'relation-type/accrual'])->select('id'))->delete();
        DB::table('relation_type_pairs')->where('source_kind', 'accession')->delete();
        $this->entityTables(self::ENTITY_TABLES_BEFORE);
        foreach (['deaccessions', 'accession_source_confidentiality', 'accession_preservation_requirements', 'accession_languages', 'accession_extents', 'accession_events', 'accessions'] as $table) {
            Schema::dropIfExists($table);
        }
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
