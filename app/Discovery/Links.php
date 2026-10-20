<?php

// OpenRiC Workspace - the public page of each kind of entity (WS-009).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Discovery;

use App\Relations\EntityKinds;
use Illuminate\Database\Eloquent\Model;

final class Links
{
    /** kind => its public route; a concept has no public page yet. */
    public const PAGES = ['record' => 'discover.record', 'agent' => 'discover.agent', 'repository' => 'discover.repository', 'place' => 'discover.place', 'activity' => 'discover.activity'];

    /** kind => its PublicCatalogue key. */
    public const CATALOGUE = ['record' => 'records', 'agent' => 'agents', 'repository' => 'repositories', 'place' => 'places', 'activity' => 'activities'];

    public static function page(Model $entity): ?string
    {
        $route = self::PAGES[EntityKinds::of($entity)] ?? null;

        return $route ? route($route, $entity->getKey()) : null;
    }
}
