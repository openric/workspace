<?php

// OpenRiC Workspace - security clearance: levels, compartments, accounts' clearances, records' classifications and
// requests for access above one's clearance (roadmap phase 18c, ADR 0016), after Heratio's
// SecurityClearanceController (its dashboard, levels, compartments, clearances, classify, declassify, access requests).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Models\AccountClearance;
use App\Models\AccountCompartment;
use App\Models\ClearanceGrant;
use App\Models\ClearanceHistoryEntry;
use App\Models\ClearanceRequest;
use App\Models\Concept;
use App\Models\RecordClassification;
use App\Models\RecordResource;
use App\Models\SecurityCompartment;
use App\Models\SecurityLevel;
use App\Models\User;
use App\Rights\Clearances;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class ClearanceController extends Controller
{
    public function __construct(private Clearances $clearances) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->hasPermission(Vocab::permission('manage-institution')), 403);
        $institution = $user->institution_id;
        $records = fn ($q) => $q->where('institution_id', $institution);

        return view('clearance.index', ['levels' => $this->clearances->levels($institution),
            'compartments' => SecurityCompartment::where('institution_id', $institution)->with('minLevel')->orderBy('code')->get(),
            'accounts' => User::where('institution_id', $institution)->orderBy('name')->get(),
            'clearances' => AccountClearance::whereHas('user', $records)->with('user', 'level', 'grantedBy')->get()->sortBy('user.name'),
            'compartmentsHeld' => AccountCompartment::whereHas('user', $records)->with('compartment')->get()->groupBy('user_id'),
            'requests' => ClearanceRequest::whereHas('record', $records)->whereHas('status', fn ($q) => $q->where('iri', Vocab::BASE.'clearance-request-status/pending'))->with('user', 'record', 'requestType', 'priority')->get(),
            'grants' => ClearanceGrant::whereHas('record', $records)->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->with('user', 'record', 'requestType')->get(),
            'classified' => RecordClassification::whereHas('record', $records)->with('record', 'level', 'declassifyTo')->orderBy('declassify_on')->get(),
            'history' => ClearanceHistoryEntry::whereIn('user_id', User::where('institution_id', $institution)->select('id'))->with('user', 'action', 'previousLevel', 'newLevel', 'changedBy')->latest('created_at')->limit(30)->get(),
            'mine' => $this->clearances->levelOf($user)]);
    }

    public function step(Request $request, string $step, ?string $id = null): RedirectResponse
    {
        $user = $request->user();
        $institution = $user->institution_id;

        return match ($step) {
            'level' => $this->attempt(fn () => $this->clearances->saveLevel($user, $request->validate(['code' => ['required', 'string', 'max:20'], 'level' => ['required', 'integer', 'min:0', 'max:100'],
                'name' => ['required', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:2000'], 'colour' => ['nullable', 'string', 'max:20'], 'max_session_hours' => ['nullable', 'integer', 'min:1'],
                'requires_justification' => ['nullable', 'boolean'], 'requires_approval' => ['nullable', 'boolean'], 'requires_2fa' => ['nullable', 'boolean'], 'watermark_required' => ['nullable', 'boolean'],
                'download_allowed' => ['nullable', 'boolean'], 'print_allowed' => ['nullable', 'boolean'], 'copy_allowed' => ['nullable', 'boolean'], 'is_active' => ['nullable', 'boolean']]),
                $id ? SecurityLevel::where('institution_id', $institution)->findOrFail($id) : null), __('Saved.')),
            'compartment' => $this->attempt(fn () => $this->clearances->saveCompartment($user, $request->validate(['code' => ['required', 'string', 'max:50'], 'name' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:2000'], 'min_level_id' => ['required', 'uuid'], 'requires_need_to_know' => ['nullable', 'boolean'], 'requires_briefing' => ['nullable', 'boolean'],
                'is_active' => ['nullable', 'boolean']]), $id ? SecurityCompartment::where('institution_id', $institution)->findOrFail($id) : null), __('Saved.')),
            'grant' => $this->attempt(function () use ($request, $user, $institution) {
                $data = $request->validate(['user_id' => ['required', 'uuid'], 'level_id' => ['required', 'uuid'], 'expires_on' => ['nullable', 'date_format:Y-m-d'], 'notes' => ['nullable', 'string', 'max:2000'],
                    'compartments' => ['array'], 'compartments.*' => ['uuid']]);
                $this->clearances->grant($user, User::where('institution_id', $institution)->findOrFail($data['user_id']), SecurityLevel::where('institution_id', $institution)->findOrFail($data['level_id']),
                    $data['expires_on'] ?? null, $data['notes'] ?? null, $data['compartments'] ?? []);
            }, __('Clearance granted.')),
            'revoke' => $this->attempt(fn () => $this->clearances->revoke($user, User::where('institution_id', $institution)->findOrFail($id), $request->input('reason')), __('Clearance revoked.')),
            'approve', 'deny' => $this->attempt(fn () => $this->clearances->review($user, ClearanceRequest::whereHas('record', fn ($q) => $q->where('institution_id', $institution))->findOrFail($id),
                $step === 'approve', $request->input('notes'), $request->filled('duration_hours') ? max(1, (int) $request->input('duration_hours')) : null), $step === 'approve' ? __('Access granted.') : __('Denied.')),
            'revoke-grant' => $this->attempt(fn () => $this->clearances->revokeGrant($user, ClearanceGrant::whereHas('record', fn ($q) => $q->where('institution_id', $institution))->findOrFail($id)), __('Access revoked.')),
        };
    }

    /** Classify or declassify a record, from its page. */
    public function classify(Request $request, RecordResource $record): RedirectResponse
    {
        abort_unless($record->institution_id === $request->user()->institution_id, 404);
        if ($request->boolean('declassify')) {
            $to = $request->filled('to_level_id') ? SecurityLevel::where('institution_id', $record->institution_id)->findOrFail($request->input('to_level_id')) : null;

            return $this->attempt(fn () => $this->clearances->declassify($request->user(), $record, $to, $request->input('reason')), __('Declassified.'));
        }
        $data = $request->validate(['level_id' => ['required', 'uuid'], 'review_on' => ['nullable', 'date_format:Y-m-d'], 'declassify_on' => ['nullable', 'date_format:Y-m-d'],
            'declassify_to_level_id' => ['nullable', 'uuid'], 'reason' => ['nullable', 'string', 'max:5000'], 'handling_instructions' => ['nullable', 'string', 'max:5000'],
            'inherit_to_children' => ['nullable', 'boolean'], 'justification' => ['nullable', 'string', 'max:5000'], 'compartments' => ['array'], 'compartments.*' => ['uuid']]);
        $level = SecurityLevel::where('institution_id', $record->institution_id)->findOrFail($data['level_id']);

        return $this->attempt(fn () => $this->clearances->classify($request->user(), $record, $level, $data, $data['compartments'] ?? []), __('Classified.'));
    }

    /** Ask for access to a classified record, named by its persistent IRI. */
    public function requestForm(Request $request): View
    {
        return view('clearance.request', ['types' => Concept::choices(Vocab::BASE.'clearance-request-type/')->get(), 'priorities' => Concept::choices(Vocab::BASE.'clearance-request-priority/')->get(),
            'mine' => ClearanceRequest::where('user_id', $request->user()->id)->with('record', 'status', 'requestType')->latest()->get()]);
    }

    public function submitRequest(Request $request): RedirectResponse
    {
        $data = $request->validate(['iri' => ['required', 'string', 'max:500'], 'request_type' => ['required', 'string', 'max:32'], 'priority' => ['nullable', 'string', 'max:32'],
            'justification' => ['required', 'string', 'max:5000']]);
        $record = RecordResource::where(['institution_id' => $request->user()->institution_id, 'iri' => trim($data['iri'])])->whereNull('deleted_at')->first();
        if (! $record) {
            return back()->withInput()->withErrors(['clearance' => __('No record has that IRI.')]);
        }

        return $this->attempt(fn () => $this->clearances->request($request->user(), $record, $data['request_type'], $data['justification'], $data['priority'] ?? 'normal'), __('Your request is sent.'));
    }

    private function attempt(callable $do, string $done): RedirectResponse
    {
        try {
            $do();
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['clearance' => $e->getMessage()]);
        } catch (\UnhandledMatchError) {
            abort(404);
        }

        return back()->with('status', $done);
    }
}
