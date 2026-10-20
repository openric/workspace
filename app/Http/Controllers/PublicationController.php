<?php

// OpenRiC Workspace - review, publish and withdraw an entity's description.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Reviewing needs update on the entity; publishing and withdrawing need
// publish (ADR 0005). Publishing the units inside a unit needs publish on each.

namespace App\Http\Controllers;

use App\Description\PublicationService;
use App\Models\Concept;
use App\Models\RecordResource;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PublicationController extends Controller
{
    public function __construct(private PublicationService $publication) {}

    public function edit(Request $request, string $kind, string $id): View
    {
        [$entity, $permission] = $this->entity($request, $kind, $id);

        return view('description.publication', ['entity' => $entity, 'kind' => $kind, 'summary' => $this->publication->summary($entity),
            'mayPublish' => $request->user()->may('publish', $permission, $entity), 'mayReview' => $request->user()->may('update', $permission, $entity),
            'states' => Concept::choices(Vocab::VALUE_STATES)->get()->keyBy('notation'),
            'inside' => $entity instanceof RecordResource ? RecordResource::whereKey($entity->descendantIds())->whereNull('deleted_at')->count() : 0]);
    }

    public function update(Request $request, string $kind, string $id): RedirectResponse
    {
        [$entity, $permission] = $this->entity($request, $kind, $id);
        $data = $request->validate([
            'action' => ['required', Rule::in(array_keys(PublicationService::ACTIONS))],
            'language' => ['nullable', 'string', 'max:35'],
            'inside' => ['nullable', 'boolean'],
        ]);
        $user = $request->user();
        abort_unless($user->may($data['action'] === 'review' ? 'update' : 'publish', $permission, $entity), 403);
        $inside = (bool) ($data['inside'] ?? false);
        if ($inside && $entity instanceof RecordResource) {
            foreach (RecordResource::whereKey($entity->descendantIds())->whereNull('deleted_at')->get() as $unit) {
                abort_unless($user->may($data['action'] === 'review' ? 'update' : 'publish', $permission, $unit), 403);
            }
        }
        $changed = $this->publication->apply($entity, $data['action'], ($data['language'] ?? '') ?: null, $inside, $user);
        $done = ['review' => 'reviewed', 'publish' => 'published', 'withdraw' => 'withdrawn'][$data['action']];

        return redirect()->route(EntityKinds::get(EntityKinds::of($entity))['route'], $entity)
            ->with('status', $changed === 0 ? "Nothing to mark $done." : "$changed ".($changed === 1 ? 'value' : 'values')." $done.");
    }

    /** @return array{0: Model, 1: string} the entity and its permission kind; 404 outside the institution, 403 without update or publish */
    private function entity(Request $request, string $segment, string $id): array
    {
        $kind = EntityKinds::bySegment($segment) ?? abort(404);
        $entity = EntityKinds::existing($kind, $request->user()->institution_id)->find($id) ?? abort(404);
        $permission = EntityKinds::get($kind)['permission'];
        abort_unless($request->user()->may('update', $permission, $entity) || $request->user()->may('publish', $permission, $entity), 403);

        return [$entity, $permission];
    }
}
