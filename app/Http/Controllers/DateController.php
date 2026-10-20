<?php

// OpenRiC Workspace - add, change and remove the dates of any described entity (ADR 0006).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// One controller for every kind of entity: the URL names the kind (records,
// repositories, and agents later) and the entity; changing its dates needs
// update on that entity (ADR 0005).

namespace App\Http\Controllers;

use App\Description\DateService;
use App\Http\Controllers\Concerns\ResolvesDescribedEntity;
use App\Models\RicDate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class DateController extends Controller
{
    use ResolvesDescribedEntity;

    public function __construct(private DateService $dates) {}

    public function store(Request $request, string $kind, string $id): RedirectResponse
    {
        [$subject, $route] = $this->subject($request, $kind, $id);
        try {
            $this->dates->add($subject, $this->fields($request, $kind), $request->user());
        } catch (InvalidArgumentException $e) {
            return redirect()->route($route, $subject)->withInput()->withErrors(['dates' => $e->getMessage()]);
        }

        return redirect()->route($route, $subject)->with('status', 'Date added.');
    }

    public function update(Request $request, string $kind, string $id, RicDate $date): RedirectResponse
    {
        [$subject, $route] = $this->subject($request, $kind, $id);
        $this->belongs($subject, $date);
        try {
            $this->dates->update($date, $this->fields($request, $kind), $request->user());
        } catch (InvalidArgumentException $e) {
            return redirect()->route($route, $subject)->withErrors(['dates' => $e->getMessage()]);
        }

        return redirect()->route($route, $subject)->with('status', 'Date saved.');
    }

    public function destroy(Request $request, string $kind, string $id, RicDate $date): RedirectResponse
    {
        [$subject, $route] = $this->subject($request, $kind, $id);
        $this->belongs($subject, $date);
        $this->dates->remove($date, $request->user());

        return redirect()->route($route, $subject)->with('status', 'Date removed.');
    }

    private function fields(Request $request, string $kind): array
    {
        $properties = config('workspace.date_properties.'.$this->recordKind($kind));

        return $request->validate([
            'property' => ['required', 'string', 'in:'.implode(',', $properties)],
            'normalized' => ['nullable', 'string', 'max:64'],
            'qualifier' => ['nullable', 'string', 'max:64'],
            'type' => ['nullable', 'string', 'max:64'],
            'expressed' => ['nullable', 'string', 'max:500'],
            'language' => ['nullable', 'string', 'max:35'],
        ]);
    }
}
