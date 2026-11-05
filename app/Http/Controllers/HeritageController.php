<?php

/*
 * OpenRiC Workspace - heritage at risk and the displaced-heritage register for staff (roadmap phase 17d3), copied from
 * Heratio, packages/ahg-semantic-search/src/Controllers/EndangeredHeritageController.php (worklist, flagForm, flag,
 * captureStatus), EndangeredInboundController.php (index, review) and DisplacedHeritageController.php (index) at
 * commit bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md). Changed: a description is flagged
 * by whoever may change it (Heratio: administrators); the displaced-heritage register is for those who manage
 * repatriation claims, each traced description a step from a claim; pushed flags and the institution's choices are
 * for who manages the institution, as federation is; the namespace and code style.
 * Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Http\Controllers;

use App\Audit\AuditLog;
use App\Heritage\Displaced;
use App\Heritage\Endangered;
use App\Heritage\EndangeredNetwork;
use App\Models\Concept;
use App\Models\EndangeredInboundFlag;
use App\Models\EndangeredItem;
use App\Models\EndangeredSetting;
use App\Models\RecordResource;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class HeritageController extends Controller
{
    public function __construct(private Endangered $endangered, private EndangeredNetwork $network, private Displaced $displaced) {}

    /** The worklist, most urgent first, and every flag by its capture status. */
    public function atRisk(Request $request): View
    {
        abort_unless($request->user()->mayAnywhere('update', 'description'), 403);
        $institution = $request->user()->institution;
        $status = Concept::where('iri', Endangered::CAPTURE.$request->query('status'))->exists() ? $request->query('status') : null;
        $manages = $request->user()->hasPermission(Vocab::permission('manage-institution'));

        return view('heritage.at-risk', ['worklist' => $this->endangered->priorityList($institution), 'flags' => $this->endangered->list($institution, $status),
            'status' => $status, 'statuses' => Concept::choices(Endangered::CAPTURE)->get(), 'counts' => $this->endangered->counts($institution, 'capture_status_id', Endangered::CAPTURE),
            'manages' => $manages, 'settings' => EndangeredSetting::for($institution->id), 'pending' => $manages ? ($this->network->reviewCounts($institution)['pending'] ?? 0) : 0,
            'disclaimer' => Endangered::DISCLAIMER]);
    }

    public function flagForm(Request $request): View
    {
        $record = $this->record($request, (string) $request->query('record'));

        return view('heritage.flag', ['record' => $record, 'item' => EndangeredItem::where('record_resource_id', $record->id)->first(),
            'risks' => Concept::choices(Endangered::RISKS)->get(), 'urgencies' => Concept::choices(Endangered::URGENCIES)->get(),
            'statuses' => Concept::choices(Endangered::CAPTURE)->get(), 'disclaimer' => Endangered::DISCLAIMER]);
    }

    public function flag(Request $request): RedirectResponse
    {
        $record = $this->record($request, (string) $request->input('record'));
        $data = $request->validate(['risk' => ['required', 'string', 'max:64'], 'urgency' => ['required', 'string', 'max:64'], 'capture_status' => ['required', 'string', 'max:64'],
            'reason' => ['nullable', 'string', 'max:20000']]);
        $this->attempt(fn () => $this->endangered->flag($record, $data, $request->user()));

        return redirect()->route('records.show', $record)->with('status', __('The description is flagged for prioritised capture.'));
    }

    public function captureStatus(Request $request, EndangeredItem $item): RedirectResponse
    {
        $this->record($request, $item->record_resource_id);
        $data = $request->validate(['capture_status' => ['required', 'string', 'max:64']]);
        $this->attempt(fn () => $this->endangered->setCaptureStatus($item, $data['capture_status'], $request->user()));

        return back()->with('status', __('Capture status updated.'));
    }

    public function inbound(Request $request): View
    {
        $this->manages($request);
        $institution = $request->user()->institution;

        return view('heritage.inbound', ['flags' => $this->network->pending($institution), 'counts' => $this->network->reviewCounts($institution)]);
    }

    public function review(Request $request, EndangeredInboundFlag $flag): RedirectResponse
    {
        $this->manages($request);
        abort_unless($flag->institution_id === $request->user()->institution_id, 404);
        $data = $request->validate(['decision' => ['required', 'in:accepted,declined']]);
        $this->network->review($flag, $data['decision'], $request->user());

        return back()->with('status', $data['decision'] === 'accepted' ? __('Pushed flag accepted - it will now appear on the network board.') : __('Pushed flag declined.'));
    }

    public function settings(Request $request): RedirectResponse
    {
        $this->manages($request);
        $values = ['public_register' => $request->boolean('public_register'), 'share_with_peers' => $request->boolean('share_with_peers'), 'accept_pushes' => $request->boolean('accept_pushes')];
        DB::transaction(fn () => AuditLog::actingAs($request->user(), function () use ($request, $values) {
            EndangeredSetting::for($request->user()->institution_id)->fill($values)->save();
            AuditLog::record(Vocab::action('update'), $request->user()->institution, null, ['endangered_settings' => $values]);
        }));

        return redirect()->route('heritage.at-risk')->with('status', __('Saved.'));
    }

    /** The displaced-heritage register, perhaps of one region of origin. */
    public function displaced(Request $request): View
    {
        abort_unless($request->user()->hasPermission(Vocab::permission('manage-repatriation')), 403);
        $report = $this->displaced->scan($request->user()->institution, null, 0);
        $origin = trim((string) $request->query('origin'));

        return view('heritage.displaced', ['report' => $report, 'origin' => $origin,
            'records' => $origin === '' ? $report['records'] : array_values(array_filter($report['records'], fn ($r) => strcasecmp($r['origin_region'], $origin) === 0))]);
    }

    private function record(Request $request, string $id): RecordResource
    {
        $record = RecordResource::where('institution_id', $request->user()->institution_id)->whereNull('deleted_at')->findOrFail($id);
        abort_unless($request->user()->may('update', 'description', $record), 403);

        return $record;
    }

    private function manages(Request $request): void
    {
        abort_unless($request->user()->hasPermission(Vocab::permission('manage-institution')), 403);
    }

    private function attempt(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['flag' => $e->getMessage()]);
        }
    }
}
