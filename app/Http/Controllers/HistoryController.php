<?php

// OpenRiC Workspace - the modification history of one entity, from the audit log (Heratio's "modifications").
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The entity's own rows and those of its parts: its names, description
// values, dates, identifiers and contact points, and the relations it takes
// part in. A part that was removed is found by what its audit row recorded.
// Needs update on the entity, as the history shows drafts and who wrote them.

namespace App\Http\Controllers;

use App\Relations\EntityKinds;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class HistoryController extends Controller
{
    /** Tables whose rows belong to an entity by subject_table and subject_id. */
    private const PARTS = ['names', 'description_values', 'dates', 'identifiers', 'contact_points'];

    public function show(Request $request, string $kind, string $id): View
    {
        $type = EntityKinds::bySegment($kind) ?? abort(404);
        $entity = EntityKinds::existing($type, $request->user()->institution_id)->find($id) ?? abort(404);
        abort_unless($request->user()->may('update', EntityKinds::get($type)['permission'], $entity), 403);
        $table = $entity->getTable();
        $of = fn ($json) => fn ($q) => $q->whereRaw("($json ->> 'subject_table') = ?", [$table])->whereRaw("($json ->> 'subject_id') = ?", [$id]);
        $end = fn ($json) => fn ($q) => $q->where(fn ($q) => $q->whereRaw("($json ->> 'source_table') = ? AND ($json ->> 'source_id') = ?", [$table, $id])
            ->orWhereRaw("($json ->> 'target_table') = ? AND ($json ->> 'target_id') = ?", [$table, $id]));
        // An update records only what changed, so the parts that exist now are found by their ids.
        $partIds = collect(self::PARTS)->flatMap(fn ($t) => DB::table($t)->where(['subject_table' => $table, 'subject_id' => $id])->pluck('id'))->all();
        $relationIds = DB::table('relations')->where(fn ($q) => $q->where(['source_table' => $table, 'source_id' => $id])->orWhere(fn ($q) => $q->where(['target_table' => $table, 'target_id' => $id])))->pluck('id')->all();
        $rows = DB::table('audit_log as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_id')->leftJoin('concepts as c', 'c.id', '=', 'a.action_id')
            ->where('a.institution_id', $request->user()->institution_id)
            ->where(fn ($q) => $q->where(['a.subject_table' => $table, 'a.subject_id' => $id])
                ->orWhere(fn ($q) => $q->whereIn('a.subject_table', self::PARTS)->where(fn ($q) => $q->where($of('a.before'))->orWhere($of('a.after'))))
                ->orWhere(fn ($q) => $q->where('a.subject_table', 'relations')->where(fn ($q) => $q->where($end('a.before'))->orWhere($end('a.after'))))
                ->orWhere(fn ($q) => $q->whereIn('a.subject_table', self::PARTS)->whereIn('a.subject_id', $partIds))
                ->orWhere(fn ($q) => $q->where('a.subject_table', 'relations')->whereIn('a.subject_id', $relationIds)))
            ->orderByDesc('a.occurred_at')->limit(500)
            ->get(['a.occurred_at', 'a.subject_table', 'a.before', 'a.after', 'u.name as actor', 'c.notation as action']);

        return view('description.history', ['entity' => $entity, 'rows' => $rows]);
    }
}
