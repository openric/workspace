<?php

// OpenRiC Workspace - the clipboard: entries a reader gathers, saves under a clipboard ID, loads again and exports.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Heratio's ClipboardService (ahg-core), kept as it works: the items live in the
// session by type; save() keeps them under a unique seven-digit clipboard ID;
// load() merges or replaces from one; a signed-in reader's latest saved clipboard
// is loaded when the session's is empty; exportCsv() writes name, type, id and
// address. Changed for the Workspace: entries are records, authority records and
// repositories by id (Heratio: AtoM slugs), and only entries of the reader's
// institution that the reader may see are added, loaded or shown.

namespace App\Clipboard;

use App\Api\PublicCatalogue;
use App\Discovery\Links;
use App\Models\Agent;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\User;
use App\Relations\EntityKinds;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class ClipboardService
{
    /** Clipboard type => model, in the order the clipboard lists them. */
    public const TYPES = ['records' => RecordResource::class, 'agents' => Agent::class, 'repositories' => Repository::class];

    /** @return array<string, list<string>> type => ids, in the order they were added */
    public function items(?User $user): array
    {
        $items = Session::get('clipboard_items', []);
        if ($user && collect($items)->flatten()->isEmpty()) {
            $latest = DB::table('clipboard_saves')->where('user_id', $user->id)->orderByDesc('created_at')->value('id');
            if ($latest) {
                $items = $this->fromSave($latest);
                Session::put('clipboard_items', $items);
            }
        }

        return array_merge(array_fill_keys(array_keys(self::TYPES), []), $items);
    }

    public function has(string $type, string $id): bool
    {
        return in_array($id, Session::get('clipboard_items', [])[$type] ?? [], true);
    }

    public function add(string $type, string $id, ?User $user, Institution $institution): bool
    {
        if (! $this->visible($type, $id, $user, $institution)) {
            return false;
        }
        $items = $this->items($user);
        if (! in_array($id, $items[$type], true)) {
            $items[$type][] = $id;
        }
        Session::put('clipboard_items', $items);

        return true;
    }

    public function remove(string $type, string $id, ?User $user): void
    {
        $items = $this->items($user);
        $items[$type] = array_values(array_diff($items[$type] ?? [], [$id]));
        Session::put('clipboard_items', $items);
    }

    public function clear(?string $type = null): void
    {
        $items = Session::get('clipboard_items', []);
        Session::put('clipboard_items', $type ? [...$items, $type => []] : []);
    }

    public function count(): int
    {
        return collect(Session::get('clipboard_items', []))->sum(fn ($ids) => count($ids));
    }

    /** @return list<array{type: string, id: string, name: string, url: ?string, entity: Model}> the entries the reader may still see */
    public function details(?User $user, Institution $institution): array
    {
        $rows = [];
        foreach ($this->items($user) as $type => $ids) {
            foreach (self::TYPES[$type]::whereIn('id', $ids)->get()->sortBy(fn ($e) => array_search($e->id, $ids, true)) as $entity) {
                if ($this->visible($type, $entity->id, $user, $institution)) {
                    $rows[] = ['type' => $type, 'id' => $entity->id, 'name' => (string) EntityKinds::label($entity), 'entity' => $entity,
                        'url' => $user ? route($type.'.show', $entity) : Links::page($entity)];
                }
            }
        }

        return $rows;
    }

    /** Keep the clipboard under a new clipboard ID. @return string the ID */
    public function save(?User $user, Institution $institution): string
    {
        $rows = $this->details($user, $institution);
        if ($rows === []) {
            throw new \InvalidArgumentException('There is nothing on the clipboard to save.');
        }

        return DB::transaction(function () use ($rows, $user, $institution) {
            do {
                $password = (string) random_int(1000000, 9999999);
            } while (DB::table('clipboard_saves')->where('password', $password)->exists());
            $id = (string) Str::uuid7();
            DB::table('clipboard_saves')->insert(['id' => $id, 'institution_id' => $institution->id, 'user_id' => $user?->id, 'password' => $password, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('clipboard_save_items')->insert(array_map(fn ($r) => ['save_id' => $id, 'subject_table' => $r['type'], 'subject_id' => $r['id']], $rows));

            return $password;
        });
    }

    /** Load a saved clipboard, merged with this one or replacing it. @return int how many entries it brought */
    public function load(string $password, bool $replace, ?User $user, Institution $institution): int
    {
        $save = DB::table('clipboard_saves')->where(['password' => trim($password), 'institution_id' => $institution->id])->value('id')
            ?? throw new \InvalidArgumentException('No clipboard has that ID.');
        $items = $replace ? array_fill_keys(array_keys(self::TYPES), []) : $this->items($user);
        $added = 0;
        foreach ($this->fromSave($save) as $type => $ids) {
            foreach ($ids as $id) {
                if ($this->visible($type, $id, $user, $institution)) {
                    $added++;
                    if (! in_array($id, $items[$type], true)) {
                        $items[$type][] = $id;
                    }
                }
            }
        }
        Session::put('clipboard_items', $items);

        return $added;
    }

    public function exportCsv(?User $user, Institution $institution): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Name', 'Type', 'ID', 'URL'], escape: '');
        foreach ($this->details($user, $institution) as $row) {
            fputcsv($out, [$row['name'], $row['type'], $row['id'], $row['url']], escape: '');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** @return array<string, list<string>> */
    private function fromSave(string $saveId): array
    {
        $items = array_fill_keys(array_keys(self::TYPES), []);
        foreach (DB::table('clipboard_save_items')->where('save_id', $saveId)->get() as $item) {
            if (isset($items[$item->subject_table])) {
                $items[$item->subject_table][] = $item->subject_id;
            }
        }

        return $items;
    }

    /** May the reader see this entry: of the institution, and readable to them (the public: published and readable to Public). */
    private function visible(string $type, string $id, ?User $user, Institution $institution): bool
    {
        $model = self::TYPES[$type] ?? null;
        $entity = $model ? $model::find($id) : null;
        if ($entity === null || $entity->getAttribute('institution_id') !== $institution->id) {
            return false;
        }
        if ($user) {
            return $user->may('read', ['records' => 'description', 'agents' => 'authority', 'repositories' => 'repository'][$type], $entity);
        }

        return (new PublicCatalogue($institution))->find($type, $id) !== null;
    }
}
