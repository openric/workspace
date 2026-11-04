<?php

// OpenRiC Workspace - the last fixed lists that describe something become vocabularies (roadmap phase 20d): what a
// publish gate found (passed, failed, warning), and the page size and orientation of a print template. Each row keeps
// what it had, now as a concept; the lists that standards define (HTML5 caption kinds, BagIt algorithms) stay fixed.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

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
        foreach (['publish-gate-outcome', 'print-page-size', 'page-orientation'] as $vocabulary) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$vocabulary.ttl")));
        }
        $concept = fn (string $vocabulary, string $notation) => DB::table('concepts')->where('iri', self::VOCAB.$vocabulary.'/'.$notation)->value('id');

        Schema::table('publish_gate_results', fn (Blueprint $table) => $table->foreignUuid('outcome_id')->nullable()->constrained('concepts')->restrictOnDelete());
        foreach (['passed', 'failed', 'warning'] as $outcome) {
            DB::table('publish_gate_results')->where('status', $outcome)->update(['outcome_id' => $concept('publish-gate-outcome', $outcome)]);
        }
        DB::statement('ALTER TABLE publish_gate_results DROP CONSTRAINT IF EXISTS publish_gate_results_status');
        Schema::table('publish_gate_results', function (Blueprint $table) {
            $table->dropColumn('status');
        });
        DB::statement('ALTER TABLE publish_gate_results ALTER COLUMN outcome_id SET NOT NULL');

        Schema::table('research_print_templates', function (Blueprint $table) {
            $table->foreignUuid('page_size_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('orientation_id')->nullable()->constrained('concepts')->restrictOnDelete();
        });
        foreach (['A4' => 'a4', 'A5' => 'a5', 'A6' => 'a6', 'Letter' => 'letter'] as $old => $notation) {
            DB::table('research_print_templates')->where('page_size', $old)->update(['page_size_id' => $concept('print-page-size', $notation)]);
        }
        foreach (['portrait', 'landscape'] as $orientation) {
            DB::table('research_print_templates')->where('orientation', $orientation)->update(['orientation_id' => $concept('page-orientation', $orientation)]);
        }
        DB::statement('ALTER TABLE research_print_templates DROP CONSTRAINT IF EXISTS research_print_templates_page_check');
        Schema::table('research_print_templates', function (Blueprint $table) {
            $table->dropColumn(['page_size', 'orientation']);
        });
        DB::statement('ALTER TABLE research_print_templates ALTER COLUMN page_size_id SET NOT NULL');
        DB::statement('ALTER TABLE research_print_templates ALTER COLUMN orientation_id SET NOT NULL');

        foreach ([['publish_gate_results', 'outcome_id', 'publish-gate-outcome'], ['research_print_templates', 'page_size_id', 'print-page-size'],
            ['research_print_templates', 'orientation_id', 'page-orientation']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS publish_gate_results_outcome_id_vocabulary ON publish_gate_results');
        DB::statement('DROP TRIGGER IF EXISTS research_print_templates_page_size_id_vocabulary ON research_print_templates');
        DB::statement('DROP TRIGGER IF EXISTS research_print_templates_orientation_id_vocabulary ON research_print_templates');
        Schema::table('publish_gate_results', fn (Blueprint $table) => $table->string('status', 10)->default('passed'));
        DB::statement('UPDATE publish_gate_results r SET status = c.notation FROM concepts c WHERE c.id = r.outcome_id');
        Schema::table('publish_gate_results', fn (Blueprint $table) => $table->dropConstrainedForeignId('outcome_id'));
        Schema::table('research_print_templates', function (Blueprint $table) {
            $table->string('page_size', 10)->default('A4');
            $table->string('orientation', 10)->default('portrait');
        });
        DB::statement('UPDATE research_print_templates t SET page_size = upper(c.notation) FROM concepts c WHERE c.id = t.page_size_id');
        DB::statement("UPDATE research_print_templates t SET page_size = 'Letter' WHERE page_size = 'LETTER'");
        DB::statement('UPDATE research_print_templates t SET orientation = c.notation FROM concepts c WHERE c.id = t.orientation_id');
        Schema::table('research_print_templates', fn (Blueprint $table) => $table->dropConstrainedForeignId('page_size_id'));
        Schema::table('research_print_templates', fn (Blueprint $table) => $table->dropConstrainedForeignId('orientation_id'));
    }
};
