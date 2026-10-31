<?php

// OpenRiC Workspace - IIIF Change Discovery 1.0 (roadmap phase 14a, ADR 0014): an Activity Streams ordered collection
// of the latest change to each manifest, oldest first, at /iiif/discovery/changes, so a harvester can follow it.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's IiifChangeDiscoveryService, which records each change in a table of its own. The Workspace already
// knows when each record or any part of it last changed (the datestamps OAI-PMH gives) and which published records
// were withdrawn (the deprecation register), so the stream is made from those: an Update for each record with a
// manifest, a Delete for each withdrawn one. That is level 1 of the specification: every manifest, its latest change.

namespace App\Iiif;

use App\Api\PublicCatalogue;
use App\Models\Deprecation;
use App\Models\RecordResource;
use Carbon\CarbonImmutable;

class ChangeDiscovery
{
    public const PAGE_SIZE = 100;

    private const CONTEXT = ['http://iiif.io/api/discovery/1/context.json', 'https://www.w3.org/ns/activitystreams'];

    public function __construct(private PublicCatalogue $catalogue) {}

    public function collection(): array
    {
        $total = count($this->activities());
        $last = max(1, (int) ceil($total / self::PAGE_SIZE));

        return ['@context' => self::CONTEXT, 'id' => route('iiif.changes'), 'type' => 'OrderedCollection', 'totalItems' => $total,
            'first' => ['id' => route('iiif.changes', ['page' => 1]), 'type' => 'OrderedCollectionPage'],
            'last' => ['id' => route('iiif.changes', ['page' => $last]), 'type' => 'OrderedCollectionPage']];
    }

    /** One page, or null when there is no such page. */
    public function page(int $page): ?array
    {
        $activities = $this->activities();
        $last = max(1, (int) ceil(count($activities) / self::PAGE_SIZE));
        if ($page < 1 || $page > $last) {
            return null;
        }
        $items = [];
        foreach (array_slice($activities, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE) as [$type, $manifest, $at]) {
            $items[] = ['type' => $type, 'object' => ['id' => $manifest, 'type' => 'Manifest'], 'endTime' => $at->format('Y-m-d\TH:i:s\Z')];
        }
        $doc = ['@context' => self::CONTEXT, 'id' => route('iiif.changes', ['page' => $page]), 'type' => 'OrderedCollectionPage',
            'partOf' => ['id' => route('iiif.changes'), 'type' => 'OrderedCollection']];
        if ($page > 1) {
            $doc['prev'] = ['id' => route('iiif.changes', ['page' => $page - 1]), 'type' => 'OrderedCollectionPage'];
        }
        if ($page < $last) {
            $doc['next'] = ['id' => route('iiif.changes', ['page' => $page + 1]), 'type' => 'OrderedCollectionPage'];
        }
        $doc['orderedItems'] = $items;

        return $doc;
    }

    /**
     * Every manifest's latest change, oldest first.
     * ponytail: made afresh on each request from the records a visitor may read; a large catalogue wants it kept.
     *
     * @return list<array{0: string, 1: string, 2: CarbonImmutable}>
     */
    private function activities(): array
    {
        $presentation = new Presentation($this->catalogue);
        $units = $this->catalogue->visible('records')->filter(fn (RecordResource $unit) => $presentation->hasManifest($unit));
        $activities = [];
        foreach ($this->catalogue->datestamps($units->pluck('id')->all()) as $id => $at) {
            $activities[] = ['Update', route('iiif.manifest', $id), $at];
        }
        $gone = Deprecation::where(['institution_id' => $this->catalogue->institution->id, 'subject_table' => 'record_resources', 'was_public' => true])->get();
        foreach ($gone as $deprecation) {
            $activities[] = ['Delete', route('iiif.manifest', $deprecation->subject_id), CarbonImmutable::parse($deprecation->deprecated_at)->utc()];
        }
        usort($activities, fn ($a, $b) => [$a[2]->getTimestamp(), $a[1]] <=> [$b[2]->getTimestamp(), $b[1]]);

        return $activities;
    }
}
