<?php

// OpenRiC Workspace - the places a fonds most uses (roadmap phase 17b2), after Heratio's DocumentPriorService: for the
// unit a mention is in, its fonds (the top of its hierarchy) and every unit inside it, the places related to those
// units by RiC place relations that are not rejected, counted, most first. A mention accepted as a place makes such a
// relation, so what archivists have decided counts too. Kept for a day, as Heratio keeps it.
// Changed from Heratio: counted from the RiC relations themselves rather than from linked mentions only, so places
// described by hand count as well.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Authority\Evidence;

use App\Models\RecordResource;
use App\Support\Vocab;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DocumentPrior
{
    /** @return array{fonds_id: ?string, unit_count: int, distribution: array<string, int>} place id => units it is related to */
    public function priorFor(RecordResource $unit): array
    {
        $fonds = $unit->ancestors()[0] ?? $unit;

        return Cache::remember('authority-prior:'.$fonds->id, now()->addDay(), function () use ($fonds) {
            $ids = collect([$fonds->id, ...$fonds->descendantIds()])->unique()->values();
            $type = DB::table('concepts')->where('iri', Vocab::BASE.'relation-type/place')->value('id');
            $rejected = DB::table('concepts')->where('iri', Vocab::BASE.'assertion-status/rejected')->value('id');
            $distribution = DB::table('relations as r')->where('r.relation_type_id', $type)->where('r.source_table', 'places')
                ->where('r.target_table', 'record_resources')->whereIn('r.target_id', $ids)
                ->whereExists(fn ($q) => $q->from('relation_assertions as a')->whereColumn('a.relation_id', 'r.id')->where('a.status_id', '!=', $rejected))
                ->selectRaw('r.source_id, count(DISTINCT r.target_id) AS n')->groupBy('r.source_id')->orderByDesc('n')->pluck('n', 'source_id')->map(fn ($n) => (int) $n)->all();

            return ['fonds_id' => $fonds->id, 'unit_count' => $ids->count(), 'distribution' => $distribution];
        });
    }
}
