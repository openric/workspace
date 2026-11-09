<?php

// OpenRiC Workspace - ResourceSync (ANSI/NISO Z39.99-2017) over the published catalogue (roadmap phase 16e), after
// Heratio's ahg-resourcesync: the source description at /.well-known/resourcesync points to the capability list, which
// offers a resource list (every published entity by its persistent IRI, with when it last changed) and a change list
// (what was made or changed since a moment, thirty days back unless asked, and what was withdrawn, as deprecated, since
// then), so a harvester keeps a copy in step without taking everything again; both paged by ?page= as Heratio pages
// them, each page linking the next and the one before. Only what the public may read is listed, as in the sitemaps.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Api\PublicCatalogue;
use App\Models\Deprecation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ResourceSyncController extends Controller
{
    private const KINDS = ['records', 'agents', 'repositories', 'places', 'activities', 'rules'];

    public function description(): Response
    {
        return $this->urlset('description', [['loc' => route('resourcesync.capabilities'), 'ln' => [['rel' => 'describedby', 'href' => route('discover.home')]], 'capability' => 'capabilitylist']]);
    }

    public function capabilities(): Response
    {
        return $this->urlset('capabilitylist', [['loc' => route('resourcesync.resources'), 'capability' => 'resourcelist'], ['loc' => route('resourcesync.changes'), 'capability' => 'changelist']],
            route('resourcesync.description'));
    }

    public function resources(Request $request): Response
    {
        $urls = [];
        foreach (self::KINDS as $kind) {
            // Streamed from the database, only the address and the date (v0.0.146).
            foreach ($this->catalogue($request)->query($kind)->select('e.iri', 'e.updated_at')->orderBy('e.id')->cursor() as $entity) {
                $urls[] = ['loc' => $entity->iri, 'lastmod' => $entity->updated_at?->toAtomString()];
            }
        }

        return $this->page($request, 'resourcelist', $urls, ['at' => now()->toAtomString()]);
    }

    /** What changed since ?from= (an ISO 8601 moment; Heratio's horizon of days back when none is given), oldest first. */
    public function changes(Request $request): Response
    {
        try {
            $from = $request->filled('from') ? CarbonImmutable::parse((string) $request->query('from')) : now()->subDays((int) config('workspace.resourcesync.changelist_days'))->toImmutable();
        } catch (Throwable) {
            return response(__('Give from as an ISO 8601 date and time.'), 400, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $urls = [];
        foreach (self::KINDS as $kind) {
            foreach ($this->catalogue($request)->query($kind)->where('e.updated_at', '>=', $from)->select('e.iri', 'e.updated_at', 'e.created_at')->orderBy('e.id')->cursor() as $entity) {
                $urls[] = ['loc' => $entity->iri, 'lastmod' => $entity->updated_at->toAtomString(), 'change' => $entity->created_at?->gte($from) ? 'created' : 'updated'];
            }
        }
        $institution = $request->attributes->get('institution');
        foreach (Deprecation::where(['institution_id' => $institution->id, 'was_public' => true])->where('deprecated_at', '>=', $from)->get() as $gone) {
            $urls[] = ['loc' => $gone->iri, 'lastmod' => $gone->deprecated_at->toAtomString(), 'change' => 'deleted'];
        }
        usort($urls, fn ($a, $b) => strcmp($a['lastmod'], $b['lastmod']));

        return $this->page($request, 'changelist', $urls, ['from' => $from->toAtomString(), 'until' => now()->toAtomString()]);
    }

    /** One page of a list, linked to the next and the one before by rs:ln, as Heratio's are. */
    private function page(Request $request, string $capability, array $urls, array $times): Response
    {
        $size = max(1, (int) config('workspace.resourcesync.page_size'));
        $page = max(1, (int) $request->query('page', 1));
        $pages = max(1, (int) ceil(count($urls) / $size));
        $route = 'resourcesync.'.($capability === 'resourcelist' ? 'resources' : 'changes');
        $query = $request->only('from');
        $links = array_filter(['next' => $page < $pages ? route($route, [...$query, 'page' => $page + 1]) : null, 'prev' => $page > 1 ? route($route, [...$query, 'page' => $page - 1]) : null]);

        return $this->urlset($capability, array_slice($urls, ($page - 1) * $size, $size), route('resourcesync.capabilities'), $times, $links);
    }

    /** A sitemaps.org urlset carrying the ResourceSync rs:md and rs:ln of each URL. */
    private function urlset(string $capability, array $urls, ?string $up = null, array $times = [], array $links = []): Response
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:rs="http://www.openarchives.org/rs/terms/">'
            .'<rs:md capability="'.$capability.'"'.collect($times)->map(fn ($v, $k) => ' '.$k.'="'.e($v).'"')->implode('').'/>'
            .($up ? '<rs:ln rel="up" href="'.e($up).'"/>' : '')
            .collect($links)->map(fn ($href, $rel) => '<rs:ln rel="'.$rel.'" href="'.e($href).'"/>')->implode('');
        foreach ($urls as $url) {
            $xml .= '<url><loc>'.e($url['loc']).'</loc>'.(isset($url['lastmod']) ? '<lastmod>'.e($url['lastmod']).'</lastmod>' : '');
            foreach ($url['ln'] ?? [] as $ln) {
                $xml .= '<rs:ln rel="'.e($ln['rel']).'" href="'.e($ln['href']).'"/>';
            }
            $md = array_filter(['capability' => $url['capability'] ?? null, 'change' => $url['change'] ?? null]);
            $xml .= ($md ? '<rs:md'.collect($md)->map(fn ($v, $k) => ' '.$k.'="'.e($v).'"')->implode('').'/>' : '').'</url>';
        }

        return response($xml.'</urlset>', 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    private function catalogue(Request $request): PublicCatalogue
    {
        return $request->attributes->get('catalogue');
    }
}
