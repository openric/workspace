<?php

// OpenRiC Workspace - confirm and delete a unit of description, a repository or a place.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// As Heratio does, deleting asks first: the confirmation page says what goes
// with the entity and what has to be moved before it can go. The rule itself
// is EntityDeletion's; this needs the delete permission on the entity. A reason
// and what replaces it go with the IRI, which answers as deprecated (ADR 0013).

namespace App\Http\Controllers;

use App\Description\EntityDeletion;
use App\Models\RecordResource;
use App\Relations\EntityKinds;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class DeletionController extends Controller
{
    public function __construct(private EntityDeletion $deletion) {}

    public function confirm(Request $request, string $kind, string $id): View
    {
        $entity = $this->entity($request, $kind, $id);

        return view('description.delete', ['entity' => $entity, 'kind' => $kind, 'blockers' => $this->deletion->blockers($entity),
            'consequences' => $this->deletion->consequences($entity)]);
    }

    public function destroy(Request $request, string $kind, string $id): RedirectResponse
    {
        $entity = $this->entity($request, $kind, $id);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000'], 'replaced_by' => ['nullable', 'string', 'max:2000']]);
        $name = EntityKinds::label($entity);
        // What replaces it, by its persistent IRI: an entity of the same kind in the institution.
        $successor = null;
        if (($data['replaced_by'] ?? '') !== '') {
            $successor = $entity::where(['iri' => trim($data['replaced_by']), 'institution_id' => $entity->getAttribute('institution_id')])->whereNull('deleted_at')->first();
            if ($successor === null) {
                return redirect()->route('deletion.confirm', [$kind, $id])->withInput()->withErrors(['replaced_by' => __('No current :kind here has that persistent IRI.', ['kind' => __(EntityKinds::noun($entity))])]);
            }
        }
        try {
            $this->deletion->delete($entity, $request->user(), $data['reason'] ?? null, $successor);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('deletion.confirm', [$kind, $id])->withErrors(['delete' => $e->getMessage()]);
        }
        // A unit inside another goes back to the unit above it; anything else to its list.
        $back = $entity instanceof RecordResource && $entity->parent_id
            ? route('records.show', $entity->parent_id)
            : route(EntityKinds::get(EntityKinds::of($entity))['index']);

        return redirect($back)->with('status', __('Deleted: :name.', ['name' => $name]));
    }

    /** Another institution's entity, or a deleted one, does not exist here (404); deleting needs the delete permission (403). */
    private function entity(Request $request, string $segment, string $id): Model
    {
        $kind = EntityKinds::bySegment($segment) ?? abort(404);
        $entity = EntityKinds::existing($kind, $request->user()->institution_id)->find($id) ?? abort(404);
        abort_unless($request->user()->may('delete', EntityKinds::get($kind)['permission'], $entity), 403);

        return $entity;
    }
}
