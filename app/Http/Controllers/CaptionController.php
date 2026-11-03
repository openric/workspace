<?php

// OpenRiC Workspace - caption tracks on an instantiation's page: added, replaced, removed, and read (roadmap phase 22c).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Media\Captions;
use App\Models\CaptionTrack;
use App\Models\Instantiation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

class CaptionController extends Controller
{
    public function __construct(private Captions $captions) {}

    public function store(Request $request, Instantiation $instantiation): RedirectResponse
    {
        $this->own($request, $instantiation);
        $request->validate(['file' => ['required', 'file'], 'language' => ['required', 'string', 'max:35'], 'kind' => ['required', 'string'], 'label' => ['nullable', 'string', 'max:200']]);
        try {
            $track = $this->captions->add($instantiation->load('files'), $request->file('file')->getRealPath(), trim($request->input('language')), $request->input('kind'),
                (string) $request->input('label'), $request->boolean('is_default'), $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['captions' => $e->getMessage()]);
        }

        return redirect()->route('instantiations.show', $instantiation)->with('status', trans_choice('Caption track kept: :count cue.|Caption track kept: :count cues.', $track->cue_count, ['count' => $track->cue_count]));
    }

    public function destroy(Request $request, CaptionTrack $track): RedirectResponse
    {
        $this->own($request, $track->instantiation);
        $track->delete();

        return redirect()->route('instantiations.show', $track->instantiation_id)->with('status', __('Caption track removed.'));
    }

    public function show(Request $request, CaptionTrack $track): Response
    {
        abort_unless($track->institution_id === $request->user()->institution_id && $this->captions->deliverable($request->user(), $track), 404);

        return $this->send($track);
    }

    /** The track as WebVTT, private and not kept by the browser, as every file. */
    public function send(CaptionTrack $track): Response
    {
        $path = $this->captions->path($track);
        abort_unless(is_file($path), 404);

        return response((string) file_get_contents($path), 200, ['Content-Type' => 'text/vtt; charset=utf-8', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline; filename="'.$track->language.'-'.$track->kind.'.vtt"']);
    }

    private function own(Request $request, Instantiation $instantiation): void
    {
        abort_unless($instantiation->institution_id === $request->user()->institution_id && $instantiation->deleted_at === null, 404);
        abort_unless($request->user()->may('update', 'description', $instantiation->record), 403);
    }
}
