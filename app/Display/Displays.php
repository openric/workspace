<?php

// OpenRiC Workspace - display profiles and browse settings (roadmap phase 20d), after Heratio's ahg-display
// DisplayService and DisplayController: the profile a unit of description is shown to the public under (its level's,
// else the institution's default, else none, which shows everything as before), the elements it shows in order and
// under the institution's labels, what a reader may do from the page; and the browse settings an account lists units
// with (its own where the institution allows them, else the institution's, else the Workspace's).
// Changed from Heratio: the archival part only, with no GLAM collection types; a profile is chosen by level, not
// assigned to each object; and the settings are vocabulary terms.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Display;

use App\Audit\AuditLog;
use App\Description\Elements;
use App\Models\BrowseSetting;
use App\Models\Concept;
use App\Models\DisplayProfile;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Displays
{
    /** What the browse lists with when nobody has set anything: Heratio's defaults, but the Workspace's 50 a page. */
    public const DEFAULTS = ['view' => 'list', 'sort' => 'title', 'direction' => 'ascending', 'per_page' => 50, 'show_thumbnails' => true];

    /** @var array<string, ?DisplayProfile> institution and level => its profile, for the request */
    private array $profiles = [];

    public function profileFor(RecordResource $unit): ?DisplayProfile
    {
        return $this->profiles[$unit->institution_id.'|'.$unit->level_id] ??= DisplayProfile::where('institution_id', $unit->institution_id)
            ->where(fn ($q) => $q->where('level_id', $unit->level_id)->orWhereNull('level_id'))->with('actions', 'thumbnailSize')
            ->orderByRaw('level_id IS NULL')->first();
    }

    /**
     * The ISAD(G) elements a profile shows, in its order; with no profile, or one that names none, every element.
     *
     * @return Collection<string, Collection<int, Concept>> area => its elements
     */
    public function byArea(?DisplayProfile $profile): Collection
    {
        $areas = collect(Elements::byArea(Elements::ISAD_G))->map(fn ($elements) => collect($elements));
        $chosen = array_values((array) ($profile?->elements ?? []));
        if ($chosen === []) {
            return $areas;
        }
        $at = array_flip($chosen);

        return $areas->map(fn ($elements) => $elements->filter(fn ($e) => isset($at[$e->notation]))->sortBy(fn ($e) => $at[$e->notation])->values())
            ->filter(fn ($elements) => $elements->isNotEmpty());
    }

    /** The element's label under the profile, in the first of the languages the institution gave one in, else its own. */
    public function label(?DisplayProfile $profile, Concept $element, array $languages): string
    {
        foreach ($languages as $language) {
            if (($label = trim((string) ($profile?->labels[$element->notation][$language] ?? ''))) !== '') {
                return $label;
            }
        }

        return (string) $element->label($languages);
    }

    /** May the reader do this from the page? With no profile, everything the page offers. */
    public function allows(?DisplayProfile $profile, string $action): bool
    {
        return $profile === null || $profile->actions->contains('notation', $action);
    }

    /** The width an image is shown at under the profile, or null for the page's own; 0 shows none. */
    public function thumbnailWidth(?DisplayProfile $profile): ?int
    {
        return match ($profile?->thumbnailSize?->notation) {
            'none' => 0, 'small' => 100, 'medium' => 200, 'large' => 400, default => null,
        };
    }

    /** Save a profile: its name, level, elements in order, labels, tree, image size and actions. */
    public function saveProfile(Institution $institution, ?DisplayProfile $profile, array $data, User $actor): DisplayProfile
    {
        $known = Elements::notations(Elements::ISAD_G);
        $elements = array_values(array_unique(array_filter(array_map('strval', (array) ($data['elements'] ?? [])), fn ($n) => in_array($n, $known, true))));
        $labels = collect((array) ($data['labels'] ?? []))->only($known)
            ->map(fn ($byLanguage) => array_filter(array_map(fn ($l) => mb_substr(trim((string) $l), 0, 255), (array) $byLanguage), fn ($l) => $l !== ''))
            ->filter()->all();
        $level = ($data['level'] ?? '') !== '' ? (Concept::notated(Vocab::BASE.'level-of-description/', (string) $data['level'])
            ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $data['level']]))) : null;
        $clash = DisplayProfile::where('institution_id', $institution->id)->when($profile, fn ($q) => $q->whereKeyNot($profile->id))
            ->when($level, fn ($q) => $q->where('level_id', $level->id), fn ($q) => $q->whereNull('level_id'))->exists();
        $clash && throw new InvalidArgumentException($level ? __('That level has a profile already.') : __('The institution has a default profile already.'));
        $size = ($data['thumbnail_size'] ?? '') !== '' ? Concept::where('iri', Vocab::BASE.'thumbnail-size/'.$data['thumbnail_size'])->value('id') : null;
        $actions = Concept::choices(Vocab::BASE.'display-action/')->whereIn('notation', (array) ($data['actions'] ?? []))->pluck('id');

        return AuditLog::actingAs($actor, fn () => DB::transaction(function () use ($institution, $profile, $data, $level, $elements, $labels, $size, $actions, $actor) {
            $profile ??= new DisplayProfile(['institution_id' => $institution->id]);
            $profile->fill(['name' => trim((string) $data['name']), 'level_id' => $level?->id, 'elements' => $elements, 'labels' => $labels,
                'show_tree' => ! empty($data['show_tree']), 'thumbnail_size_id' => $size, 'updated_by_id' => $actor->id])->save();
            $profile->actions()->sync($actions);
            $this->profiles = [];

            return $profile;
        }));
    }

    public function deleteProfile(DisplayProfile $profile, User $actor): void
    {
        AuditLog::actingAs($actor, fn () => $profile->delete());
        $this->profiles = [];
    }

    /**
     * The settings the account browses with, as notations and numbers, and whether it may set its own.
     *
     * @return array{view: string, sort: string, direction: string, per_page: int, show_thumbnails: bool, own: bool, may_override: bool}
     */
    public function browse(User $user): array
    {
        $rows = BrowseSetting::where('institution_id', $user->institution_id)->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $user->id))
            ->with('view', 'sort', 'direction')->get();
        $institution = $rows->firstWhere('user_id', null);
        $mayOverride = $institution?->allow_user_override ?? true;
        $row = ($mayOverride ? $rows->firstWhere('user_id', $user->id) : null) ?? $institution;

        return $this->settings($row) + ['may_override' => $mayOverride];
    }

    /** The institution's own browse settings, whatever its accounts chose. */
    public function institutionBrowse(string $institutionId): array
    {
        $row = BrowseSetting::where('institution_id', $institutionId)->whereNull('user_id')->with('view', 'sort', 'direction')->first();

        return $this->settings($row) + ['may_override' => $row?->allow_user_override ?? true];
    }

    private function settings(?BrowseSetting $row): array
    {
        return $row === null ? self::DEFAULTS + ['own' => false] : ['view' => $row->view->notation, 'sort' => $row->sort->notation,
            'direction' => $row->direction->notation, 'per_page' => $row->per_page, 'show_thumbnails' => $row->show_thumbnails, 'own' => $row->user_id !== null];
    }

    /** Save the institution's browse settings (no user) or an account's own. */
    public function saveBrowse(Institution $institution, ?User $user, array $data, User $actor): BrowseSetting
    {
        $user && ! $this->browse($user)['may_override'] && throw new InvalidArgumentException(__('The institution sets how its staff browse.'));
        $term = fn (string $vocabulary, string $key) => Concept::where('iri', Vocab::BASE.$vocabulary.'/'.($data[$key] ?? self::DEFAULTS[$key]))->value('id')
            ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $data[$key] ?? '']));
        $values = ['view_id' => $term('browse-view', 'view'), 'sort_id' => $term('browse-sort', 'sort'), 'direction_id' => $term('sort-direction', 'direction'),
            'per_page' => max(10, min(200, (int) ($data['per_page'] ?? 50))), 'show_thumbnails' => ! empty($data['show_thumbnails'])]
            + ($user ? [] : ['allow_user_override' => ! empty($data['allow_user_override'])]);

        return AuditLog::actingAs($actor, fn () => BrowseSetting::updateOrCreate(['institution_id' => $institution->id, 'user_id' => $user?->id], $values));
    }

    /** An account goes back to the institution's settings. */
    public function resetBrowse(User $user): void
    {
        AuditLog::actingAs($user, fn () => BrowseSetting::where('user_id', $user->id)->get()->each->delete());
    }
}
