<?php

// OpenRiC Workspace - the clipboard's page and actions (Heratio's ClipboardController, ahg-core).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// For anyone, as in Heratio: a signed-in reader's clipboard holds what they may
// read in their institution; a visitor's, what the public catalogue shows.

namespace App\Http\Controllers;

use App\Clipboard\ClipboardService;
use App\Models\Institution;
use App\Support\HostInstitution;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class ClipboardController extends Controller
{
    public function __construct(private ClipboardService $clipboard) {}

    public function index(Request $request): View
    {
        $institution = $this->institution($request);

        return view($request->user() ? 'clipboard.index' : 'clipboard.public', ['rows' => collect($this->clipboard->details($request->user(), $institution)),
            'institution' => $institution]);
    }

    public function add(Request $request): RedirectResponse
    {
        $data = $this->entry($request);
        $added = $this->clipboard->add($data['type'], $data['id'], $request->user(), $this->institution($request));

        return back()->with('status', $added ? 'Added to the clipboard.' : 'That cannot go on the clipboard.');
    }

    public function remove(Request $request): RedirectResponse
    {
        $data = $this->entry($request);
        $this->clipboard->remove($data['type'], $data['id'], $request->user());

        return back()->with('status', 'Taken off the clipboard.');
    }

    public function clear(Request $request): RedirectResponse
    {
        $this->clipboard->clear($request->validate(['type' => ['nullable', Rule::in(array_keys(ClipboardService::TYPES))]])['type'] ?? null);

        return redirect()->route('clipboard.index')->with('status', 'The clipboard is empty.');
    }

    public function save(Request $request): RedirectResponse
    {
        try {
            $id = $this->clipboard->save($request->user(), $this->institution($request));
        } catch (InvalidArgumentException $e) {
            return redirect()->route('clipboard.index')->withErrors(['clipboard' => $e->getMessage()]);
        }

        return redirect()->route('clipboard.index')->with('clipboard_id', $id);
    }

    public function load(Request $request): RedirectResponse
    {
        $data = $request->validate(['clipboard_id' => ['required', 'string', 'max:16'], 'mode' => ['nullable', Rule::in(['merge', 'replace'])]]);
        try {
            $count = $this->clipboard->load($data['clipboard_id'], ($data['mode'] ?? 'merge') === 'replace', $request->user(), $this->institution($request));
        } catch (InvalidArgumentException $e) {
            return redirect()->route('clipboard.index')->withErrors(['clipboard_id' => $e->getMessage()]);
        }

        return redirect()->route('clipboard.index')->with('status', "Clipboard {$data['clipboard_id']} loaded: $count ".($count === 1 ? 'entry' : 'entries').'.');
    }

    public function export(Request $request): Response
    {
        return response($this->clipboard->exportCsv($request->user(), $this->institution($request)), 200, [
            'Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="clipboard.csv"']);
    }

    /** @return array{type: string, id: string} */
    private function entry(Request $request): array
    {
        return $request->validate(['type' => ['required', Rule::in(array_keys(ClipboardService::TYPES))], 'id' => ['required', 'uuid']]);
    }

    private function institution(Request $request): Institution
    {
        return $request->user()?->institution ?? HostInstitution::for($request) ?? abort(404);
    }
}
