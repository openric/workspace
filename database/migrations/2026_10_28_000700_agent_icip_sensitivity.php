<?php

// OpenRiC Workspace - an authority record's ICIP sensitivity (governance/HERATIO-PARITY.md, "Authority record", 28 October 2026).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Heratio's actor.icip_sensitivity: one of the seven sensitivity levels of its ICIP vocabulary, shown with the record.

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
        Schema::table('agents', fn (Blueprint $table) => $table->foreignUuid('icip_sensitivity_id')->nullable()->constrained('concepts')->restrictOnDelete());
        DB::statement("CREATE TRIGGER agents_icip_sensitivity_id_vocabulary BEFORE INSERT OR UPDATE ON agents FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('icip_sensitivity_id', '".self::VOCAB."icip-sensitivity/')");
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS agents_icip_sensitivity_id_vocabulary ON agents');
        Schema::table('agents', fn (Blueprint $table) => $table->dropConstrainedForeignId('icip_sensitivity_id'));
    }
};
