<?php

// OpenRiC Workspace - find the described entity a URL names, for the controllers of its dates, names and identifiers.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers\Concerns;

use App\Models\RecordResource;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

trait ResolvesDescribedEntity
{
    /** URL segment => [model class, record kind for permissions (ADR 0005), route of its page]; agents join here. */
    private const SUBJECTS = [
        'records' => [RecordResource::class, 'description', 'records.show'],
        'repositories' => [Repository::class, 'repository', 'repositories.show'],
    ];

    /**
     * Another institution's entity, or a deleted one, does not exist here (404);
     * changing what belongs to it needs update on it (403).
     *
     * @return array{0: Model, 1: string} the entity and the route of its page
     */
    private function subject(Request $request, string $kind, string $id): array
    {
        [$class, $permissionKind, $route] = self::SUBJECTS[$kind] ?? abort(404);
        $subject = $class::find($id);
        abort_unless($subject && $subject->institution_id === $request->user()->institution_id && $subject->deleted_at === null, 404);
        abort_unless($request->user()->may('update', $permissionKind, $subject), 403);

        return [$subject, $route];
    }

    /** A part reached through the wrong entity does not exist (404). */
    private function belongs(Model $subject, Model $part): void
    {
        abort_unless($part->getAttribute('subject_table') === $subject->getTable() && $part->getAttribute('subject_id') === $subject->getKey(), 404);
    }

    private function recordKind(string $kind): string
    {
        return self::SUBJECTS[$kind][1];
    }
}
