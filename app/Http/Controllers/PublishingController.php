<?php

// OpenRiC Workspace - what tells search engines and readers what the catalogue holds (roadmap phase 22c): sitemaps,
// robots.txt naming them, and an Atom feed of what was published last, after Heratio's ahg-api PublicSitemapController
// and FeedController. Each lists only what the public may read, at its public address.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Api\PublicCatalogue;
use App\Models\RecordResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PublishingController extends Controller
{
    /** The kinds with a public page, and its route. */
    private const PAGES = ['records' => 'discover.record', 'agents' => 'discover.agent', 'repositories' => 'discover.repository', 'places' => 'discover.place',
        'activities' => 'discover.activity', 'rules' => 'discover.rule'];

    public function robots(): Response
    {
        return response("User-agent: *\nDisallow:\nSitemap: ".route('sitemap')."\n", 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /** The sitemap index: one sitemap for each kind with a public page. */
    public function index(): Response
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .'<sitemap><loc>'.e(route('discover.home')).'</loc></sitemap>';
        foreach (array_keys(self::PAGES) as $kind) {
            $xml .= '<sitemap><loc>'.e(route('sitemap.kind', $kind)).'</loc></sitemap>';
        }

        return response($xml.'</sitemapindex>', 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    /**
     * The public pages of one kind (sitemaps.org 0.9), each with when it last changed.
     * ponytail: one sitemap a kind, up to the protocol's 50,000 addresses; more needs numbered pages.
     */
    public function kind(Request $request, string $kind): Response
    {
        $catalogue = $this->catalogue($request);
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($catalogue->visible($kind)->take(50000) as $entity) {
            $xml .= '<url><loc>'.e(route(self::PAGES[$kind], $entity->getKey())).'</loc><lastmod>'.$entity->updated_at?->toAtomString().'</lastmod></url>';
        }

        return response($xml.'</urlset>', 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    /** The last 50 archival descriptions made public, newest first (RFC 4287), each with its scope and content. */
    public function feed(Request $request): Response
    {
        $catalogue = $this->catalogue($request);
        $view = $catalogue->view;
        $records = $catalogue->visible('records')->sortByDesc(fn (RecordResource $r) => $r->updated_at)->take(50)->values();
        $updated = $records->first()?->updated_at ?? now();
        $institution = $request->attributes->get('institution');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<feed xmlns="http://www.w3.org/2005/Atom">'
            .'<id>'.e(route('feed')).'</id><title>'.e(__('Newly published at :name', ['name' => $institution->name])).'</title>'
            .'<link rel="self" href="'.e(route('feed')).'"/><link rel="alternate" href="'.e(route('discover.home')).'"/>'
            .'<updated>'.$updated->toAtomString().'</updated><author><name>'.e($institution->name).'</name></author>';
        foreach ($records as $record) {
            $summary = (string) $view->value($record, 'scope-and-content');
            $xml .= '<entry><id>'.e($record->iri).'</id><title>'.e((string) $view->label($record)).'</title>'
                .'<link rel="alternate" href="'.e(route('discover.record', $record->id)).'"/>'
                .'<link rel="alternate" type="application/ld+json" href="'.e(route('api.record', $record->id)).'"/>'
                .'<updated>'.$record->updated_at->toAtomString().'</updated>'
                .($summary !== '' ? '<summary>'.e(mb_substr($summary, 0, 1000)).'</summary>' : '').'</entry>';
        }

        return response($xml.'</feed>', 200, ['Content-Type' => 'application/atom+xml; charset=utf-8']);
    }

    private function catalogue(Request $request): PublicCatalogue
    {
        return $request->attributes->get('catalogue');
    }
}
