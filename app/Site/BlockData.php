<?php

// OpenRiC Workspace - what each landing page block shows (roadmap phase 20a), after Heratio's ahg-landing-page
// BlockDataResolver and its two rules: everything comes from what the public may read, counts as much as lists, since a
// count tells a visitor how much exists; and a block that cannot be filled shows nothing rather than failing the page.
// Changed from Heratio: the public catalogue decides what is read, as for every public page (ExportView, ADR 0005),
// rather than a publication status checked here; and the map shows the published places with their coordinates.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Site;

use App\Api\PublicCatalogue;
use App\Models\RecordResource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class BlockData
{
    /** The kinds a block may count or list. */
    public const KINDS = ['records', 'agents', 'repositories', 'places', 'activities', 'rules'];

    /** Heratio's CACHE_TTL: counts are stable enough to keep five minutes. */
    private const CACHE_SECONDS = 300;

    public function resolve(string $type, array $config, PublicCatalogue $catalogue): mixed
    {
        try {
            return match ($type) {
                'statistics' => $this->statistics($config, $catalogue),
                'browse-panels' => $this->panels($config, $catalogue),
                'recent-items' => $this->recent($config, $catalogue),
                'featured-items' => $this->featured($config, $catalogue),
                'holdings-list' => $this->holdings($config, $catalogue),
                'image-carousel' => $this->carousel($config, $catalogue),
                'repository-spotlight' => $this->spotlight($config, $catalogue),
                'map' => $this->places($catalogue),
                default => null,
            };
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    public function count(string $kind, PublicCatalogue $catalogue): int
    {
        return in_array($kind, self::KINDS, true)
            ? (int) Cache::remember('landing:count:'.$catalogue->institution->id.':'.$kind, self::CACHE_SECONDS, fn () => $catalogue->query($kind)->count()) : 0;
    }

    private function statistics(array $config, PublicCatalogue $catalogue): array
    {
        return array_map(fn ($stat) => [...$stat, 'count' => $this->count((string) ($stat['kind'] ?? ''), $catalogue)], array_values((array) ($config['stats'] ?? [])));
    }

    private function panels(array $config, PublicCatalogue $catalogue): array
    {
        return array_map(fn ($panel) => [...$panel, 'count' => ! empty($panel['kind']) ? $this->count((string) $panel['kind'], $catalogue) : null,
            'href' => LandingPages::url($panel['url'] ?? '') ?: route('discover.home', ['catalogue' => 1])], array_values((array) ($config['panels'] ?? [])));
    }

    /** The units of description published most recently, with a thumbnail where the public may see one. */
    private function recent(array $config, PublicCatalogue $catalogue): array
    {
        $limit = max(1, min((int) ($config['limit'] ?? 6), 50));
        $units = $catalogue->query('records')->with([...PublicCatalogue::WITH, 'level.labels'])->orderByDesc('e.created_at')->limit($limit)->get()
            ->filter(fn ($u) => $catalogue->view->readable($u))->values();

        return $this->cards($units, $catalogue);
    }

    /** The units chosen, in the order chosen, that the public may read. */
    private function featured(array $config, PublicCatalogue $catalogue): array
    {
        $units = collect((array) ($config['items'] ?? []))->map(fn ($id) => $catalogue->find('records', (string) $id, ['level.labels']))->filter()->values();

        return $this->cards($units, $catalogue);
    }

    /** The top-level units, of one repository or all, by title or newest first (Heratio's holdingsList). */
    private function holdings(array $config, PublicCatalogue $catalogue): array
    {
        $limit = max(1, min((int) ($config['limit'] ?? 10), 50));
        $units = $catalogue->visible('records', '', false, ['level.labels'], fn ($q) => $q->whereNull('e.parent_id')
            ->when(! empty($config['repository_id']), fn ($q) => $q->where('e.repository_id', $config['repository_id'])));
        $units = ($config['sort'] ?? 'title') === 'title' ? $units : $units->sortByDesc('created_at');

        return $units->take($limit)->values()->map(fn (RecordResource $u) => ['unit' => $u, 'title' => $catalogue->view->label($u), 'level' => $u->level?->label([app()->getLocale(), 'en']),
            'reference' => $u->referenceCode(), 'href' => route('discover.record', $u->id)])->all();
    }

    /** The images chosen, or the collections the catalogue's home shows with their pictures. */
    private function carousel(array $config, PublicCatalogue $catalogue): array
    {
        if (($config['source'] ?? 'collections') === 'images') {
            return array_values(array_filter(array_map(fn ($image) => ['src' => LandingPages::url($image['url'] ?? ''), 'caption' => (string) ($image['caption'] ?? ''),
                'href' => LandingPages::url($image['link'] ?? '')], (array) ($config['images'] ?? [])), fn ($i) => $i['src'] !== ''));
        }

        return $catalogue->featured()->take(max(1, min((int) ($config['limit'] ?? 12), 50)))->map(fn ($f) => ['src' => route('discover.file', $f['file']->id),
            'caption' => (string) $catalogue->view->label($f['unit']), 'href' => route('discover.record', $f['unit']->id)])->values()->all();
    }

    private function spotlight(array $config, PublicCatalogue $catalogue): ?array
    {
        $repository = ! empty($config['repository_id']) ? $catalogue->find('repositories', (string) $config['repository_id'], ['contactPoints'])
            : $catalogue->visible('repositories', '', false, ['contactPoints'])->first();
        if (! $repository) {
            return null;
        }
        $holdings = $catalogue->visible('records', '', false, [], fn ($q) => $q->whereNull('e.parent_id')->where('e.repository_id', $repository->id))->take(max(1, min((int) ($config['max_holdings'] ?? 5), 50)));

        return ['repository' => $repository, 'name' => $catalogue->view->label($repository), 'href' => route('discover.repository', $repository->id),
            'description' => ($text = $catalogue->view->value($repository, 'history') ?? $catalogue->view->value($repository, 'holdings')) ? Str::limit($text, 400) : null,
            'contact' => $repository->contactPoints->sortByDesc('is_primary')->first(),
            'holdings' => $holdings->map(fn ($u) => ['title' => $catalogue->view->label($u), 'href' => route('discover.record', $u->id)])->values()->all()];
    }

    /** The published places that have coordinates. */
    private function places(PublicCatalogue $catalogue): array
    {
        return $catalogue->visible('places', '', false, [], fn ($q) => $q->whereNotNull('e.latitude')->whereNotNull('e.longitude'))->take(500)
            ->map(fn ($p) => ['name' => (string) $catalogue->view->label($p), 'latitude' => (float) $p->latitude, 'longitude' => (float) $p->longitude, 'href' => route('discover.place', $p->id)])->values()->all();
    }

    /** Units as cards: title, level, date and a thumbnail the public may see. */
    private function cards($units, PublicCatalogue $catalogue): array
    {
        $pictures = $catalogue->featured($units)->keyBy(fn ($f) => $f['unit']->id);

        return $units->map(fn (RecordResource $u) => ['title' => $catalogue->view->label($u), 'href' => route('discover.record', $u->id), 'level' => $u->level?->label([app()->getLocale(), 'en']),
            'date' => $u->created_at?->format('Y-m-d'), 'summary' => ($text = $catalogue->view->value($u, 'scope-and-content')) ? Str::limit(trim(preg_replace('/\s+/', ' ', $text)), 160) : null,
            'thumbnail' => isset($pictures[$u->id]) ? route('discover.file', $pictures[$u->id]['file']->id) : null])->values()->all();
    }
}
