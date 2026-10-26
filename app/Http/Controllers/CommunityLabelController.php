<?php

// OpenRiC Workspace - apply and remove the Local Contexts Labels of a unit of description or an instantiation (WS-007, roadmap phase 2).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesDescribedEntity;
use App\Models\CommunityLabel;
use App\Rights\CommunityLabelService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class CommunityLabelController extends Controller
{
    use ResolvesDescribedEntity;

    public function __construct(private CommunityLabelService $labels) {}

    public function store(Request $request, string $kind, string $id): RedirectResponse
    {
        [$subject, $route] = $this->subject($request, $kind, $id);
        $data = $request->validate(['label' => ['required', 'string', 'max:64'], 'applied_by' => ['required', 'string', 'max:64'], 'community' => ['nullable', 'uuid'],
            'project' => ['nullable', 'string', 'max:255'], 'note' => ['nullable', 'string', 'max:4000']]);
        try {
            $this->labels->apply($subject, $data, $request->user());
        } catch (InvalidArgumentException $e) {
            return redirect()->route($route, $subject)->withInput()->withErrors(['labels' => $e->getMessage()]);
        }

        return redirect()->to(route($route, $subject).'#labels')->with('status', __('Label applied.'));
    }

    public function destroy(Request $request, string $kind, string $id, CommunityLabel $label): RedirectResponse
    {
        [$subject, $route] = $this->subject($request, $kind, $id);
        $this->belongs($subject, $label);
        $this->labels->remove($label, $request->user());

        return redirect()->to(route($route, $subject).'#labels')->with('status', __('Label removed.'));
    }
}
