<?php

// OpenRiC Workspace - a repository's premises: the places it is or was at, with when (roadmap phase 23b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// ISDIAH 5.3.6 kept a repository's buildings only as text. The design review of 2 November 2026 asks that a holding
// body's moves between places stay traceable (scenario B), so a repository is now related to the places it is or was
// at, each relation dated, exported as rico:hasOrHadLocation.

use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const VOCAB = 'https://workspace.openric.org/vocab/';

    public function up(): void
    {
        app(SkosImporter::class)->import(file_get_contents(resource_path('vocabularies/relation-type.ttl')));
        $id = DB::table('concepts')->where('iri', self::VOCAB.'relation-type/premises')->value('id');
        DB::table('relation_types')->insert(['concept_id' => $id, 'own_section' => true, 'ric_class' => 'rico:Relation', 'ric_property' => 'rico:hasOrHadLocation', 'ric_inverse' => 'rico:isOrWasLocationOf',
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('relation_type_pairs')->insert(['relation_type_id' => $id, 'source_kind' => 'repository', 'target_kind' => 'place']);
        $af = json_decode(file_get_contents(lang_path('af.json')), true);
        foreach (['en' => ['is or was located at', 'is or was the location of'], 'af' => [$af['is or was located at'], $af['is or was the location of']]] as $language => [$forward, $inverse]) {
            DB::table('relation_type_phrases')->insert(['id' => (string) Str::uuid7(), 'relation_type_id' => $id, 'language' => $language,
                'forward' => $forward, 'inverse' => $inverse, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('relation_types')->where('concept_id', DB::table('concepts')->where('iri', self::VOCAB.'relation-type/premises')->value('id'))->delete();
    }
};
