<?php

// OpenRiC Workspace - the jurisdiction packs: transfers to the National Archives and Records Service of South Africa,
// permits to export heritage objects (NHRA s.32), and the National Archives of Zimbabwe's closures, protections,
// research permits, records schedules and transfers (roadmap phases 18d and 18e, ADR 0016). Zimbabwe's Cyber and Data
// Protection Act is a jurisdiction of the privacy registers. After Heratio's ahg-narssa and NazController and PSIS's
// ahgSAHRAPlugin.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Files\FileStore;
use App\Jurisdiction\ExportPermits;
use App\Jurisdiction\NarssaTransfers;
use App\Jurisdiction\Naz;
use App\Models\Concept;
use App\Models\DisposalClass;
use App\Models\ExportPermit;
use App\Models\ExportPermitDocument;
use App\Models\NarssaTransfer;
use App\Models\NazRecordsSchedule;
use App\Models\NazResearchPermit;
use App\Models\NazRestriction;
use App\Models\NazTransfer;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\Researcher;
use App\Support\Vocab;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class JurisdictionController extends Controller implements HasMiddleware
{
    /** Each pack's pages answer only where the institution has switched the pack on in its settings. */
    public static function middleware(): array
    {
        $pack = fn (string $notation) => function (Request $request, Closure $next) use ($notation) {
            abort_unless($request->user()?->institution?->usesPack($notation), 404);

            return $next($request);
        };

        return [new Middleware($pack('narssa'), only: ['narssa', 'narssaBuild', 'narssaStep', 'narssaPackage']),
            new Middleware($pack('export-permits'), only: ['permits', 'permit', 'savePermit', 'permitStep', 'permitDocument']),
            new Middleware($pack('naz'), only: ['naz', 'nazStep'])];
    }

    public function __construct(private NarssaTransfers $narssa, private ExportPermits $permits, private Naz $naz) {}

    // NARSSA transfers.

    public function narssa(Request $request): View
    {
        return view('jurisdiction.narssa', ['transfers' => NarssaTransfer::where('institution_id', $request->user()->institution_id)->with('status', 'initiatedBy', 'items')->latest()->get()]);
    }

    public function narssaBuild(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($request->boolean('from_disposals')) {
            return $this->attempt(fn () => $this->narssa->fromDisposals($user), __('The transfer is packaged.'), 'jurisdiction.narssa');
        }
        $data = $request->validate(['record_iris' => ['required', 'string', 'max:50000'], 'title' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000']]);
        $iris = array_filter(array_map('trim', preg_split('/\R/', $data['record_iris'])));
        $records = RecordResource::where('institution_id', $user->institution_id)->whereIn('iri', $iris)->get();
        if ($records->count() !== count(array_unique($iris))) {
            return back()->withInput()->withErrors(['jurisdiction' => __('Name every record to transfer by its persistent IRI, one a line.')]);
        }

        return $this->attempt(fn () => $this->narssa->build($user, $records->all(), $data['title'] ?? null, $data['description'] ?? null), __('The transfer is packaged.'), 'jurisdiction.narssa');
    }

    public function narssaStep(Request $request, NarssaTransfer $transfer, string $step): RedirectResponse
    {
        $this->own($request, $transfer->institution_id);

        return $this->attempt(fn () => $this->narssa->step($request->user(), $transfer, $step, $request->input('receipt_reference'), $request->input('notes')), __('Saved.'));
    }

    public function narssaPackage(Request $request, NarssaTransfer $transfer): BinaryFileResponse
    {
        $this->own($request, $transfer->institution_id);

        return response()->download(app(FileStore::class)->path($transfer->package_path), $transfer->reference.'.tar.gz', ['Content-Type' => 'application/gzip', 'X-Content-SHA256' => $transfer->package_sha256]);
    }

    // Export permits (NHRA s.32).

    public function permits(Request $request): View
    {
        $user = $request->user();

        return view('jurisdiction.permits', ['permits' => ExportPermit::where('institution_id', $user->institution_id)->with('status', 'applicant', 'records.record')->latest()->get(), 'user' => $user]);
    }

    public function permit(Request $request, ExportPermit $permit): View
    {
        $this->own($request, $permit->institution_id);

        return view('jurisdiction.permit', ['permit' => $permit->load('status', 'applicant', 'approvedBy', 'records.record', 'documents'), 'user' => $request->user()]);
    }

    public function savePermit(Request $request, ?ExportPermit $permit = null): RedirectResponse
    {
        $permit && $this->own($request, $permit->institution_id);
        $data = $request->validate(['purpose' => ['required', 'string', 'max:1000'], 'description' => ['nullable', 'string', 'max:10000'], 'issuing_authority' => ['nullable', 'string', 'max:255'],
            'destination_institution' => ['nullable', 'string', 'max:255'], 'destination_country' => ['nullable', 'string', 'max:2'], 'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'], 'fee_amount' => ['nullable', 'numeric', 'min:0'], 'record_iris' => ['required', 'string', 'max:20000']]);
        $saved = null;
        $back = $this->attempt(function () use ($request, $data, $permit, &$saved) {
            $saved = $this->permits->save($request->user(), $data, $permit);
        }, __('Saved.'));

        return $saved ? redirect()->route('jurisdiction.permit', $saved)->with('status', __('Saved.')) : $back;
    }

    public function permitStep(Request $request, ExportPermit $permit, string $step): RedirectResponse
    {
        $this->own($request, $permit->institution_id);
        if ($step === 'document') {
            $request->validate(['file' => ['required', 'file', 'max:51200'], 'kind' => ['nullable', 'string', 'max:100']]);

            return $this->attempt(fn () => $this->permits->addDocument($request->user(), $permit, $request->file('file'), $request->input('kind')), __('Added.'));
        }

        return $this->attempt(fn () => $this->permits->step($request->user(), $permit, $step, $request->only(['notes', 'authority_reference', 'permit_number', 'conditions', 'start_date', 'end_date'])), __('Saved.'));
    }

    public function permitDocument(Request $request, ExportPermitDocument $document): BinaryFileResponse
    {
        $this->own($request, $document->permit->institution_id);

        return response()->download(app(FileStore::class)->path($document->storage_path), $document->original_name);
    }

    // The National Archives of Zimbabwe.

    public function naz(Request $request): View
    {
        $institution = $request->user()->institution_id;
        $choices = fn (string $v) => Concept::choices(Vocab::BASE.$v.'/')->get();

        return view('jurisdiction.naz', [
            'restrictions' => NazRestriction::whereHas('record', fn ($q) => $q->where('institution_id', $institution))->with('record', 'embargo', 'closureType', 'protectionType')->latest()->get(),
            'permits' => NazResearchPermit::where('institution_id', $institution)->with('researcher', 'permitType', 'status')->latest()->get(),
            'schedules' => NazRecordsSchedule::where('institution_id', $institution)->with('disposalAction', 'status', 'disposalClass')->orderBy('schedule_number')->get(),
            'transfers' => NazTransfer::where('institution_id', $institution)->with('transferType', 'status', 'schedule')->latest()->get(),
            'researchers' => Researcher::where('institution_id', $institution)->orderBy('last_name')->get(), 'repositories' => Repository::where('institution_id', $institution)->whereNull('deleted_at')->get(),
            'classes' => DisposalClass::whereHas('schedule', fn ($q) => $q->where('institution_id', $institution))->orderBy('reference')->get(),
            'closureTypes' => $choices('naz-closure-type'), 'protectionTypes' => $choices('naz-protection-type'), 'permitTypes' => $choices('naz-permit-type'), 'scheduleStatuses' => $choices('naz-schedule-status'),
            'recordsClasses' => $choices('naz-records-class'), 'accessRestrictions' => $choices('naz-access-restriction'), 'actions' => $choices('disposal-action-type'),
            'transferTypes' => $choices('naz-transfer-type'), 'transferStatuses' => $choices('naz-transfer-status')]);
    }

    public function nazStep(Request $request, string $step, ?string $id = null): RedirectResponse
    {
        $user = $request->user();
        $institution = $user->institution_id;
        if ($step === 'restrict') {
            $data = $request->validate(['iri' => ['required', 'string', 'max:500'], 'kind' => ['required', 'in:closure,protection'], 'type' => ['required', 'string', 'max:32'], 'years' => ['nullable', 'integer', 'min:1', 'max:200'],
                'start_date' => ['nullable', 'date_format:Y-m-d'], 'end_date' => ['nullable', 'date_format:Y-m-d'], 'review_date' => ['nullable', 'date_format:Y-m-d'], 'authority_reference' => ['nullable', 'string', 'max:255'],
                'reason' => ['nullable', 'string', 'max:5000'], 'inside' => ['nullable', 'boolean']]);
            $record = RecordResource::where(['institution_id' => $institution, 'iri' => trim($data['iri'])])->whereNull('deleted_at')->first();
            if (! $record) {
                return back()->withInput()->withErrors(['jurisdiction' => __('No record has that IRI.')]);
            }

            return $this->attempt(fn () => $this->naz->restrict($user, $record, $data['kind'], $data['type'], $data), __('Restricted.'));
        }

        return match ($step) {
            'release' => $this->attempt(fn () => $this->naz->release($user, NazRestriction::whereHas('record', fn ($q) => $q->where('institution_id', $institution))->findOrFail($id), $request->input('reason')), __('Released.')),
            'permit' => $this->attempt(fn () => $this->naz->savePermit($user, $request->validate(['researcher_id' => ['required', 'uuid'], 'permit_type' => ['nullable', 'string', 'max:32'],
                'research_topic' => ['required', 'string', 'max:500'], 'research_purpose' => ['nullable', 'string', 'max:5000'], 'start_date' => ['required', 'date_format:Y-m-d'], 'end_date' => ['required', 'date_format:Y-m-d'],
                'fee_amount' => ['nullable', 'numeric', 'min:0'], 'fee_currency' => ['nullable', 'string', 'size:3'], 'fee_paid' => ['nullable', 'boolean'], 'fee_receipt' => ['nullable', 'string', 'max:100'],
                'payment_date' => ['nullable', 'date_format:Y-m-d'], 'repository_ids' => ['array'], 'repository_ids.*' => ['uuid'], 'restrictions' => ['nullable', 'string', 'max:5000']]),
                $id ? NazResearchPermit::where('institution_id', $institution)->findOrFail($id) : null), __('Saved.')),
            'approve', 'reject', 'revoke' => $this->attempt(fn () => $this->naz->decidePermit($user, NazResearchPermit::where('institution_id', $institution)->findOrFail($id), $step, $request->input('reason')), __('Saved.')),
            'schedule' => $this->attempt(fn () => $this->naz->saveSchedule($user, $request->all(), $id ? NazRecordsSchedule::where('institution_id', $institution)->findOrFail($id) : null), __('Saved.')),
            'transfer' => $this->attempt(fn () => $this->naz->saveTransfer($user, $request->all(), $id ? NazTransfer::where('institution_id', $institution)->findOrFail($id) : null), __('Saved.')),
        };
    }

    private function own(Request $request, string $institutionId): void
    {
        abort_unless($institutionId === $request->user()->institution_id, 404);
    }

    private function attempt(callable $do, string $done, ?string $route = null): RedirectResponse
    {
        try {
            $do();
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['jurisdiction' => $e->getMessage()]);
        } catch (\UnhandledMatchError) {
            abort(404);
        }

        return ($route ? redirect()->route($route) : back())->with('status', $done);
    }
}
