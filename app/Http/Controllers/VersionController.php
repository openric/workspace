<?php

// OpenRiC Workspace - the version history of a unit of description or an authority record: its versions, one of them,
// two compared, and restoring one (roadmap phase 22b), after Heratio's ahg-version-control VersionController.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Models\EntityVersion;
use App\Relations\EntityKinds;
use App\Versions\Versions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class VersionController extends Controller
{
    private const KINDS = ['records' => ['record_resources', 'description'], 'agents' => ['agents', 'authority']];

    public function index(Request $request, string $kind, string $id): View
    {
        $entity = $this->entity($request, $kind, $id);
        $versions = EntityVersion::where(['subject_table' => $entity->getTable(), 'subject_id' => $entity->getKey()])->with('createdBy')->orderByDesc('version_number')->get();
        $shown = $request->query('show') ? $versions->firstWhere('version_number', (int) $request->query('show')) : null;
        $compare = null;
        if ($request->query('from') && $request->query('to')) {
            [$a, $b] = [$versions->firstWhere('version_number', (int) $request->query('from')), $versions->firstWhere('version_number', (int) $request->query('to'))];
            abort_unless($a && $b, 404);
            $compare = ['from' => $a, 'to' => $b, 'diff' => app(Versions::class)->diff($a->snapshot, $b->snapshot)];
        }

        return view('versions.index', ['entity' => $entity, 'kind' => $kind, 'versions' => $versions, 'shown' => $shown, 'compare' => $compare,
            'mayRestore' => $request->user()->may('update', self::KINDS[$kind][1], $entity)]);
    }

    public function restore(Request $request, string $kind, string $id): RedirectResponse
    {
        $entity = $this->entity($request, $kind, $id);
        $number = (int) $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        try {
            $version = app(Versions::class)->restore($request->user(), $entity, $number);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['versions' => $e->getMessage()]);
        }

        return redirect()->route('versions.index', [$kind, $id])->with('status', __('Version :n restored as version :new; what changed is a draft again, for review.', ['n' => $number, 'new' => $version->version_number]));
    }

    private function entity(Request $request, string $kind, string $id): Model
    {
        [$table, $permission] = self::KINDS[$kind] ?? abort(404);
        $entity = Versions::KINDS[$table]::where('institution_id', $request->user()->institution_id)->whereNull('deleted_at')->find($id) ?? abort(404);
        abort_unless($request->user()->may('update', $permission, $entity) && EntityKinds::readable($request->user(), $entity), 404);

        return $entity;
    }
}
