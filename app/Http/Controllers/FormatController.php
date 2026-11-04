<?php

// OpenRiC Workspace - the file formats the institution keeps, as PRONOM identifies them, with each format's risk and the
// action for it (Heratio's ahg-preservation format registry; roadmap phase 15a). A format is one row for the whole
// installation, so only its operators set its risk and action; each institution sees its own files.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Jobs\IdentifyFormat;
use App\Models\Concept;
use App\Models\DigitalFile;
use App\Models\FileFormat;
use App\Operations\Health;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class FormatController extends Controller
{
    public function index(Request $request): View
    {
        $institution = $request->user()->institution_id;
        $counts = DigitalFile::where('institution_id', $institution)->whereNotNull('format_id')->select('format_id', DB::raw('count(*) as n'), DB::raw('sum(size_bytes) as bytes'))->groupBy('format_id')->get()->keyBy('format_id');

        return view('formats.index', ['formats' => FileFormat::whereIn('id', $counts->keys())->with('risk.labels', 'action.labels')->orderBy('name')->get(), 'counts' => $counts,
            'unidentified' => DigitalFile::where('institution_id', $institution)->whereNull('format_id')->count(),
            'risks' => Concept::choices(Vocab::BASE.'format-risk/')->get(), 'actions' => Concept::choices(Vocab::BASE.'format-action/')->get(),
            'operator' => Health::operator($request->user())]);
    }

    public function update(Request $request, FileFormat $format): RedirectResponse
    {
        abort_unless(Health::operator($request->user()), 403);
        $concept = fn (string $vocabulary, ?string $notation) => $notation ? Concept::choices(Vocab::BASE.$vocabulary.'/')->where('notation', $notation)->value('id') : null;
        $format->update(['risk_id' => $concept('format-risk', $request->input('risk')), 'action_id' => $concept('format-action', $request->input('action')),
            'is_preservation_format' => $request->boolean('is_preservation_format'), 'notes' => trim((string) $request->input('notes')) ?: null]);

        return back()->with('status', __('Saved.'));
    }

    /** Identify, in the background, the institution's files whose format is not yet known. */
    public function identify(Request $request): RedirectResponse
    {
        $ids = DigitalFile::where('institution_id', $request->user()->institution_id)->whereNull('format_identified_at')->pluck('id');
        $ids->each(fn ($id) => IdentifyFormat::dispatch($id));

        return back()->with('status', trans_choice(':count file is being identified.|:count files are being identified.', $ids->count(), ['count' => $ids->count()]));
    }
}
