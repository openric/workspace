<?php

// OpenRiC Workspace - rules and mandates as RiC entities (rico:Rule, rico:Mandate; roadmap phase 23b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A rule, such as an act, a regulation, a policy or a mandate, is an entity with its own persistent IRI, names,
// values, dates, identifiers and relations, as an activity is (2026_10_20_001300). Until now the Workspace kept a
// mandate only as text on an agent (ISAAR(CPF) 5.2.6) and an activity's legislation as text (ISDF 5.2.4), so the
// changing authority of a creator could not be exported; the RiC standards gap assessment of 1 November 2026 asked
// for it (scenario S1). Heratio keeps rules as entities (ahg-ric ric_rule). Its kind is a rule type of the OpenRiC
// rule-type vocabulary or of one of the institution's own rule-type vocabularies; a rule of type mandate is a
// rico:Mandate. Two relation types come with it: a mandate gives an agent its authority (rico:MandateRelation), and
// a rule regulates a unit of description, an activity, an agent or an instantiation (rico:regulatesOrRegulated). A
// place is associated with a rule as with an activity, such as its jurisdiction.

use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const VOCAB = 'https://workspace.openric.org/vocab/';

    private const ENTITY_TABLES_BEFORE = "'record_resources', 'repositories', 'places', 'concepts', 'agents', 'activities', 'instantiations', 'containers', 'accessions', 'agreements'";

    private const ENTITY_TABLES = "'record_resources', 'repositories', 'places', 'concepts', 'agents', 'activities', 'instantiations', 'containers', 'accessions', 'agreements', 'rules'";

    private const NAMED_BEFORE = "'record_resources', 'repositories', 'places', 'agents', 'activities', 'instantiations', 'containers'";

    private const NAMED = "'record_resources', 'repositories', 'places', 'agents', 'activities', 'instantiations', 'containers', 'rules'";

    public function up(): void
    {
        foreach (['description-element.ttl', 'relation-type.ttl', 'concept-scheme-kind.ttl'] as $file) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$file")));
        }
        Schema::create('rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('iri')->unique();
            $table->foreignUuid('rule_type_id')->nullable()->constrained('concepts')->restrictOnDelete();
            // The rule as an authority elsewhere publishes it (owl:sameAs), such as a law's entry in a legal database; Heratio's authority_uri.
            $table->text('authority_iri')->nullable();
            $table->text('description_identifier')->nullable();
            $table->foreignUuid('description_status_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('description_detail_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->timestampsTz();
            $table->timestampTz('deleted_at')->nullable();
            $table->index(['institution_id', 'rule_type_id']);
        });
        DB::statement("ALTER TABLE rules ADD CONSTRAINT rules_authority_iri CHECK (authority_iri IS NULL OR authority_iri ~ '^https?://')");
        DB::statement('CREATE TRIGGER rules_iri_guard BEFORE UPDATE OR DELETE ON rules FOR EACH ROW EXECUTE FUNCTION persistent_iri_guard()');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION rule_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.rule_type_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM concepts c JOIN vocabularies v ON v.id = c.vocabulary_id LEFT JOIN concepts k ON k.id = v.kind_id
                    WHERE c.id = NEW.rule_type_id
                      AND (v.iri = 'https://openric.org/vocab/rule-type/'
                           OR (c.institution_id = NEW.institution_id AND k.iri = 'https://workspace.openric.org/vocab/concept-scheme-kind/rule-type'))) THEN
                    RAISE EXCEPTION 'a rule type is a concept of the OpenRiC rule-type vocabulary or of one of the institution''s rule-type vocabularies'
                        USING ERRCODE = 'foreign_key_violation';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER rules_guard BEFORE INSERT OR UPDATE ON rules FOR EACH ROW EXECUTE FUNCTION rule_guard()');
        foreach (['description_status_id' => 'description-status', 'description_detail_id' => 'description-detail'] as $column => $vocabulary) {
            DB::statement("CREATE TRIGGER rules_{$column}_vocabulary BEFORE INSERT OR UPDATE ON rules FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }
        DB::statement('CREATE CONSTRAINT TRIGGER rules_has_authorised_name AFTER INSERT OR UPDATE ON rules DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION keeps_authorised_name('.self::NAMED.')');
        $this->named(self::NAMED);
        $this->entityTables(self::ENTITY_TABLES);
        $this->listed('import_keys', 'import_keys_subject_table', "'record_resources', 'agents', 'repositories', 'places', 'concepts', 'activities', 'rules'");
        $this->listed('deprecations', 'deprecations_subject_table', "'record_resources', 'agents', 'repositories', 'activities', 'places', 'rules'");

        $type = fn (string $notation) => DB::table('concepts')->where('iri', self::VOCAB."relation-type/$notation")->value('id');
        $af = json_decode(file_get_contents(lang_path('af.json')), true);
        foreach (['mandate' => [[['rule', 'agent']], 'gives or gave authority to', 'has or had its authority from', ['rico:MandateRelation', 'rico:authorizes', 'rico:authorizedBy']],
            'regulation' => [[['rule', 'record'], ['rule', 'activity'], ['rule', 'agent'], ['rule', 'instantiation']], 'regulates or regulated', 'is or was regulated by',
                ['rico:Relation', 'rico:regulatesOrRegulated', 'rico:isOrWasRegulatedBy']]] as $notation => [$pairs, $forward, $inverse, [$class, $property, $back]]) {
            $id = $type($notation);
            DB::table('relation_types')->insert(['concept_id' => $id, 'ric_class' => $class, 'ric_property' => $property, 'ric_inverse' => $back, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('relation_type_pairs')->insert(array_map(fn ($p) => ['relation_type_id' => $id, 'source_kind' => $p[0], 'target_kind' => $p[1]], $pairs));
            foreach (['en' => [$forward, $inverse], 'af' => [$af[$forward], $af[$inverse]]] as $language => [$from, $to]) {
                DB::table('relation_type_phrases')->insert(['id' => (string) Str::uuid7(), 'relation_type_id' => $id, 'language' => $language,
                    'forward' => $from, 'inverse' => $to, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        // A place is associated with a rule, such as its jurisdiction, as with an activity.
        DB::table('relation_type_pairs')->insert(['relation_type_id' => $type('place'), 'source_kind' => 'place', 'target_kind' => 'rule']);
    }

    public function down(): void
    {
        DB::table('relation_type_pairs')->where(['relation_type_id' => DB::table('concepts')->where('iri', self::VOCAB.'relation-type/place')->value('id'), 'target_kind' => 'rule'])->delete();
        DB::table('relation_types')->whereIn('concept_id', DB::table('concepts')->whereIn('iri', [self::VOCAB.'relation-type/mandate', self::VOCAB.'relation-type/regulation'])->select('id'))->delete();
        $this->listed('deprecations', 'deprecations_subject_table', "'record_resources', 'agents', 'repositories', 'activities', 'places'");
        $this->listed('import_keys', 'import_keys_subject_table', "'record_resources', 'agents', 'repositories', 'places', 'concepts', 'activities'");
        $this->entityTables(self::ENTITY_TABLES_BEFORE);
        $this->named(self::NAMED_BEFORE);
        DB::statement('DROP TRIGGER IF EXISTS rules_has_authorised_name ON rules');
        Schema::dropIfExists('rules');
        DB::statement('DROP FUNCTION IF EXISTS rule_guard()');
    }

    private function listed(string $table, string $constraint, string $tables): void
    {
        DB::statement("ALTER TABLE $table DROP CONSTRAINT $constraint");
        DB::statement("ALTER TABLE $table ADD CONSTRAINT $constraint CHECK (subject_table IN ($tables))");
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
