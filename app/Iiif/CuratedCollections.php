<?php

// OpenRiC Workspace - curated IIIF collections, after Heratio's IiifCollectionService (createCollection,
// updateCollection, deleteCollection, addItem, removeItem, reorderItems; roadmap phase 14a, ADR 0014). Each change is
// audited. An item is a record of the institution, another of its curated collections, or another institution's
// manifest or collection by its URI.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Iiif;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\IiifCollection;
use App\Models\IiifCollectionItem;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CuratedCollections
{
    public const BEHAVIORS = Vocab::BASE.'iiif-behavior/';

    public const RESOURCE_TYPES = Vocab::BASE.'iiif-resource-type/';

    /** @param array{label: string, summary?: ?string, language?: ?string, attribution?: ?string, behavior?: ?string, nav_date?: ?string, is_public?: bool, parent_id?: ?string} $data */
    public function save(Institution $institution, array $data, ?IiifCollection $collection, User $actor): IiifCollection
    {
        $fields = ['label' => trim($data['label']), 'summary' => $data['summary'] ?? null, 'language' => ($data['language'] ?? '') ?: 'en',
            'attribution' => $data['attribution'] ?? null, 'nav_date' => ($data['nav_date'] ?? '') ?: null, 'is_public' => (bool) ($data['is_public'] ?? false),
            'behavior_id' => ($data['behavior'] ?? '') ? Concept::where('iri', self::BEHAVIORS.$data['behavior'])->value('id') : null,
            'parent_id' => $this->parent($institution, $data['parent_id'] ?? null, $collection)?->id];

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $collection
            ? tap($collection)->update($fields)
            : IiifCollection::create([...$fields, 'institution_id' => $institution->id, 'created_by_id' => $actor->id,
                'position' => (int) IiifCollection::where('institution_id', $institution->id)->max('position') + 1])));
    }

    public function delete(IiifCollection $collection, User $actor): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($collection) {
            IiifCollection::where('parent_id', $collection->id)->update(['parent_id' => null]);
            $collection->delete();
        }));
    }

    /**
     * Add a record (by its persistent IRI or its id), another curated collection, or an outside manifest or collection
     * (an http URI and its type).
     */
    public function add(IiifCollection $collection, array $data, User $actor): IiifCollectionItem
    {
        $target = trim((string) ($data['target'] ?? ''));
        $label = trim((string) ($data['label'] ?? '')) ?: null;
        $fields = match (true) {
            ($data['member'] ?? '') !== '' => ['member_collection_id' => IiifCollection::where(['id' => $data['member'], 'institution_id' => $collection->institution_id])
                ->where('id', '!=', $collection->id)->value('id') ?? throw new InvalidArgumentException(__('Choose another curated collection of the institution.'))],
            ($record = RecordResource::where('institution_id', $collection->institution_id)->whereNull('deleted_at')
                ->where(fn ($q) => $q->where('iri', $target)->when(preg_match('/^[0-9a-f-]{36}$/', $target), fn ($q) => $q->orWhere('id', $target)))->first()) !== null => ['record_resource_id' => $record->id],
            preg_match('~^https?://\S+$~', $target) === 1 => ['uri' => $target, 'resource_type_id' => Concept::where('iri', self::RESOURCE_TYPES.($data['type'] ?? 'manifest'))->value('id')
                ?? throw new InvalidArgumentException(__('Say whether it is a manifest or a collection.'))],
            default => throw new InvalidArgumentException(__('Give a record of this institution by its persistent IRI, or another institution\'s IIIF manifest or collection by its URI.')),
        };

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => IiifCollectionItem::create([...$fields, 'iiif_collection_id' => $collection->id, 'label' => $label,
            'position' => (int) $collection->items()->max('position') + 1])));
    }

    /** Move an item one place up or down. */
    public function move(IiifCollectionItem $item, int $by, User $actor): void
    {
        $items = $item->collection->items()->get()->values();
        $from = $items->search(fn ($i) => $i->id === $item->id);
        $to = $from + $by;
        if ($to < 0 || $to >= $items->count()) {
            return;
        }
        $order = $items->all();
        [$order[$from], $order[$to]] = [$order[$to], $order[$from]];
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($order) {
            foreach ($order as $position => $each) {
                if ($each->position !== $position + 1) {
                    $each->update(['position' => $position + 1]);
                }
            }
        }));
    }

    public function remove(IiifCollectionItem $item, User $actor): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $item->delete()));
    }

    /** The collection above, of the same institution, never the collection itself or one inside it. */
    private function parent(Institution $institution, ?string $id, ?IiifCollection $collection): ?IiifCollection
    {
        if (! $id) {
            return null;
        }
        $parent = IiifCollection::where(['id' => $id, 'institution_id' => $institution->id])->first() ?? throw new InvalidArgumentException(__('Choose a curated collection of the institution.'));
        for ($above = $parent; $above !== null; $above = $above->parent) {
            if ($collection && $above->id === $collection->id) {
                throw new InvalidArgumentException(__('A collection cannot be inside itself or a collection inside it.'));
            }
        }

        return $parent;
    }
}
