<?php

// OpenRiC Workspace - the IIIF validation dashboard (roadmap phase 14b, ADR 0014), after PSIS's validation dashboard:
// the latest outcome of every record checked, recent failures, a record's runs, and checking one record or all those
// with files. Who may publish descriptions may check them.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Iiif\Validation;
use App\Models\Institution;
use App\Models\RecordResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IiifValidationController extends Controller
{
    public function __construct(private Validation $validation) {}

    public function index(Request $request): View
    {
        $this->authorise($request);

        return view('iiif.validation', ['dashboard' => $this->validation->dashboard(Institution::findOrFail($request->user()->institution_id))]);
    }

    public function record(Request $request, RecordResource $record): View
    {
        $this->authorise($request, $record);

        return view('iiif.validation-record', ['unit' => $record, 'runs' => $this->validation->history($record)]);
    }

    public function validateRecord(Request $request, RecordResource $record): RedirectResponse
    {
        $this->authorise($request, $record);
        $this->validation->validate($record, $request->user());

        return redirect()->route('iiif-validation.record', $record)->with('status', __('Checked.'));
    }

    /** Check every record with a file. ponytail: synchronous; a large catalogue wants it as a queued job. */
    public function validateAll(Request $request): RedirectResponse
    {
        $this->authorise($request);
        $summary = $this->validation->validateAll(Institution::findOrFail($request->user()->institution_id), $request->user());

        return back()->with('status', __(':total checked: :passed passed, :warning with warnings, :failed failed.', $summary));
    }

    private function authorise(Request $request, ?RecordResource $record = null): void
    {
        abort_unless($request->user()->mayAnywhere('publish', 'description'), 403);
        abort_if($record && ($record->institution_id !== $request->user()->institution_id || $record->deleted_at), 404);
    }
}
