<?php

// OpenRiC Workspace - an activity may result in an instantiation (OpenRiC Provenance and Event profile 0.9.0, spec v0.60.0).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// RiC-O 1.1 gives rico:resultsOrResultedIn the range rico:Thing; the profile had narrowed it to record resources, so
// a digitisation or survey photography activity could not say it resulted in the photographs it made. Dr Johan
// Pieterse widened the profile's :ResultTypeShape to rico:Instantiation on 23 November 2026, and the Workspace's
// "result" relation type follows it.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const VOCAB = 'https://workspace.openric.org/vocab/';

    private function pair(): array
    {
        return ['relation_type_id' => DB::table('concepts')->where('iri', self::VOCAB.'relation-type/result')->value('id'),
            'source_kind' => 'activity', 'target_kind' => 'instantiation'];
    }

    public function up(): void
    {
        DB::table('relation_type_pairs')->insert($this->pair());
    }

    public function down(): void
    {
        DB::table('relation_type_pairs')->where($this->pair())->delete();
    }
};
