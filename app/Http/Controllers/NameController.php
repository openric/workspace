<?php

// OpenRiC Workspace - add, change and remove the names of any described entity (rico:Name; ADR 0006).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Description\NameService;
use App\Http\Controllers\Concerns\ResolvesDescribedEntity;
use App\Models\Name;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class NameController extends Controller
{
    use ResolvesDescribedEntity;

    public function __construct(private NameService $names) {}

    public function store(Request $request, string $kind, string $id): RedirectResponse
    {
        [$subject, $route] = $this->subject($request, $kind, $id);
        try {
            $this->names->add($subject, $this->fields($request), $request->user());
        } catch (InvalidArgumentException $e) {
            return redirect()->route($route, $subject)->withInput()->withErrors(['names' => $e->getMessage()]);
        }

        return redirect()->route($route, $subject)->with('status', __('Name added.'));
    }

    public function update(Request $request, string $kind, string $id, Name $name): RedirectResponse
    {
        [$subject, $route] = $this->subject($request, $kind, $id);
        $this->belongs($subject, $name);
        try {
            $this->names->update($name, $this->fields($request), $request->user());
        } catch (InvalidArgumentException $e) {
            return redirect()->route($route, $subject)->withErrors(['names' => $e->getMessage()]);
        }

        return redirect()->route($route, $subject)->with('status', __('Name saved.'));
    }

    public function destroy(Request $request, string $kind, string $id, Name $name): RedirectResponse
    {
        [$subject, $route] = $this->subject($request, $kind, $id);
        $this->belongs($subject, $name);
        try {
            $this->names->remove($name, $request->user());
        } catch (InvalidArgumentException $e) {
            return redirect()->route($route, $subject)->withErrors(['names' => $e->getMessage()]);
        }

        return redirect()->route($route, $subject)->with('status', __('Name removed.'));
    }

    private function fields(Request $request): array
    {
        return $request->validate([
            'type' => ['required', 'string', 'max:64'],
            'value' => ['required', 'string', 'max:2000'],
            'language' => ['required', 'string', 'max:35'],
            'used_from' => ['nullable', 'string', 'max:64'],
            'used_to' => ['nullable', 'string', 'max:64'],
        ]) + ['used_from' => null, 'used_to' => null];
    }
}
