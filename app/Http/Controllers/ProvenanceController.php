<?php

// OpenRiC Workspace - the provenance and custody history of a unit of description: its overview, the chain of steps,
// their order, the supporting documents, the timeline and the CSV export (Heratio's ahg-information-object-manage
// ProvenanceController; PSIS's ahgProvenancePlugin before it; roadmap phase 9e).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Reading it needs read on the description, as Heratio's per-record gate; changing it needs update.

namespace App\Http\Controllers;

use App\Description\ProvenanceService;
use App\Files\FileResponse;
use App\Files\FileStore;
use App\Models\Agent;
use App\Models\Concept;
use App\Models\ProvenanceDocument;
use App\Models\ProvenanceOverview;
use App\Models\RecordResource;
use App\Rights\CommunityProtocols;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProvenanceController extends Controller
{
    public function __construct(private ProvenanceService $provenance) {}

    public function index(Request $request, RecordResource $record): View
    {
        $this->own($request, $record, 'read');
        $record->load('names.nameType', 'provenanceOverview', 'provenanceDocuments.documentType.labels', 'provenanceDocuments.entry', 'provenanceDocuments.uploadedBy');
        $steps = $record->provenanceEntries()->with('owner.names', 'ownerType.labels', 'startQualifier.labels', 'endQualifier.labels', 'transferType.labels',
            'certainty.labels', 'evidenceType.labels')->get();
        $choices = fn (string $vocabulary) => Concept::choices(Vocab::BASE.$vocabulary.'/')->with('labels')->get();

        return view('records.provenance', ['record' => $record, 'steps' => $steps, 'overview' => $record->provenanceOverview,
            'canUpdate' => $request->user()->may('update', 'description', $record),
            'choices' => [...collect(ProvenanceOverview::TERMS)->map($choices)->all(), ...collect(ProvenanceService::ENTRY_TERMS)->map($choices)->all(),
                'document_type' => $choices('provenance-document-type')],
            // ponytail: every authority record in one list, as the rights holder; a search field when institutions hold more than a few thousand.
            'agents' => Agent::where('institution_id', $record->institution_id)->whereNull('deleted_at')->with('names')->get()
                ->sortBy(fn (Agent $a) => mb_strtolower((string) $a->described('agent-authorised-name', ['en'])))->values()]);
    }

    public function overview(Request $request, RecordResource $record): RedirectResponse
    {
        $this->own($request, $record, 'update');
        $data = $request->validate([...array_fill_keys(array_keys(ProvenanceOverview::TERMS), ['nullable', 'string', 'max:64']),
            'acquisition_date' => ['nullable', 'string', 'max:10'], 'acquisition_date_text' => ['nullable', 'string', 'max:255'], 'acquisition_price' => ['nullable', 'string', 'max:30'],
            'acquisition_currency' => ['nullable', 'string', 'max:3'], 'nazi_era_clear' => ['nullable', 'in:0,1'],
            ...array_fill_keys(['has_gaps', 'nazi_era_checked', 'is_complete', 'is_public'], ['nullable', 'boolean']),
            ...array_fill_keys(['gap_description', 'research_notes', 'nazi_era_notes', 'cultural_property_notes', 'summary'], ['nullable', 'string', 'max:65535'])]);

        return $this->attempt($record, fn () => $this->provenance->saveOverview($record, $data, $request->user()), __('Provenance overview saved.'), 'overview');
    }

    /** Add a step, or change one. */
    public function saveEntry(Request $request, RecordResource $record, ?string $entry = null): RedirectResponse
    {
        $this->own($request, $record, 'update');
        $existing = $entry ? $record->provenanceEntries()->findOrFail($entry) : null;
        $data = $request->validate(['owner' => ['nullable', 'uuid'], 'owner_name' => ['nullable', 'string', 'max:500'],
            ...array_fill_keys(array_keys(ProvenanceService::ENTRY_TERMS), ['nullable', 'string', 'max:64']),
            'owner_location' => ['nullable', 'string', 'max:255'], 'owner_location_tgn' => ['nullable', 'string', 'max:100'],
            'start_date' => ['nullable', 'string', 'max:64'], 'end_date' => ['nullable', 'string', 'max:64'], 'sale_price' => ['nullable', 'string', 'max:30'],
            'sale_currency' => ['nullable', 'string', 'max:3'], 'auction_house' => ['nullable', 'string', 'max:255'], 'auction_lot' => ['nullable', 'string', 'max:50'],
            'is_gap' => ['nullable', 'boolean'],
            ...array_fill_keys(['transfer_details', 'sources', 'evidence_description', 'notes', 'gap_explanation'], ['nullable', 'string', 'max:65535'])]);

        return $this->attempt($record, fn () => $this->provenance->saveEntry($record, $existing, $data, $request->user()),
            __($existing ? 'Provenance entry saved.' : 'Provenance entry added.'), 'chain');
    }

    public function move(Request $request, RecordResource $record, string $entry): RedirectResponse
    {
        $this->own($request, $record, 'update');
        $step = $record->provenanceEntries()->findOrFail($entry);
        $data = $request->validate(['direction' => ['required', 'in:earlier,later']]);
        $this->provenance->move($step, $data['direction'] === 'earlier', $request->user());

        return redirect(route('records.provenance', $record).'#chain');
    }

    public function removeEntry(Request $request, RecordResource $record, string $entry): RedirectResponse
    {
        $this->own($request, $record, 'update');
        $this->provenance->removeEntry($record->provenanceEntries()->findOrFail($entry), $request->user());

        return redirect(route('records.provenance', $record).'#chain')->with('status', __('Provenance entry removed.'));
    }

    public function addDocument(Request $request, RecordResource $record): RedirectResponse
    {
        $this->own($request, $record, 'update');
        $data = $request->validate(['file' => ['nullable', 'file', 'max:'.(config('workspace.max_upload_megabytes') * 1024)], 'document_type' => ['nullable', 'string', 'max:64'],
            'title' => ['nullable', 'string', 'max:500'], 'description' => ['nullable', 'string', 'max:65535'], 'document_date' => ['nullable', 'string', 'max:10'],
            'document_date_text' => ['nullable', 'string', 'max:255'], 'external_url' => ['nullable', 'url:http,https', 'max:1000'], 'archive_reference' => ['nullable', 'string', 'max:500'],
            'is_public' => ['nullable', 'boolean'], 'entry' => ['nullable', 'uuid']]);
        $upload = $request->file('file');

        return $this->attempt($record, fn () => $this->provenance->addDocument($record, $data, $upload?->getRealPath(), $upload?->getClientOriginalName(), $request->user()),
            __('Supporting document added.'), 'documents');
    }

    public function document(Request $request, RecordResource $record, string $document): BinaryFileResponse
    {
        $this->own($request, $record, 'read');
        $file = ProvenanceDocument::where('record_resource_id', $record->id)->whereNotNull('storage_path')->findOrFail($document);
        $path = app(FileStore::class)->path($file->storage_path);
        abort_unless(is_file($path), 404);

        return FileResponse::make($file, $path, $request->boolean('download'));
    }

    public function removeDocument(Request $request, RecordResource $record, string $document): RedirectResponse
    {
        $this->own($request, $record, 'update');
        $this->provenance->removeDocument(ProvenanceDocument::where('record_resource_id', $record->id)->findOrFail($document), $request->user());

        return redirect(route('records.provenance', $record).'#documents')->with('status', __('Removed.'));
    }

    /** Heratio's provenance CSV. */
    public function csv(Request $request, RecordResource $record): StreamedResponse
    {
        $this->own($request, $record, 'read');
        $rows = $this->provenance->csv($record);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, ($record->identifier ?: $record->id).'_provenance.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function attempt(RecordResource $record, callable $change, string $status, string $section): RedirectResponse
    {
        try {
            $change();
        } catch (InvalidArgumentException $e) {
            return redirect(route('records.provenance', $record).'#'.$section)->withInput()->withErrors(['provenance' => ucfirst($e->getMessage()).'.']);
        }

        return redirect(route('records.provenance', $record).'#'.$section)->with('status', $status);
    }

    /** As the record's own pages: another institution's or a deleted one does not exist here; without the permission, 403. */
    private function own(Request $request, RecordResource $record, string $action): void
    {
        abort_unless($record->institution_id === $request->user()->institution_id && $record->deleted_at === null, 404);
        abort_unless($request->user()->may($action, 'description', $record), 403);
        abort_unless(app(CommunityProtocols::class)->allows($request->user(), $record, 'description'), 404);
    }
}
