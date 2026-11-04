<?php

// OpenRiC Workspace - OAIS information packages and Archivematica (roadmap phase 15d, ADR 0022), after Heratio's
// ahg-preservation package pages and ahg-archivematica transfer panel and settings: a unit's packages, built,
// validated, carried on from SIP to AIP to DIP, downloaded and removed by who may manage the institution; a unit sent to
// Archivematica as a transfer; and the installation's Archivematica settings, which its operators set.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Models\ArchivematicaJob;
use App\Models\ArchivematicaLink;
use App\Models\ArchivematicaSettings;
use App\Models\Package;
use App\Models\RecordResource;
use App\Operations\Health;
use App\Preservation\Archivematica\Transfers;
use App\Preservation\Packages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PackageController extends Controller
{
    public function __construct(private Packages $packages) {}

    /** The unit's packages and transfers, and the forms to build one and send it. */
    public function index(Request $request, RecordResource $record): View
    {
        $this->own($request, $record);

        return view('packages.index', ['record' => $record, 'packages' => Package::where('record_resource_id', $record->id)->with('type.labels', 'status.labels', 'format.labels', 'parent')
            ->orderByDesc('created_at')->get(), 'jobs' => ArchivematicaJob::where('record_resource_id', $record->id)->with('direction.labels', 'status.labels')->orderByDesc('created_at')->limit(20)->get(),
            'archivematica' => ArchivematicaSettings::current()]);
    }

    public function store(Request $request, RecordResource $record): RedirectResponse
    {
        $this->own($request, $record);
        $data = $request->validate(['type' => ['required', 'in:sip,aip,dip'], 'format' => ['required', 'in:zip,tar'], 'algorithm' => ['required', 'in:sha256,sha512'],
            'name' => ['nullable', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:4000'], 'originator' => ['nullable', 'string', 'max:255'],
            'submission_agreement' => ['nullable', 'string', 'max:255'], 'retention_period' => ['nullable', 'string', 'max:255']]);
        try {
            $package = $this->packages->build($record, $data['type'], $request->user(), [...$data, 'include_descendants' => $request->boolean('include_descendants')]);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['packages' => $e->getMessage()]);
        }

        return redirect()->route('packages.show', $package)->with('status', __('The package is built.'));
    }

    public function show(Request $request, Package $package): View
    {
        abort_unless($package->institution_id === $request->user()->institution_id, 404);

        return view('packages.show', ['package' => $package->load('type.labels', 'status.labels', 'format.labels', 'record', 'parent.type', 'children.type', 'createdBy', 'files.role.labels',
            'events.type.labels', 'events.outcome.labels')]);
    }

    /** Validate it again, carry it on (SIP to AIP, AIP to DIP), or remove it. */
    public function act(Request $request, Package $package, string $action): RedirectResponse
    {
        abort_unless($package->institution_id === $request->user()->institution_id, 404);
        try {
            return match ($action) {
                'validate' => back()->with('status', ($errors = $this->packages->validate($package, $request->user())) === [] ? __('The package is a valid BagIt bag.')
                    : __('The package did not validate: :errors', ['errors' => implode('; ', array_slice($errors, 0, 5))])),
                'derive' => redirect()->route('packages.show', $this->packages->derive($package, $request->user()))->with('status', __('The package is built.')),
                'remove' => tap(redirect()->route('packages.index', $package->record_resource_id), fn () => $this->packages->remove($package, $request->user()))->with('status', __('The package was removed.')),
            };
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['packages' => $e->getMessage()]);
        }
    }

    public function download(Request $request, Package $package): BinaryFileResponse
    {
        abort_unless($package->institution_id === $request->user()->institution_id, 404);
        try {
            $path = $this->packages->download($package, $request->user());
        } catch (InvalidArgumentException $e) {
            abort(404, $e->getMessage());
        }

        return response()->download($path, $package->name.'.'.$package->format->notation, ['X-Content-SHA256' => $package->package_checksum]);
    }

    /** Send the unit to Archivematica as a transfer. */
    public function transfer(Request $request, RecordResource $record): RedirectResponse
    {
        $this->own($request, $record);
        try {
            $job = app(Transfers::class)->send($record, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['packages' => $e->getMessage()]);
        }

        return back()->with('status', $job->status->notation === 'failed' ? __('Archivematica did not take the transfer: :error', ['error' => $job->error]) : __('The transfer is with Archivematica.'));
    }

    /** The installation's Archivematica, its jobs and the packages it gave. */
    public function archivematica(Request $request): View
    {
        $institution = $request->user()->institution_id;

        return view('packages.archivematica', ['settings' => ArchivematicaSettings::current(), 'operator' => Health::operator($request->user()),
            'jobs' => ArchivematicaJob::where('institution_id', $institution)->with('record', 'direction.labels', 'status.labels')->orderByDesc('created_at')->limit(50)->get(),
            // A package that found no unit belongs to no institution: only the installation's operators see it.
            'links' => ArchivematicaLink::where(fn ($q) => $q->where('institution_id', $institution)->when(Health::operator($request->user()), fn ($q) => $q->orWhereNull('institution_id')))
                ->with('record', 'status.labels')->orderByDesc('created_at')->limit(50)->get(),
            'ping' => session('ping')]);
    }

    public function saveArchivematica(Request $request): RedirectResponse
    {
        abort_unless(Health::operator($request->user()), 403);
        $uuid = ['nullable', 'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i'];
        $data = $request->validate(['dashboard_url' => ['nullable', 'url:http,https', 'max:255'], 'dashboard_username' => ['nullable', 'string', 'max:255'], 'dashboard_api_key' => ['nullable', 'string', 'max:255'],
            'storage_url' => ['nullable', 'url:http,https', 'max:255'], 'storage_username' => ['nullable', 'string', 'max:255'], 'storage_api_key' => ['nullable', 'string', 'max:255'],
            'pipeline_uuid' => $uuid, 'transfer_source_location_uuid' => $uuid, 'transfer_source_path' => ['nullable', 'regex:#^/[A-Za-z0-9_./-]*$#'],
            'staging_path' => ['nullable', 'regex:#^/[A-Za-z0-9_./-]*$#'], 'deposit_path' => ['nullable', 'regex:#^/[A-Za-z0-9_./-]*$#'],
            'transfer_type' => ['required', 'in:standard,zipfile,unzipped bag,zipped bag'], 'dip_match_strategy' => ['required', 'in:identifier'], 'timeout_seconds' => ['required', 'integer', 'between:5,600']]);
        $settings = ArchivematicaSettings::current();
        // A key left empty keeps the one kept: it is never shown again.
        foreach (['dashboard_api_key', 'storage_api_key'] as $key) {
            if (! filled($data[$key] ?? null)) {
                unset($data[$key]);
            }
        }
        $settings->update([...$data, 'fetch_dips' => $request->boolean('fetch_dips')]);

        return back()->with('status', __('Saved.'));
    }

    /** Ask the Dashboard and Storage Service whether they answer. */
    public function ping(Request $request): RedirectResponse
    {
        abort_unless(Health::operator($request->user()), 403);

        return back()->with('ping', app(Transfers::class)->ping());
    }

    private function own(Request $request, RecordResource $record): void
    {
        abort_unless($record->institution_id === $request->user()->institution_id && $record->deleted_at === null, 404);
    }
}
