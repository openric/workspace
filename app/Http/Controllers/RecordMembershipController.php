<?php

// OpenRiC Workspace - a unit of description in record sets besides its parent (rico:Proxy; roadmap phase 24, ADR 0019).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Description\RecordMemberships;
use App\Models\RecordMembership;
use App\Models\RecordResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class RecordMembershipController extends Controller
{
    public function __construct(private RecordMemberships $memberships) {}

    /** Put the unit in another record set as well, named by its reference code, IRI or UUID. */
    public function store(Request $request, RecordResource $record): RedirectResponse
    {
        abort_unless($record->institution_id === $request->user()->institution_id && $record->deleted_at === null, 404);
        $data = $request->validate(['record_set' => ['required', 'string', 'max:2048'], 'note' => ['nullable', 'string', 'max:2000']]);
        $named = trim($data['record_set']);
        $units = RecordResource::where('institution_id', $record->institution_id)->whereNull('deleted_at');
        // By its IRI or UUID, else by its reference code or identifier.
        // ponytail: the last two read every unit of the institution; a lookup table of reference codes would serve a large archive.
        $set = (clone $units)->where('iri', $named)->first() ?? (preg_match('/^[0-9a-f-]{36}$/', $named) ? (clone $units)->find($named) : null)
            ?? $units->get()->first(fn (RecordResource $u) => $u->referenceCode() === $named || $u->identifier === $named);
        if ($set === null) {
            return back()->withInput()->withErrors(['record_set' => __('No record set here has that reference code, IRI or identifier.')]);
        }
        try {
            $this->memberships->add($record, $set, $request->user(), null, $data['note'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['record_set' => $e->getMessage()]);
        }

        return back()->with('status', __('It is now also in :set.', ['set' => $set->described('title', ['en'])]));
    }

    public function step(Request $request, RecordMembership $membership, string $step): RedirectResponse
    {
        abort_unless($membership->institution_id === $request->user()->institution_id, 404);
        try {
            $step === 'remove' ? $this->memberships->remove($membership, $request->user()) : $this->memberships->move($membership, $step, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['record_set' => $e->getMessage()]);
        }

        return back()->with('status', __($step === 'remove' ? 'Taken out of that record set.' : 'Moved.'));
    }
}
