<?php

// OpenRiC Workspace - privacy in the catalogue (roadmap phase 18b, ADR 0016), after Heratio's PrivacyController PII
// scan and file screening actions (ahg-privacy) and its field redaction panel: scan a record for personal
// information, redact its fields for the public, release or withhold its files where screening is required, and say
// where screening is required. The Information Officer's work: Manage privacy.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Audit\AuditLog;
use App\Models\PiiScanReport;
use App\Models\PrivacyJurisdiction;
use App\Models\RecordRedaction;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\ScreeningScope;
use App\Privacy\Redaction;
use App\Privacy\Screening;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;

class PrivacyScreeningController extends Controller
{
    public function __construct(private Screening $screening, private Redaction $redaction) {}

    /** Where screening is required, and the latest scan of each record that found something and waits for a decision. */
    public function index(Request $request): View
    {
        $institution = $this->authorise($request);
        $latest = PiiScanReport::where('institution_id', $institution)->with('record', 'status', 'coverage', 'jurisdiction')->orderByDesc('started_at')->orderByDesc('id')->get()
            ->unique('record_resource_id')->values();

        return view('privacy.screening', [
            'scopes' => ScreeningScope::where('institution_id', $institution)->with('repository', 'record')->orderBy('created_at')->get(),
            'waiting' => $latest->filter(fn ($r) => $r->status->notation === 'pending' && $r->hits_total > 0),
            'withheld' => $latest->filter(fn ($r) => $r->status->notation === 'withheld'),
            'repositories' => Repository::where('institution_id', $institution)->whereNull('deleted_at')->get()->sortBy(fn ($r) => $r->described('authorised-name', Vocab::languages())),
            'counts' => $latest->countBy(fn ($r) => $r->status->notation),
        ]);
    }

    public function requireScope(Request $request): RedirectResponse
    {
        $this->authorise($request);
        $data = $request->validate(['repository_id' => ['nullable', 'uuid'], 'record' => ['nullable', 'string', 'max:2048'], 'note' => ['nullable', 'string', 'max:2000']]);
        // A record is named by its persistent IRI, or its id.
        $record = trim((string) ($data['record'] ?? ''));
        if ($record !== '') {
            $data['record_resource_id'] = RecordResource::where('institution_id', $request->user()->institution_id)->whereNull('deleted_at')
                ->where(fn ($q) => $q->where('iri', $record)->when(preg_match('/^[0-9a-f-]{36}$/', $record), fn ($q) => $q->orWhere('id', $record)))->value('id');
            if ($data['record_resource_id'] === null) {
                return back()->withInput()->withErrors(['screening' => __('No record of the institution has that IRI.')]);
            }
        }
        try {
            $this->screening->require($data, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['screening' => $e->getMessage()]);
        }

        return back()->with('status', __('Saved.'));
    }

    public function removeScope(Request $request, ScreeningScope $scope): RedirectResponse
    {
        abort_unless($scope->institution_id === $this->authorise($request), 404);
        DB::transaction(fn () => AuditLog::actingAs($request->user(), fn () => $scope->delete()));

        return back()->with('status', __('Screening is no longer required there.'));
    }

    public function scan(Request $request, RecordResource $record): RedirectResponse
    {
        $this->own($request, $record);
        $data = $request->validate(['jurisdiction' => ['nullable', 'string', 'max:32']]);
        try {
            $report = $this->screening->scan($record, $data['jurisdiction'] ?? null, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['privacy' => $e->getMessage()]);
        }

        return redirect(route('records.show', $record).'#privacy')->with('status', trans_choice('{0} Scanned: nothing found.|{1} Scanned: :count finding.|[2,*] Scanned: :count findings.', $report->hits_total, ['count' => $report->hits_total]));
    }

    public function redact(Request $request, RecordResource $record): RedirectResponse
    {
        $this->own($request, $record);
        $data = $request->validate(['field' => ['required', 'string', 'max:100'], 'type' => ['required', 'string', 'max:64'], 'pattern' => ['nullable', 'string', 'max:64'],
            'reason' => ['required', 'string', 'max:2000'], 'legal_basis' => ['nullable', 'string', 'max:500'], 'is_sensitive' => ['nullable', 'boolean']]);
        try {
            DB::transaction(fn () => AuditLog::actingAs($request->user(), fn () => $this->redaction->set($record, $data, $request->user())));
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['redaction' => $e->getMessage()]);
        }

        return redirect(route('records.show', $record).'#privacy')->with('status', __('The field is redacted for the public.'));
    }

    public function unredact(Request $request, RecordResource $record, RecordRedaction $redaction): RedirectResponse
    {
        $this->own($request, $record);
        abort_unless($redaction->record_resource_id === $record->id, 404);
        DB::transaction(fn () => AuditLog::actingAs($request->user(), fn () => $this->redaction->remove($redaction)));

        return redirect(route('records.show', $record).'#privacy')->with('status', __('The field is no longer redacted.'));
    }

    public function decide(Request $request, RecordResource $record): RedirectResponse
    {
        $this->own($request, $record);
        $data = $request->validate(['decision' => ['required', 'in:release,redacted,withhold'], 'ground' => ['nullable', 'string', 'max:32'], 'note' => ['nullable', 'string', 'max:5000']]);
        try {
            $this->screening->decide($record, $data['decision'], $data['ground'] ?? null, $data['note'] ?? null, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['screening' => $e->getMessage()]);
        }

        return redirect(route('records.show', $record).'#privacy')->with('status', __('Saved.'));
    }

    /** @return string the institution's id */
    private function authorise(Request $request): string
    {
        abort_unless($request->user()->hasPermission(Vocab::permission('manage-privacy')), 403);

        return $request->user()->institution_id;
    }

    private function own(Request $request, RecordResource $record): void
    {
        abort_unless($record->institution_id === $this->authorise($request) && $record->deleted_at === null, 404);
    }

    /** The jurisdictions a scan may look for, and a decision be grounded on. */
    public static function jurisdictions()
    {
        return PrivacyJurisdiction::where('is_active', true)->orderBy('position')->get()->reject(fn ($j) => $j->code === 'cdpa' && ! auth()->user()?->institution?->usesPack('cdpa'))->values();
    }
}
