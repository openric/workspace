<?php

// OpenRiC Workspace - add, change and remove the rights of a unit of description or an instantiation (WS-007, roadmap phase 2).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesDescribedEntity;
use App\Models\Right;
use App\Rights\RightsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class RightsController extends Controller
{
    use ResolvesDescribedEntity;

    public function __construct(private RightsService $rights) {}

    public function store(Request $request, string $kind, string $id): RedirectResponse
    {
        [$subject, $route] = $this->subject($request, $kind, $id);
        try {
            $this->rights->add($subject, $this->fields($request), $request->user());
        } catch (InvalidArgumentException $e) {
            return redirect()->route($route, $subject)->withInput()->withErrors(['rights' => $e->getMessage()]);
        }

        return redirect()->to(route($route, $subject).'#rights')->with('status', __('Right added.'));
    }

    public function update(Request $request, string $kind, string $id, Right $right): RedirectResponse
    {
        [$subject, $route] = $this->subject($request, $kind, $id);
        $this->belongs($subject, $right);
        try {
            $this->rights->update($right, $this->fields($request), $request->user());
        } catch (InvalidArgumentException $e) {
            return redirect()->route($route, $subject)->withInput()->withErrors(['rights' => $e->getMessage()]);
        }

        return redirect()->to(route($route, $subject).'#rights')->with('status', __('Right saved.'));
    }

    public function destroy(Request $request, string $kind, string $id, Right $right): RedirectResponse
    {
        [$subject, $route] = $this->subject($request, $kind, $id);
        $this->belongs($subject, $right);
        $this->rights->remove($right, $request->user());

        return redirect()->to(route($route, $subject).'#rights')->with('status', __('Right removed.'));
    }

    /** @return array<string, mixed> */
    private function fields(Request $request): array
    {
        $data = $request->validate([
            'basis' => ['required', 'string', 'max:64'], 'statement' => ['nullable', 'string', 'max:64'], 'licence' => ['nullable', 'string', 'max:64'],
            'copyright_status' => ['nullable', 'string', 'max:64'], 'jurisdiction' => ['nullable', 'string', 'max:2'], 'determined_on' => ['nullable', 'string', 'max:10'],
            'citation' => ['nullable', 'string', 'max:2000'], 'starts_on' => ['nullable', 'string', 'max:10'], 'ends_on' => ['nullable', 'string', 'max:10'],
            'holder' => ['nullable', 'uuid'], 'note' => ['nullable', 'string', 'max:4000'], 'note_language' => ['nullable', 'string', 'max:35'],
            'grants' => ['nullable', 'array'], 'grants.*.restriction' => ['nullable', 'string', 'max:64'], 'grants.*.note' => ['nullable', 'string', 'max:1000'],
        ]);
        // One row per act on the form; an act left at "not said" is not a grant.
        $data['grants'] = collect($data['grants'] ?? [])->filter(fn ($g) => ($g['restriction'] ?? '') !== '')
            ->map(fn ($g, $act) => ['act' => $act, 'restriction' => $g['restriction'], 'note' => $g['note'] ?? null])->values()->all();

        return $data;
    }
}
