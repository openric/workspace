<?php

// OpenRiC Workspace - an account's favourites and their folders (roadmap phase 19a, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's FavoritesService, FolderService and FavoritesExportService (ahg-favorites): keep an entity, or a
// link of one's own, with notes, in folders; move, clear and search them; share a folder by a link that expires (or
// does not, when it is public); export them as CSV, JSON, BibTeX, RIS, EndNote, CSL-JSON, EAD 3 or a page to print;
// import records by their IRIs. Changed: a favourite is any entity of the Workspace (Heratio's object types: record,
// authority record, repository, accession, term, function, physical object), shown only while its owner may read it;
// a shared folder shows a visitor only what the public may read; the link is shown once and kept as a hash.

namespace App\Research;

use App\Audit\AuditLog;
use App\Exchange\ExportView;
use App\Models\Concept;
use App\Models\Favourite;
use App\Models\FavouriteFolder;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class Favourites
{
    public const VISIBILITY = Vocab::BASE.'favourite-visibility/';

    public const SHARE_DAYS = 30;

    public const SHARE_DAYS_MAX = 365;

    /** Kinds with a page in the public catalogue, so a favourite of them links there. */
    private const PUBLIC_ROUTES = ['record' => 'discover.record', 'agent' => 'discover.agent', 'repository' => 'discover.repository',
        'place' => 'discover.place', 'activity' => 'discover.activity'];

    public function has(User $user, Model $entity): ?Favourite
    {
        return Favourite::where(['user_id' => $user->id, 'subject_table' => $entity->getTable(), 'subject_id' => $entity->getKey()])->first();
    }

    /** Keep the entity, or forget it when it is kept already. @return bool whether it is kept now */
    public function toggle(User $user, Model $entity, ?string $folderId = null): bool
    {
        if ($found = $this->has($user, $entity)) {
            DB::transaction(fn () => AuditLog::actingAs($user, fn () => $found->delete()));

            return false;
        }
        $this->add($user, $entity, $folderId);

        return true;
    }

    public function add(User $user, Model $entity, ?string $folderId = null, ?string $notes = null): Favourite
    {
        if ($entity->getAttribute('institution_id') !== $user->institution_id || ! EntityKinds::readable($user, $entity)) {
            throw new InvalidArgumentException(__('You may not keep that.'));
        }

        return $this->has($user, $entity) ?? DB::transaction(fn () => AuditLog::actingAs($user, fn () => Favourite::create([
            'institution_id' => $user->institution_id, 'user_id' => $user->id, 'folder_id' => $this->folderOf($user, $folderId)?->id,
            'subject_table' => $entity->getTable(), 'subject_id' => $entity->getKey(), 'notes' => $notes,
        ])));
    }

    /** A link of one's own (Heratio's custom favourite). */
    public function addLink(User $user, string $url, string $title, ?string $folderId = null): Favourite
    {
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $url) || trim($title) === '') {
            throw new InvalidArgumentException(__('Give a web address and a title.'));
        }

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => Favourite::create(['institution_id' => $user->institution_id, 'user_id' => $user->id,
            'folder_id' => $this->folderOf($user, $folderId)?->id, 'url' => $url, 'title' => trim($title)])));
    }

    /** The entity a favourite (or a collection item: anything with subject_table and subject_id) keeps, or null when it is a link or is gone. */
    public function entity(Model $favourite): ?Model
    {
        if ($favourite->subject_table === null) {
            return null;
        }
        $kind = EntityKinds::byTable($favourite->subject_table);
        $class = $kind ? EntityKinds::get($kind)['class'] : null;

        return $class ? $class::find($favourite->subject_id) : null;
    }

    /**
     * A favourite as its owner, or a view, may see it: its title, kind, address and whether it may still be read.
     *
     * @return array{favourite: Favourite, entity: ?Model, title: string, kind: string, url: ?string, readable: bool, reference: ?string}
     */
    public function shown(Model $favourite, ?User $user, ?ExportView $public = null): array
    {
        $entity = $this->entity($favourite);
        if ($entity === null) {
            return ['favourite' => $favourite, 'entity' => null, 'title' => (string) ($favourite->title ?? __('No longer in the catalogue')), 'kind' => $favourite->url ? 'link' : 'gone',
                'url' => $favourite->url, 'readable' => $favourite->url !== null, 'reference' => null];
        }
        $kind = EntityKinds::of($entity);
        $readable = $entity->getAttribute('deleted_at') === null && ($public ? $public->readable($entity) : ($user && EntityKinds::readable($user, $entity)));
        $route = isset(self::PUBLIC_ROUTES[$kind]) && ($public || ! $user?->may('update', EntityKinds::permission($entity), $entity)) ? route(self::PUBLIC_ROUTES[$kind], $entity->getKey()) : EntityKinds::route($entity);

        return ['favourite' => $favourite, 'entity' => $entity, 'kind' => $kind, 'readable' => $readable, 'url' => $readable ? $route : null,
            'title' => $readable ? ($public ? (string) $public->label($entity) : EntityKinds::label($entity, Vocab::languages())) : __('Not available to you'),
            'reference' => $readable && $entity instanceof RecordResource ? $entity->referenceCode() : null];
    }

    /**
     * An account's favourites: in a folder, the unfiled, or all; filtered by words in their titles and notes.
     *
     * @return Collection<int, array>
     */
    public function browse(User $user, ?string $folder = null, ?string $words = null, string $sort = 'added'): Collection
    {
        $query = Favourite::where('user_id', $user->id)->with('folder');
        if ($folder === 'unfiled') {
            $query->whereNull('folder_id');
        } elseif ($folder) {
            $query->where('folder_id', $this->folderOf($user, $folder)?->id);
        }
        $items = $query->orderByDesc('created_at')->get()->map(fn (Favourite $f) => $this->shown($f, $user));
        if (($words = trim((string) $words)) !== '') {
            $items = $items->filter(fn ($i) => Str::contains($i['title'].' '.$i['favourite']->notes.' '.$i['reference'], $words, true));
        }

        return (match ($sort) {
            'title' => $items->sortBy(fn ($i) => mb_strtolower($i['title'])),
            'reference' => $items->sortBy(fn ($i) => (string) $i['reference']),
            default => $items,
        })->values();
    }

    public function notes(User $user, Favourite $favourite, ?string $notes): void
    {
        $this->own($user, $favourite);
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $favourite->update(['notes' => trim((string) $notes) ?: null])));
    }

    /** @param  list<string>  $ids */
    public function remove(User $user, array $ids): int
    {
        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => Favourite::where('user_id', $user->id)->whereIn('id', $ids)->get()->each->delete()->count()));
    }

    /** @param  list<string>  $ids */
    public function move(User $user, array $ids, ?string $folderId): int
    {
        $folder = $this->folderOf($user, $folderId);

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => Favourite::where('user_id', $user->id)->whereIn('id', $ids)->get()
            ->each(fn ($f) => $f->update(['folder_id' => $folder?->id]))->count()));
    }

    public function clear(User $user): int
    {
        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => Favourite::where('user_id', $user->id)->get()->each->delete()->count()));
    }

    /** @return Collection<int, FavouriteFolder> with counts */
    public function folders(User $user): Collection
    {
        return FavouriteFolder::where('user_id', $user->id)->with('visibility')->withCount('favourites')->orderBy('position')->orderBy('name')->get();
    }

    public function saveFolder(User $user, array $data, ?FavouriteFolder $folder = null): FavouriteFolder
    {
        if ($folder) {
            $this->own($user, $folder);
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException(__('Give the folder a name.'));
        }
        $parent = $this->folderOf($user, $data['parent_id'] ?? null);
        for ($above = $parent; $folder && $above; $above = $above->parent) {
            if ($above->id === $folder->id) {
                throw new InvalidArgumentException(__('A folder cannot be inside itself.'));
            }
        }
        $fields = ['name' => $name, 'description' => trim((string) ($data['description'] ?? '')) ?: null, 'parent_id' => $parent?->id];

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => $folder ? tap($folder)->update($fields)
            : FavouriteFolder::create([...$fields, 'institution_id' => $user->institution_id, 'user_id' => $user->id, 'visibility_id' => $this->visibility('private'),
                'position' => (int) FavouriteFolder::where('user_id', $user->id)->max('position') + 1])));
    }

    /** Its favourites go back to the unfiled ones, as Heratio's deleteFolder does. */
    public function deleteFolder(User $user, FavouriteFolder $folder): void
    {
        $this->own($user, $folder);
        DB::transaction(fn () => AuditLog::actingAs($user, function () use ($folder) {
            Favourite::where('folder_id', $folder->id)->update(['folder_id' => null]);
            FavouriteFolder::where('parent_id', $folder->id)->update(['parent_id' => $folder->parent_id]);
            $folder->delete();
        }));
    }

    /** Share a folder by a link: for some days (shared), or with no end (public). @return string the link's token, shown once */
    public function share(User $user, FavouriteFolder $folder, ?int $days): string
    {
        $this->own($user, $folder);
        if ($days !== null && ($days < 1 || $days > self::SHARE_DAYS_MAX)) {
            throw new InvalidArgumentException(__('Share it for 1 to :max days, or with no end.', ['max' => self::SHARE_DAYS_MAX]));
        }
        $token = Str::random(48);
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $folder->update(['share_token_hash' => hash('sha256', $token),
            'share_expires_at' => $days ? now()->addDays($days) : null, 'visibility_id' => $this->visibility($days ? 'shared' : 'public'), 'share_access_count' => 0])));

        return $token;
    }

    public function unshare(User $user, FavouriteFolder $folder): void
    {
        $this->own($user, $folder);
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $folder->update(['share_token_hash' => null, 'share_expires_at' => null, 'visibility_id' => $this->visibility('private')])));
    }

    /** The folder a link opens, counting the visit; null when there is none or it has expired. */
    public function shared(string $token): ?FavouriteFolder
    {
        $folder = FavouriteFolder::where('share_token_hash', hash('sha256', $token))->where(fn ($q) => $q->whereNull('share_expires_at')->orWhere('share_expires_at', '>', now()))->first();
        $folder?->forceFill(['share_access_count' => $folder->share_access_count + 1, 'share_accessed_at' => now()])->saveQuietly();

        return $folder;
    }

    /** What a shared folder shows a visitor: only what the public may read. */
    public function sharedItems(FavouriteFolder $folder): Collection
    {
        $public = new ExportView(null, false, null, Institution::find($folder->institution_id));

        return $folder->favourites()->orderByDesc('created_at')->get()->map(fn ($f) => $this->shown($f, null, $public))->filter(fn ($i) => $i['readable'])->values();
    }

    /** Add records by their persistent IRIs, one a line (Heratio imports slugs). @return int how many were added */
    public function import(User $user, string $content, ?string $folderId = null): int
    {
        $added = 0;
        foreach (preg_split('/\R/', $content) as $line) {
            $iri = trim(str_getcsv($line)[0] ?? '');
            if ($iri === '' || ! str_contains($iri, '/')) {
                continue;
            }
            $record = RecordResource::where(['institution_id' => $user->institution_id, 'iri' => $iri])->whereNull('deleted_at')->first();
            if ($record && EntityKinds::readable($user, $record) && ! $this->has($user, $record)) {
                $this->add($user, $record, $folderId);
                $added++;
            }
        }

        return $added;
    }

    /** The records among favourites, for citations and EAD. @return list<RecordResource> */
    public function records(Collection $items): array
    {
        return $items->filter(fn ($i) => $i['readable'] && $i['entity'] instanceof RecordResource)->pluck('entity')->values()->all();
    }

    public function folderOf(User $user, ?string $id): ?FavouriteFolder
    {
        if ($id === null || $id === '') {
            return null;
        }

        return FavouriteFolder::where(['id' => $id, 'user_id' => $user->id])->first() ?? throw new InvalidArgumentException(__('Choose one of your folders.'));
    }

    private function own(User $user, Model $model): void
    {
        if ($model->getAttribute('user_id') !== $user->id) {
            throw new InvalidArgumentException(__('That is not yours.'));
        }
    }

    private function visibility(string $notation): string
    {
        return Concept::where('iri', self::VISIBILITY.$notation)->value('id');
    }
}
