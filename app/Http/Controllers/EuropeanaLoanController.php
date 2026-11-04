<?php

// OpenRiC Workspace - the Europeana export and loans between union members (roadmap phase 16d), after Heratio's
// ahg-federation EuropeanaController and LoanRequestController: what the institution says of itself to Europeana, its
// exports and their bundles; and its loans, drawn up, moved along their transitions, and their figures.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Federation\Europeana;
use App\Federation\Loans;
use App\Files\FileStore;
use App\Models\Concept;
use App\Models\EuropeanaExport;
use App\Models\FederationLoanRequest;
use App\Models\FederationMember;
use App\Models\FederationShareSetting;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EuropeanaLoanController extends Controller
{
    public function europeana(Request $request): View
    {
        $institution = $request->user()->institution;

        return view('federation.europeana', ['share' => FederationShareSetting::for($institution->id),
            'runs' => DB::table('europeana_exports')->where('institution_id', $institution->id)->orderByDesc('started_at')->limit(30)->get(), 'institution' => $institution]);
    }

    public function saveEuropeana(Request $request): RedirectResponse
    {
        $data = $request->validate(['europeana_data_provider' => ['nullable', 'string', 'max:255'], 'europeana_country' => ['nullable', 'string', 'max:100'],
            'europeana_language' => ['nullable', 'regex:/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/'], 'europeana_default_rights' => ['nullable', 'regex:#^https?://(rightsstatements\.org|creativecommons\.org)/#']]);
        FederationShareSetting::for($request->user()->institution_id)->fill($data)->save();

        return back()->with('status', __('Saved.'));
    }

    public function runEuropeana(Request $request): RedirectResponse
    {
        $run = app(Europeana::class)->run($request->user()->institution, $request->input('since') ?: null, $request->user());

        return back()->with('status', $run->error ? __('The export failed: :error', ['error' => $run->error]) : trans_choice(':count record exported.|:count records exported.', $run->record_count, ['count' => $run->record_count]));
    }

    public function downloadEuropeana(Request $request, EuropeanaExport $export): BinaryFileResponse
    {
        abort_unless($export->institution_id === $request->user()->institution_id && $export->storage_path, 404);
        $row = $export;

        return response()->download(app(FileStore::class)->path($row->storage_path), 'europeana-'.substr($row->id, 0, 8).'.zip', ['X-Content-SHA256' => $row->bundle_checksum]);
    }

    public function loans(Request $request): View
    {
        $institution = $request->user()->institution;
        $status = (string) $request->query('status');

        return view('federation.loans', ['loans' => FederationLoanRequest::where('institution_id', $institution->id)->with('status.labels', 'requesting', 'holding', 'record')
            ->when($status !== '', fn ($q) => $q->whereHas('status', fn ($s) => $s->where('notation', $status)))->orderByDesc('created_at')->limit(500)->get(),
            'members' => FederationMember::where('institution_id', $institution->id)->orderByDesc('is_self')->orderBy('name')->get(),
            'statuses' => Concept::choices(Vocab::BASE.'loan-status/')->get(), 'status' => $status, 'report' => app(Loans::class)->report($institution), 'transitions' => Loans::TRANSITIONS]);
    }

    public function storeLoan(Request $request): RedirectResponse
    {
        $data = $request->validate(['requesting_member_id' => ['required', 'uuid'], 'holding_member_id' => ['required', 'uuid'], 'record_resource_id' => ['nullable', 'uuid'],
            'item_ref' => ['nullable', 'string', 'max:1000'], 'item_title' => ['nullable', 'string', 'max:1000'], 'purpose' => ['nullable', 'string', 'max:2000'],
            'needed_from' => ['nullable', 'date'], 'needed_to' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:4000']]);
        try {
            app(Loans::class)->create($request->user()->institution, $data, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['loans' => $e->getMessage()]);
        }

        return back()->with('status', __('The loan is requested.'));
    }

    public function moveLoan(Request $request, FederationLoanRequest $loan, string $to): RedirectResponse
    {
        abort_unless($loan->institution_id === $request->user()->institution_id, 404);
        try {
            app(Loans::class)->transition($loan, $to, $request->input('note'), $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['loans' => $e->getMessage()]);
        }

        return back()->with('status', __('Saved.'));
    }
}
