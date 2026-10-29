<?php

// OpenRiC Workspace - community protocols (governance/HERATIO-PARITY.md, "Term", 28 October 2026).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Heratio's community protocol engine (ahg-core TermProtocolService and TermProtocolGate, ahg-icip):
// - concept_protocols is Heratio's term_protocol: an access condition on a term of the institution's own vocabularies,
//   with the Traditional Knowledge or Biocultural Label it carries, the owning community and the region;
// - community_label_conditions is icip_tk_label_type.default_access_condition: the condition each Label brings,
//   seeded from Heratio's map (AhgIcipServiceProvider::ensureProtocolColumns) and editable as rows;
// - community_labels.access_condition_id is Heratio's object_protocol: a condition on one record that overrides
//   its Label's default.
// A record is withheld from the public when the strictest condition of its tagged terms and its Labels is one that
// the community-access-condition vocabulary puts under "Withheld from the public".

use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const VOCAB = 'https://workspace.openric.org/vocab/';

    /** Heratio's Local Contexts Label code => access condition; the Labels not listed are open, an advisory badge only. */
    private const DEFAULTS = [
        'tk_s' => 'sacred-secret', 'tk_co' => 'restricted', 'tk_wr' => 'gendered', 'tk_mr' => 'gendered', 'tk_ss' => 'seasonal',
        'tk_cv' => 'community-voice', 'tk_a' => 'attribution', 'tk_cl' => 'attribution', 'tk_f' => 'attribution', 'tk_nc' => 'non-commercial',
        'bc_p' => 'attribution', 'bc_cl' => 'attribution', 'bc_cnc' => 'non-commercial',
    ];

    public function up(): void
    {
        foreach (config('workspace.bundled_vocabularies') as $file) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$file")));
        }
        Schema::create('concept_protocols', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('concept_id')->unique()->constrained('concepts')->cascadeOnDelete();
            $table->foreignUuid('access_condition_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('label_id')->nullable()->constrained('concepts')->restrictOnDelete();
            // The owning community, an authority record of the institution.
            $table->foreignUuid('community_id')->nullable()->constrained('agents')->restrictOnDelete();
            // Heratio's region_module, such as southern_africa: the regional set of protocols it follows.
            $table->text('region')->nullable();
            $table->timestampsTz();
        });
        Schema::create('community_label_conditions', function (Blueprint $table) {
            $table->foreignUuid('label_id')->primary()->constrained('concepts')->cascadeOnDelete();
            $table->foreignUuid('access_condition_id')->constrained('concepts')->restrictOnDelete();
            $table->timestampsTz();
        });
        Schema::table('community_labels', fn (Blueprint $table) => $table->foreignUuid('access_condition_id')->nullable()->constrained('concepts')->restrictOnDelete());

        foreach ([['concept_protocols', 'access_condition_id', 'community-access-condition/'], ['concept_protocols', 'label_id', 'tk-label/'],
            ['community_label_conditions', 'label_id', 'tk-label/'], ['community_label_conditions', 'access_condition_id', 'community-access-condition/'],
            ['community_labels', 'access_condition_id', 'community-access-condition/']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB.$vocabulary."')");
        }
        // A condition, not one of its two groups.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION access_condition_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.access_condition_id IS NOT NULL AND (SELECT broader_id FROM concepts WHERE id = NEW.access_condition_id) IS NULL THEN
                    RAISE EXCEPTION 'an access condition is one of the conditions, not the group Shown or Withheld' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$
            SQL);
        foreach (['concept_protocols', 'community_label_conditions', 'community_labels'] as $t) {
            DB::statement("CREATE TRIGGER {$t}_access_condition_guard BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION access_condition_guard()");
        }

        $condition = fn (string $notation) => DB::table('concepts')->where('iri', self::VOCAB."community-access-condition/$notation")->value('id');
        foreach (self::DEFAULTS as $code => $notation) {
            $label = DB::table('concepts')->where('notation', $code)->where('iri', 'like', 'https://localcontexts.org/label/%')->value('id');
            if ($label) {
                DB::table('community_label_conditions')->insert(['label_id' => $label, 'access_condition_id' => $condition($notation), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('community_labels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('access_condition_id');
        });
        DB::statement('DROP TRIGGER IF EXISTS community_labels_access_condition_guard ON community_labels');
        DB::statement('DROP TRIGGER IF EXISTS community_labels_access_condition_id_vocabulary ON community_labels');
        Schema::dropIfExists('community_label_conditions');
        Schema::dropIfExists('concept_protocols');
        DB::statement('DROP FUNCTION IF EXISTS access_condition_guard()');
    }
};
