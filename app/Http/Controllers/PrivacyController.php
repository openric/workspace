<?php

// OpenRiC Workspace - POPIA governance (roadmap phase 18a, ADR 0016), after Heratio's PrivacyController: the privacy
// dashboard with the POPIA report, each register's list, an entry made, seen and changed, its status, the special
// steps (submit and decide a processing entry, sign off an assessment, withdraw consent, verify a requester, notify
// of a compromise), the jurisdictions and the compliance control catalogue. Who may manage privacy does this work.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Models\ComplianceControl;
use App\Models\Institution;
use App\Models\PrivacyJurisdiction;
use App\Privacy\PrivacyService;
use App\Privacy\Registers;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class PrivacyController extends Controller
{
    public function __construct(private PrivacyService $privacy) {}

    public function index(Request $request): View
    {
        $institution = $this->authorise($request);
        $counts = collect(Registers::ALL)->map(fn ($d) => $d['model']::where('institution_id', $institution->id)->count());

        return view('privacy.index', ['report' => $this->privacy->report($institution), 'counts' => $counts, 'institution' => $institution]);
    }

    public function register(Request $request, string $register): View
    {
        $institution = $this->authorise($request);
        $definition = Registers::get($register);

        return view('privacy.register', ['register' => $register, 'definition' => $definition,
            'entries' => $definition['model']::where('institution_id', $institution->id)->orderByDesc('created_at')->get()]);
    }

    public function create(Request $request, string $register): View
    {
        $this->authorise($request);

        return view('privacy.entry', ['register' => $register, 'definition' => Registers::get($register), 'entry' => null]);
    }

    public function show(Request $request, string $register, string $id): View
    {
        return view('privacy.entry', ['register' => $register, 'definition' => Registers::get($register), 'entry' => $this->entry($request, $register, $id)]);
    }

    public function save(Request $request, string $register, ?string $id = null): RedirectResponse
    {
        $institution = $this->authorise($request);
        $entry = $id ? $this->entry($request, $register, $id) : null;
        try {
            $saved = $this->privacy->save($register, $institution, $request->all(), $entry, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['privacy' => $e->getMessage()]);
        }

        return redirect()->route('privacy.show', [$register, $saved->id])->with('status', __('Saved.'));
    }

    /** A step of an entry: its status, or one of the special steps of its register. */
    public function step(Request $request, string $register, string $id, string $step): RedirectResponse
    {
        $entry = $this->entry($request, $register, $id);
        $actor = $request->user();
        $data = $request->validate(['status' => ['nullable', 'string'], 'reason' => ['nullable', 'string', 'max:5000'], 'recipient' => ['nullable', 'string'], 'method' => ['nullable', 'string'],
            'recipient_name' => ['nullable', 'string', 'max:255'], 'sent_at' => ['nullable', 'date'], 'content' => ['nullable', 'string', 'max:10000']]);
        try {
            match ([$register, $step]) {
                [$register, 'status'] => $this->privacy->status($entry, $register, (string) ($data['status'] ?? ''), $actor),
                ['processing', 'submit'] => $this->privacy->submit($entry, $actor),
                ['processing', 'approve'] => $this->privacy->decide($entry, true, null, $actor),
                ['processing', 'reject'] => $this->privacy->decide($entry, false, $data['reason'] ?? null, $actor),
                ['assessments', 'sign-off'] => $this->privacy->signOff($entry, $actor),
                ['consent', 'withdraw'] => $this->privacy->withdraw($entry, $actor),
                ['requests', 'verify'] => $this->privacy->verify($entry, $actor),
                ['compromises', 'notify'] => $this->privacy->notify($entry, $data, $actor),
                default => abort(404),
            };
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['privacy' => $e->getMessage()]);
        }

        return back()->with('status', __('Saved.'));
    }

    public function controls(Request $request): View
    {
        $this->authorise($request);

        return view('privacy.controls', ['controls' => ComplianceControl::orderBy('position')->get(), 'jurisdictions' => PrivacyJurisdiction::orderBy('position')->get()]);
    }

    private function authorise(Request $request): Institution
    {
        abort_unless($request->user()->hasPermission(Vocab::permission('manage-privacy')), 403);

        return Institution::findOrFail($request->user()->institution_id);
    }

    private function entry(Request $request, string $register, string $id): Model
    {
        $institution = $this->authorise($request);

        return Registers::get($register)['model']::where(['institution_id' => $institution->id])->whereKey($id)->firstOrFail();
    }
}
