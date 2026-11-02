<?php

// OpenRiC Workspace - the jurisdiction packs an institution switches on in its settings (roadmap phase 18, ADR 0016):
// transfers to the National Archives and Records Service of South Africa, NHRA s.32 export permits, the National
// Archives of Zimbabwe and Zimbabwe's Cyber and Data Protection Act. A pack switched off has no menu entry and its pages
// answer 404; what it holds is kept (Dr Johan Pieterse, 2 November 2026).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        app(SkosImporter::class)->import(file_get_contents(resource_path('vocabularies/jurisdiction-pack.ttl')));
        Schema::create('institution_jurisdiction_packs', function (Blueprint $table) {
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('pack_id')->constrained('concepts')->restrictOnDelete();
            $table->primary(['institution_id', 'pack_id']);
        });
        DB::statement("CREATE TRIGGER institution_jurisdiction_packs_pack_id_vocabulary BEFORE INSERT OR UPDATE ON institution_jurisdiction_packs FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('pack_id', 'https://workspace.openric.org/vocab/jurisdiction-pack/')");
    }

    public function down(): void
    {
        Schema::dropIfExists('institution_jurisdiction_packs');
    }
};
