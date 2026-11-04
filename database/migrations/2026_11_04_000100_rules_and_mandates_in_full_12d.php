<?php

// OpenRiC Workspace - rules and mandates in full (roadmap step 12d, ADR 0018): a rule's status (draft, in force,
// amended, repealed), its history, RiC-O 1.1's relations of a rule to the agent that issued it, the agent that enforces
// it and the record that carries its text, rules amending and superseding one another, and the rule a retention
// schedule is issued under.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const VOCAB = 'https://workspace.openric.org/vocab/';

    /** notation => [pairs, forward, inverse, [class, property, inverse property]] */
    private const TYPES = [
        'issuance' => [[['rule', 'agent']], 'was issued by', 'issued', ['rico:RuleRelation', 'rico:issuedBy', 'rico:isResponsibleForIssuing']],
        'enforcement' => [[['rule', 'agent']], 'is or was enforced by', 'enforces or enforced', ['rico:RuleRelation', 'rico:isOrWasEnforcedBy', 'rico:isOrWasResponsibleForEnforcing']],
        'expression' => [[['rule', 'record']], 'is or was expressed in', 'expresses or expressed', ['rico:RuleRelation', 'rico:isOrWasExpressedBy', 'rico:expressesOrExpressed']],
        'amendment' => [[['rule', 'rule']], 'amends or amended', 'is or was amended by', ['rico:RuleRelation', 'openricx:amendsOrAmended', 'openricx:isOrWasAmendedBy']],
        'supersession' => [[['rule', 'rule']], 'supersedes or superseded', 'is or was superseded by', ['rico:RuleRelation', 'openricx:supersedesOrSuperseded', 'openricx:isOrWasSupersededBy']],
    ];

    public function up(): void
    {
        foreach (['rule-status', 'description-element', 'relation-type'] as $name) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$name.ttl")));
        }
        Schema::table('rules', fn (Blueprint $table) => $table->foreignUuid('status_id')->nullable()->constrained('concepts')->restrictOnDelete());
        DB::statement("CREATE TRIGGER rules_status_id_vocabulary BEFORE INSERT OR UPDATE ON rules FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('status_id', '".self::VOCAB."rule-status/')");
        // The disposal authority a schedule is issued under, as a rule; the text stays for one not described as a rule.
        Schema::table('retention_schedules', fn (Blueprint $table) => $table->foreignUuid('authority_rule_id')->nullable()->constrained('rules')->nullOnDelete());

        $af = json_decode(file_get_contents(lang_path('af.json')), true);
        foreach (self::TYPES as $notation => [$pairs, $forward, $inverse, [$class, $property, $back]]) {
            $id = DB::table('concepts')->where('iri', self::VOCAB."relation-type/$notation")->value('id');
            DB::table('relation_types')->insert(['concept_id' => $id, 'ric_class' => $class, 'ric_property' => $property, 'ric_inverse' => $back, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('relation_type_pairs')->insert(array_map(fn ($p) => ['relation_type_id' => $id, 'source_kind' => $p[0], 'target_kind' => $p[1]], $pairs));
            foreach (['en' => [$forward, $inverse], 'af' => [$af[$forward], $af[$inverse]]] as $language => [$from, $to]) {
                DB::table('relation_type_phrases')->insert(['id' => (string) Str::uuid7(), 'relation_type_id' => $id, 'language' => $language,
                    'forward' => $from, 'inverse' => $to, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('concepts')->whereIn('iri', array_map(fn ($n) => self::VOCAB."relation-type/$n", array_keys(self::TYPES)))->pluck('id');
        DB::table('relation_type_phrases')->whereIn('relation_type_id', $ids)->delete();
        DB::table('relation_type_pairs')->whereIn('relation_type_id', $ids)->delete();
        DB::table('relation_types')->whereIn('concept_id', $ids)->delete();
        Schema::table('retention_schedules', fn (Blueprint $table) => $table->dropConstrainedForeignId('authority_rule_id'));
        DB::statement('DROP TRIGGER IF EXISTS rules_status_id_vocabulary ON rules');
        Schema::table('rules', fn (Blueprint $table) => $table->dropConstrainedForeignId('status_id'));
    }
};
