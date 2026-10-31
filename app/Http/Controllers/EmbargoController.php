<?php

// OpenRiC Workspace - embargoes on units of description (roadmap phase 13a, ADR 0015), after Heratio's RightsController
// and RightsAdminController embargo actions: put a record under embargo (and everything inside it), change it, lift it,
// open it to an account, a role or an IP range, and the list of embargoes with their statistics and the ones ending
// soon, as Heratio's EmbargoReportCommand reports them, and as CSV. Who may change a record may put it under embargo.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Models\Embargo;
use App\Models\EmbargoException;
use App\Models\RecordResource;
use App\Models\Role;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Rights\Embargoes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmbargoController extends Controller
{
    public function __construct(private Embargoes $embargoes) {}

    public function index(Request $request): View|StreamedResponse
    {
        abort_unless($request->user()->mayAnywhere('update', 'description'), 403);
        $institution = $request->user()->institution_id;
        $show = in_array($request->query('show'), ['in-force', 'ending', 'all'], true) ? $request->query('show') : 'in-force';
        $embargoes = match ($show) {
            'ending' => $this->embargoes->ending($institution, 30),
            'all' => Embargo::where('institution_id', $institution)->with('record', 'type', 'reason')->orderByDesc('starts_on')->get(),
            default => Embargo::where('institution_id', $institution)->inForce()->with('record', 'type', 'reason')->orderBy('ends_on')->get(),
        };
        if ($request->query('format') === 'csv') {
            return response()->streamDownload(function () use ($embargoes) {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['record', 'iri', 'type', 'reason', 'starts_on', 'ends_on', 'in_force', 'lifted_at', 'lift_reason']);
                foreach ($embargoes as $e) {
                    fputcsv($out, [EntityKinds::label($e->record), $e->record->iri, $e->type->notation, $e->reason?->notation, $e->starts_on->format('Y-m-d'),
                        $e->ends_on?->format('Y-m-d'), $e->inForce() ? 'yes' : 'no', $e->lifted_at?->toAtomString(), $e->lift_reason]);
                }
                fclose($out);
            }, "embargoes-$show.csv", ['Content-Type' => 'text/csv; charset=utf-8']);
        }

        return view('embargoes.index', ['embargoes' => $embargoes, 'show' => $show, 'statistics' => $this->embargoes->statistics($institution)]);
    }

    public function store(Request $request, RecordResource $record): RedirectResponse
    {
        $this->may($request, $record);
        try {
            $made = $this->embargoes->impose($record, $this->data($request), $request->boolean('inside'), $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['embargo' => $e->getMessage()]);
        }

        return back()->with('status', trans_choice('Embargo imposed.|:count embargoes imposed, on it and everything inside it.', count($made), ['count' => count($made)]));
    }

    public function update(Request $request, RecordResource $record, Embargo $embargo): RedirectResponse
    {
        $this->may($request, $record, $embargo);
        try {
            $this->embargoes->change($embargo, $this->data($request), $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['embargo' => $e->getMessage()]);
        }

        return back()->with('status', __('Saved.'));
    }

    public function lift(Request $request, RecordResource $record, Embargo $embargo): RedirectResponse
    {
        $this->may($request, $record, $embargo);
        $this->embargoes->lift($embargo, $request->validate(['lift_reason' => ['nullable', 'string', 'max:2000']])['lift_reason'] ?? null, $request->user());

        return back()->with('status', __('Embargo lifted.'));
    }

    public function except(Request $request, RecordResource $record, Embargo $embargo): RedirectResponse
    {
        $this->may($request, $record, $embargo);
        $data = $request->validate(['user_id' => ['nullable', 'uuid'], 'role_id' => ['nullable', 'uuid'], 'ip_from' => ['nullable', 'string', 'max:45'], 'ip_to' => ['nullable', 'string', 'max:45'],
            'valid_from' => ['nullable', 'date'], 'valid_until' => ['nullable', 'date'], 'note' => ['nullable', 'string', 'max:2000']]);
        // The account or role is one of the institution's.
        $institution = $request->user()->institution_id;
        if (($data['user_id'] ?? null) && ! User::where(['id' => $data['user_id'], 'institution_id' => $institution])->exists()
            || ($data['role_id'] ?? null) && ! Role::where(['id' => $data['role_id'], 'institution_id' => $institution])->exists()) {
            return back()->withErrors(['exception' => __('Choose an account or role of the institution.')]);
        }
        if (count(array_filter([$data['user_id'] ?? null, $data['role_id'] ?? null, $data['ip_from'] ?? null])) !== 1) {
            return back()->withInput()->withErrors(['exception' => __('Open it to one account, one role or one IP range.')]);
        }
        try {
            $this->embargoes->except($embargo, $data, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['exception' => $e->getMessage()]);
        }

        return back()->with('status', __('Exception added.'));
    }

    public function removeException(Request $request, RecordResource $record, Embargo $embargo, EmbargoException $exception): RedirectResponse
    {
        $this->may($request, $record, $embargo);
        abort_unless($exception->embargo_id === $embargo->id, 404);
        $this->embargoes->removeException($exception, $request->user());

        return back()->with('status', __('Removed.'));
    }

    private function data(Request $request): array
    {
        return $request->validate(['type' => ['required', 'string'], 'reason' => ['nullable', 'string'], 'note' => ['nullable', 'string', 'max:5000'],
            'public_message' => ['nullable', 'string', 'max:2000'], 'starts_on' => ['nullable', 'date_format:Y-m-d'], 'ends_on' => ['nullable', 'date_format:Y-m-d'],
            'notify_days_before' => ['nullable', 'integer', 'min:1', 'max:3650']]);
    }

    private function may(Request $request, RecordResource $record, ?Embargo $embargo = null): void
    {
        abort_unless($record->institution_id === $request->user()->institution_id && $record->deleted_at === null && ($embargo === null || $embargo->record_resource_id === $record->id), 404);
        abort_unless($request->user()->may('update', 'description', $record), 403);
    }
}
