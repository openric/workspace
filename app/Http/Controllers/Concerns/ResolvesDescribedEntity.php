<?php

// OpenRiC Workspace - find the described entity a URL names, for the controllers of its dates, names, identifiers and relations.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers\Concerns;

use App\Relations\EntityKinds;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

trait ResolvesDescribedEntity
{
    /**
     * Another institution's entity, or a deleted one, does not exist here (404);
     * changing what belongs to it needs update on it (403).
     *
     * @return array{0: Model, 1: string} the entity and the route of its page
     */
    private function subject(Request $request, string $segment, string $id): array
    {
        $kind = EntityKinds::bySegment($segment) ?? abort(404);
        $definition = EntityKinds::get($kind);
        $subject = EntityKinds::existing($kind, $request->user()->institution_id)->find($id) ?? abort(404);
        abort_unless($request->user()->may('update', $definition['permission'], $subject), 403);

        return [$subject, $definition['route']];
    }

    /** A part reached through the wrong entity does not exist (404). */
    private function belongs(Model $subject, Model $part): void
    {
        abort_unless($part->getAttribute('subject_table') === $subject->getTable() && $part->getAttribute('subject_id') === $subject->getKey(), 404);
    }
}
