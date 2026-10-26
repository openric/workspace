<?php

// OpenRiC Workspace - add, change and remove the identifiers of any described entity (rico:Identifier; ADR 0006).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Description\IdentifierService;
use App\Http\Controllers\Concerns\ResolvesDescribedEntity;
use App\Models\Identifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class IdentifierController extends Controller
{
    use ResolvesDescribedEntity;

    public function __construct(private IdentifierService $identifiers) {}

    public function store(Request $request, string $kind, string $id): RedirectResponse
    {
        [$subject, $route] = $this->subject($request, $kind, $id);
        try {
            $this->identifiers->add($subject, $this->fields($request), $request->user());
        } catch (InvalidArgumentException $e) {
            return redirect()->route($route, $subject)->withInput()->withErrors(['identifiers' => $e->getMessage()]);
        }

        return redirect()->route($route, $subject)->with('status', __('Identifier added.'));
    }

    public function update(Request $request, string $kind, string $id, Identifier $identifier): RedirectResponse
    {
        [$subject, $route] = $this->subject($request, $kind, $id);
        $this->belongs($subject, $identifier);
        try {
            $this->identifiers->update($identifier, $this->fields($request), $request->user());
        } catch (InvalidArgumentException $e) {
            return redirect()->route($route, $subject)->withErrors(['identifiers' => $e->getMessage()]);
        }

        return redirect()->route($route, $subject)->with('status', __('Identifier saved.'));
    }

    public function destroy(Request $request, string $kind, string $id, Identifier $identifier): RedirectResponse
    {
        [$subject, $route] = $this->subject($request, $kind, $id);
        $this->belongs($subject, $identifier);
        $this->identifiers->remove($identifier, $request->user());

        return redirect()->route($route, $subject)->with('status', __('Identifier removed.'));
    }

    private function fields(Request $request): array
    {
        return $request->validate([
            'type' => ['required', 'string', 'max:64'],
            'value' => ['required', 'string', 'max:255'],
        ]);
    }
}
