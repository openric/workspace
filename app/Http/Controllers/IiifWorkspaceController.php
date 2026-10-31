<?php

// OpenRiC Workspace - the IIIF viewer layouts an account saves (roadmap phase 14b, ADR 0014), after Heratio's
// WorkspaceController and WorkspaceService: save the open windows from the viewer, open them again, rename, delete,
// and choose the one the viewer opens with. Each account sees only its own.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Audit\AuditLog;
use App\Models\IiifViewerWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class IiifWorkspaceController extends Controller
{
    public function index(Request $request): View
    {
        return view('iiif.workspaces', ['workspaces' => IiifViewerWorkspace::where('user_id', $request->user()->id)->orderByDesc('is_default')->orderBy('name')->get()]);
    }

    /** Save the viewer's windows: each a manifest of this Workspace and the canvas it shows. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'windows' => ['required', 'json']]);
        $windows = $this->windows(json_decode($data['windows'], true));
        if ($windows === []) {
            return back()->withErrors(['windows' => __('Open a manifest of this Workspace in the viewer first.')]);
        }
        $saved = DB::transaction(fn () => AuditLog::actingAs($request->user(), fn () => IiifViewerWorkspace::create(['user_id' => $request->user()->id,
            'name' => trim($data['name']), 'windows' => $windows])));

        return redirect()->route('iiif-workspaces.index')->with('status', __('Saved: :name.', ['name' => $saved->name]));
    }

    public function update(Request $request, IiifViewerWorkspace $workspace): RedirectResponse
    {
        $this->own($request, $workspace);
        $data = $request->validate(['name' => ['nullable', 'string', 'max:255'], 'default' => ['nullable', 'boolean']]);
        DB::transaction(fn () => AuditLog::actingAs($request->user(), function () use ($request, $workspace, $data) {
            if ($request->boolean('default')) {
                IiifViewerWorkspace::where('user_id', $workspace->user_id)->where('id', '!=', $workspace->id)->where('is_default', true)->update(['is_default' => false]);
            }
            $workspace->update(array_filter(['name' => ($data['name'] ?? '') !== '' ? trim($data['name']) : null,
                'is_default' => $request->has('default') ? $request->boolean('default') : null], fn ($v) => $v !== null));
        }));

        return back()->with('status', __('Saved.'));
    }

    public function destroy(Request $request, IiifViewerWorkspace $workspace): RedirectResponse
    {
        $this->own($request, $workspace);
        DB::transaction(fn () => AuditLog::actingAs($request->user(), fn () => $workspace->delete()));

        return back()->with('status', __('Deleted: :name.', ['name' => $workspace->name]));
    }

    /** Open it in the viewer: the page of the first window's record, with every window. */
    public function open(Request $request, IiifViewerWorkspace $workspace): RedirectResponse
    {
        $this->own($request, $workspace);
        preg_match('~/iiif/records/([0-9a-f-]{36})/manifest$~', $workspace->windows[0]['manifestId'] ?? '', $m) || abort(404);

        return redirect()->route('discover.iiif', [$m[1], 'workspace' => $workspace->id]);
    }

    /** Only manifests and canvases of this Workspace are kept. */
    public static function windows(mixed $windows): array
    {
        $prefix = url('/iiif/records/');
        $out = [];
        foreach (is_array($windows) ? array_slice($windows, 0, 12) : [] as $window) {
            $manifest = is_array($window) ? ($window['manifestId'] ?? null) : null;
            if (is_string($manifest) && str_starts_with($manifest, $prefix) && preg_match('~/iiif/records/[0-9a-f-]{36}/manifest$~', $manifest)) {
                $canvas = $window['canvasId'] ?? null;
                $out[] = array_filter(['manifestId' => $manifest, 'canvasId' => is_string($canvas) && str_starts_with($canvas, $manifest.'/canvas/') ? $canvas : null]);
            }
        }

        return $out;
    }

    private function own(Request $request, IiifViewerWorkspace $workspace): void
    {
        abort_unless($workspace->user_id === $request->user()->id, 404);
    }
}
