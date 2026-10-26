<?php

// OpenRiC Workspace - the rights report and rights for what is on the clipboard (WS-007, roadmap phase 2), after
// Heratio's RightsAdminController (ahg-extended-rights: report and batch, the latter cloned there from AtoM's
// extendedRights/batchAction); the objects chosen are the clipboard's archival descriptions.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Clipboard\ClipboardService;
use App\Models\RecordResource;
use App\Rights\CommunityLabelService;
use App\Rights\RightsReport;
use App\Rights\RightsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RightsAdminController extends Controller
{
    public function __construct(private RightsReport $report, private ClipboardService $clipboard) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()->mayAnywhere('read', 'description'), 403);

        return view('rights.index', ['report' => $this->report->build($request->user()->institution_id),
            'onClipboard' => $this->clipboardRecords($request)->count(), 'mayAssign' => $request->user()->mayAnywhere('update', 'description')]);
    }

    public function csv(Request $request, string $list): StreamedResponse
    {
        abort_unless($request->user()->mayAnywhere('read', 'description') && in_array($list, RightsReport::LISTS, true), 404);
        $rows = $this->report->csv($request->user()->institution_id, $list);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, "rights-$list.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function batch(Request $request): View
    {
        abort_unless($request->user()->mayAnywhere('update', 'description'), 403);

        return view('rights.batch', ['records' => $this->clipboardRecords($request)]);
    }

    public function batchStore(Request $request): RedirectResponse
    {
        abort_unless($request->user()->mayAnywhere('update', 'description'), 403);
        $data = $request->validate([
            'what' => ['required', Rule::in(['right', 'label'])],
            'with_instantiations' => ['nullable', 'boolean'],
            'basis' => ['required_if:what,right', 'nullable', 'string', 'max:64'], 'statement' => ['nullable', 'string', 'max:64'], 'licence' => ['nullable', 'string', 'max:64'],
            'holder' => ['nullable', 'uuid'], 'starts_on' => ['nullable', 'string', 'max:10'], 'ends_on' => ['nullable', 'string', 'max:10'],
            'label' => ['required_if:what,label', 'nullable', 'string', 'max:64'], 'applied_by' => ['nullable', 'string', 'max:64'], 'community' => ['nullable', 'uuid'],
        ]);
        $records = $this->clipboardRecords($request);
        if ($records->isEmpty()) {
            return back()->withErrors(['batch' => __('There are no archival descriptions on the clipboard.')]);
        }
        try {
            $done = $data['what'] === 'right'
                ? app(RightsService::class)->assignToMany($records, $data, $request->boolean('with_instantiations'), $request->user())
                : app(CommunityLabelService::class)->applyToMany($records, $data + ['applied_by' => 'institution'], $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['batch' => $e->getMessage()]);
        }

        return redirect()->route('rights.batch')->with('status', __(':added added, :skipped skipped (not yours to change, or there already).', $done));
    }

    /** The archival descriptions on the clipboard that the account may read. */
    private function clipboardRecords(Request $request)
    {
        $ids = $this->clipboard->items($request->user())['records'] ?? [];

        return RecordResource::where('institution_id', $request->user()->institution_id)->whereNull('deleted_at')->whereIn('id', $ids)->get()
            ->filter(fn ($record) => $request->user()->may('read', 'description', $record))->values();
    }
}
