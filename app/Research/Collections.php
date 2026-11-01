<?php

// OpenRiC Workspace - a researcher's collections (roadmap phase 19d, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ResearchCollectionsController and ResearchService (createCollection, addToCollection,
// removeFromCollection, getCollectionFindingAidData) and research_collection with research_collection_item: named
// sets of records, with notes, tags, a reference code and an order for each item, added from the record's page or by
// IRI, shared by a link, and printed as a finding aid. Changed: an item is any entity of the Workspace (Heratio's
// object_type), or an outside address (Heratio's external_uri); it is shown only while its owner may read it, and a
// shared collection shows a visitor only what the public may read; the link is shown once and kept as a hash.

namespace App\Research;

use App\Audit\AuditLog;
use App\Exchange\ExportView;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\ResearchCollection;
use App\Models\ResearchCollectionItem;
use App\Models\User;
use App\Relations\EntityKinds;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class Collections
{
    public function __construct(private Favourites $favourites, private Diary $diary) {}

    public function save(User $user, array $data, ?ResearchCollection $collection = null): ResearchCollection
    {
        if ($collection) {
            $this->mustOwn($user, $collection);
        }
        if (trim((string) ($data['name'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give the collection a name.'));
        }
        $fields = ['name' => trim($data['name']), 'description' => trim((string) ($data['description'] ?? '')) ?: null];

        return DB::transaction(fn () => AuditLog::actingAs($user, function () use ($user, $collection, $fields) {
            if ($collection) {
                $collection->update($fields);

                return $collection;
            }
            $collection = ResearchCollection::create($fields + ['institution_id' => $user->institution_id, 'user_id' => $user->id]);
            $this->diary->auto($user, 'auto-collection', __('Made the collection ":name"', ['name' => $collection->name]), $collection);

            return $collection;
        }));
    }

    public function delete(User $user, ResearchCollection $collection): void
    {
        $this->mustOwn($user, $collection);
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $collection->delete()));
    }

    /** Add an entity its owner may read; once only. */
    public function add(User $user, ResearchCollection $collection, Model $entity, ?string $notes = null): ResearchCollectionItem
    {
        $this->mustOwn($user, $collection);
        if ($entity->getAttribute('institution_id') !== $user->institution_id || ! EntityKinds::readable($user, $entity)) {
            throw new InvalidArgumentException(__('You may not keep that.'));
        }
        $found = $collection->items()->where(['subject_table' => $entity->getTable(), 'subject_id' => $entity->getKey()])->first();
        if ($found) {
            return $found;
        }

        return DB::transaction(fn () => AuditLog::actingAs($user, function () use ($user, $collection, $entity, $notes) {
            $item = $collection->items()->create(['subject_table' => $entity->getTable(), 'subject_id' => $entity->getKey(), 'notes' => trim((string) $notes) ?: null,
                'reference_code' => $entity instanceof RecordResource ? $entity->referenceCode() : null, 'sort_order' => (int) $collection->items()->max('sort_order') + 1]);
            $this->diary->auto($user, 'auto-collection', __('Added ":title" to the collection ":name"', ['title' => EntityKinds::label($entity), 'name' => $collection->name]), $collection);

            return $item;
        }));
    }

    /** An outside address, as Heratio's external_uri. */
    public function addLink(User $user, ResearchCollection $collection, string $url, string $title, ?string $notes = null): ResearchCollectionItem
    {
        $this->mustOwn($user, $collection);
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $url) || trim($title) === '') {
            throw new InvalidArgumentException(__('Give a web address and a title.'));
        }

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => $collection->items()->create(['external_uri' => $url, 'title' => trim($title),
            'notes' => trim((string) $notes) ?: null, 'sort_order' => (int) $collection->items()->max('sort_order') + 1])));
    }

    /** Add records by their persistent IRIs, one a line. @return int how many were added */
    public function import(User $user, ResearchCollection $collection, string $content): int
    {
        $added = 0;
        foreach (preg_split('/\R/', $content) as $line) {
            $iri = trim(str_getcsv($line)[0] ?? '');
            $record = $iri === '' ? null : RecordResource::where(['institution_id' => $user->institution_id, 'iri' => $iri])->whereNull('deleted_at')->first();
            if ($record && EntityKinds::readable($user, $record) && ! $collection->items()->where(['subject_table' => 'record_resources', 'subject_id' => $record->id])->exists()) {
                $this->add($user, $collection, $record);
                $added++;
            }
        }

        return $added;
    }

    public function updateItem(User $user, ResearchCollectionItem $item, array $data): void
    {
        $this->mustOwn($user, $item->collection);
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $item->update(['notes' => trim((string) ($data['notes'] ?? '')) ?: null, 'tags' => Diary::tags($data['tags'] ?? null),
            'reference_code' => trim((string) ($data['reference_code'] ?? '')) ?: null, 'sort_order' => (int) ($data['sort_order'] ?? $item->sort_order)])));
    }

    public function removeItem(User $user, ResearchCollectionItem $item): void
    {
        $this->mustOwn($user, $item->collection);
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $item->delete()));
    }

    /** Share by a link, or stop sharing; the link is returned once. */
    public function share(User $user, ResearchCollection $collection, bool $on): ?string
    {
        $this->mustOwn($user, $collection);
        $token = $on ? Str::random(48) : null;
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $collection->update(['is_public' => $on, 'share_token_hash' => $token ? hash('sha256', $token) : null])));

        return $token;
    }

    public function shared(string $token): ?ResearchCollection
    {
        return ResearchCollection::where(['share_token_hash' => hash('sha256', $token), 'is_public' => true])->first();
    }

    /** The items as their owner sees them. @return Collection<int, array> */
    public function items(User $user, ResearchCollection $collection): Collection
    {
        return $collection->items()->get()->map(fn ($item) => $this->favourites->shown($item, $user) + ['item' => $item]);
    }

    /** The items a visitor of the link may see: what the public may read. */
    public function sharedItems(ResearchCollection $collection): Collection
    {
        $public = new ExportView(null, false, null, Institution::find($collection->institution_id));

        return $collection->items()->get()->map(fn ($item) => $this->favourites->shown($item, null, $public) + ['item' => $item])->filter(fn ($i) => $i['readable'])->values();
    }

    /**
     * Heratio's finding aid of a collection: each record with its reference code, title, dates, extent and scope and content, as its owner may read it.
     *
     * @return list<array>
     */
    public function findingAid(User $user, ResearchCollection $collection): array
    {
        $this->mustOwn($user, $collection);
        $view = new ExportView($user, $user->mayAnywhere('view-draft', 'description'), null, Institution::find($collection->institution_id));
        $citations = app(Citations::class);

        return $this->items($user, $collection)->filter(fn ($i) => $i['readable'] && $i['entity'] instanceof RecordResource)
            ->map(fn ($i) => $citations->facts($view, $i['entity']) + ['scope' => $view->value($i['entity'], 'scope-and-content'), 'notes' => $i['item']->notes])->values()->all();
    }

    /** One's collections, for the choice on an entity's page. */
    public function of(User $user): Collection
    {
        return ResearchCollection::where('user_id', $user->id)->orderBy('name')->get();
    }

    public function mustOwn(User $user, ResearchCollection $collection): void
    {
        if ($collection->user_id !== $user->id) {
            throw new InvalidArgumentException(__('That is not yours.'));
        }
    }
}
