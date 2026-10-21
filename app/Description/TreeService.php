<?php

// OpenRiC Workspace - the hierarchy tree beside a unit of description (Heratio's TreeviewService and _treeview).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Heratio's sidebar treeview: the units above the current one, the current one,
// its first children with "more", or, when it has none, the units either side
// of it; further children load as a branch is opened. Here the order is the
// Workspace's arrangement (position, then id), and a unit appears only where
// the reader may read it: signed in, by the description read permission; in the
// public catalogue, when it is published.

namespace App\Description;

use App\Api\PublicCatalogue;
use App\Models\RecordResource;
use App\Models\User;
use Illuminate\Support\Collection;

class TreeService
{
    /** Units shown at once in a branch before "more", as Heratio's numberOfSiblings. */
    public const PAGE = 10;

    /** @param  ?PublicCatalogue  $public  the public catalogue, or null for a signed-in reader */
    public function __construct(private ?User $user = null, private ?PublicCatalogue $public = null) {}

    /** @return array{ancestors: list<array>, current: array, children: list<array>, more: bool, before: list<array>, after: list<array>, moreBefore: bool, moreAfter: bool} */
    public function window(RecordResource $unit): array
    {
        $ancestors = array_values(array_filter($unit->ancestors(), fn ($a) => $this->readable($a)));
        $children = $this->children($unit->id);
        $before = $after = [];
        $moreBefore = $moreAfter = false;
        if ($children['items'] === [] && $unit->parent_id !== null) {
            $siblings = $this->visibleChildren($unit->parent_id)->values();
            $at = $siblings->search(fn ($s) => $s->id === $unit->id);
            if ($at !== false) {
                $before = $siblings->slice(max(0, $at - self::PAGE), min($at, self::PAGE))->map(fn ($s) => $this->node($s))->values()->all();
                $after = $siblings->slice($at + 1, self::PAGE)->map(fn ($s) => $this->node($s))->values()->all();
                $moreBefore = $at > self::PAGE;
                $moreAfter = $siblings->count() > $at + 1 + self::PAGE;
            }
        }

        return ['ancestors' => array_map(fn ($a) => $this->node($a), $ancestors), 'current' => $this->node($unit), 'children' => $children['items'],
            'more' => $children['more'], 'before' => $before, 'after' => $after, 'moreBefore' => $moreBefore, 'moreAfter' => $moreAfter];
    }

    /** A branch, a page at a time (Heratio's getChildren). @return array{items: list<array>, more: bool} */
    public function children(string $parentId, int $offset = 0): array
    {
        $all = $this->visibleChildren($parentId)->values();

        return ['items' => $all->slice($offset, self::PAGE)->map(fn ($c) => $this->node($c))->values()->all(), 'more' => $all->count() > $offset + self::PAGE];
    }

    /** @return array{id: string, title: string, meta: string, url: string, hasChildren: bool} */
    public function node(RecordResource $unit): array
    {
        return [
            'id' => $unit->id,
            'title' => (string) ($this->public ? $this->public->view->label($unit) : $unit->described('title', [app()->getLocale(), 'en'])) ?: '[Untitled]',
            'meta' => collect([$unit->level?->label(['en', 'und']), $unit->identifier])->filter()->join(', '),
            'url' => $this->public ? route('discover.record', $unit->id) : route('records.show', $unit),
            'hasChildren' => $this->visibleChildren($unit->id)->isNotEmpty(),
        ];
    }

    /** @return Collection<int, RecordResource> */
    private function visibleChildren(string $parentId): Collection
    {
        return RecordResource::where('parent_id', $parentId)->whereNull('deleted_at')->with('names.nameType', 'names.state', 'level.labels', 'identifiers.identifierType')
            ->orderBy('position')->orderBy('id')->get()->filter(fn ($u) => $this->readable($u));
    }

    private function readable(RecordResource $unit): bool
    {
        return $this->public ? $this->public->view->readable($unit) : (bool) $this->user?->may('read', 'description', $unit);
    }
}
