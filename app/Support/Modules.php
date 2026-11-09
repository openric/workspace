<?php

// OpenRiC Workspace - which modules an installation offers (roadmap R1 hardening, after the external audit of 8 November
// 2026, finding F08): the supported modules are always there; each experimental one is off unless WORKSPACE_MODULES names
// it ("all" for every one), and its addresses then answer 404 and leave the menus. MODULES.md lists both kinds and why.
// Modules with a switch of their own (AI, SPARQL, single sign-on, WhatsApp, the jurisdiction packs) keep it.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Support;

use Illuminate\Support\Str;

final class Modules
{
    /** Each experimental module and the route names that belong to it. */
    public const EXPERIMENTAL = [
        'research' => ['research.*', 'reading-room.*', 'registrations.*', 'register'],
        'repatriation' => ['repatriation.*', 'discover.repatriation*'],
        'heritage' => ['heritage.*', 'discover.at-risk*', 'discover.displaced*'],
        'scholarship' => ['scholarship.*', 'discover.research-leads*', 'languages.*', 'discover.languages*', 'discover.transcribe*'],
        'shop' => ['shop.*'],
        'federation' => ['federation.*', 'discover.federated*', 'discover.union*'],
        'archivematica' => ['archivematica.*'],
        'sword' => ['sword.*'],
        // The DataCite account is kept with the research services' settings, which DOIs need without the rest of research.
        'dois' => ['dois.*', 'research.services.*'],
        '3d' => ['models.*', 'discover.models*'],
        'offline-catalogues' => ['offline-catalogues.*'],
        'webhooks' => ['webhooks.*'],
        'intake' => ['intake.*', 'api.intake'],
        'dashboards' => ['dashboards*'],
        'articles' => ['site.article*', 'site.comments*', 'site.attachments*', 'site.images*', 'site.links*'],
        'iiif-extras' => ['iiif-collections.*', 'iiif-workspaces.*', 'iiif-validation.*'],
    ];

    /** @return list<string> the experimental modules this installation offers */
    public static function enabled(): array
    {
        $chosen = array_filter(array_map('trim', explode(',', mb_strtolower((string) config('workspace.modules')))));

        return in_array('all', $chosen, true) ? array_keys(self::EXPERIMENTAL) : array_values(array_intersect(array_keys(self::EXPERIMENTAL), $chosen));
    }

    public static function on(string $module): bool
    {
        return ! isset(self::EXPERIMENTAL[$module]) || in_array($module, self::enabled(), true);
    }

    /** @return list<string> the experimental modules a route belongs to; none for a supported one */
    public static function of(?string $routeName): array
    {
        return $routeName ? array_keys(array_filter(self::EXPERIMENTAL, fn (array $patterns) => Str::is($patterns, $routeName))) : [];
    }

    /** A route is offered when it is supported or any module it belongs to is on. */
    public static function routeOn(?string $routeName): bool
    {
        $modules = self::of($routeName);

        return $modules === [] || array_filter($modules, fn (string $m) => self::on($m)) !== [];
    }
}
