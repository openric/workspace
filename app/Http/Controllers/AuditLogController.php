<?php

// OpenRiC Workspace - read the institution's audit log.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $rows = DB::table('audit_log as a')
            ->join('concepts as c', 'c.id', '=', 'a.action_id')
            ->leftJoin('concept_labels as l', fn ($j) => $j->on('l.concept_id', '=', 'c.id')->where('l.language', '=', 'en'))
            ->leftJoin('users as u', 'u.id', '=', 'a.actor_id')
            ->where('a.institution_id', $request->user()->institution_id)
            ->orderByDesc('a.occurred_at')
            ->select('a.occurred_at', 'l.pref_label as action', 'u.name as actor', 'a.subject_table', 'a.ip')
            ->paginate(50);

        return view('audit.index', ['rows' => $rows]);
    }
}
