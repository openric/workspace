<?php

// OpenRiC Workspace - the Help Center's system map and system breakdown (roadmap phase 20b), after Heratio's ahg-help
// SystemMapService and SystemBreakdownService: the map, how a record moves through the Workspace stage by stage, three
// levels deep; the breakdown, what the Workspace does for each kind of entity, four levels deep. Both are data, in
// resources/help, written for the Workspace; each node may name the guide that explains it, and a guide the viewer may
// not open, or that does not exist, leaves the node as plain text rather than a dead link.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Help;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SystemMaps
{
    public function map(bool $staff): array
    {
        return $this->resolve(require resource_path('help/system-map.php'), $staff);
    }

    public function breakdown(bool $staff): array
    {
        return $this->resolve(require resource_path('help/system-breakdown.php'), $staff);
    }

    public static function slug(string $category): string
    {
        return Str::slug($category);
    }

    /** Each node's guide kept only if the viewer may open it. */
    private function resolve(array $nodes, bool $staff): array
    {
        $open = DB::table('help_articles')->when(! $staff, fn ($q) => $q->where('staff_only', false))->pluck('slug')->flip();
        $walk = function (array $nodes) use (&$walk, $open): array {
            return array_map(function (array $node) use ($walk, $open) {
                $node['help'] = isset($node['help'], $open[$node['help']]) ? $node['help'] : null;
                $node['children'] = $walk($node['children'] ?? []);

                return $node;
            }, $nodes);
        };

        return $walk($nodes);
    }
}
