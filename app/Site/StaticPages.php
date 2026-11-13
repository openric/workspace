<?php

// OpenRiC Workspace - the institution's static pages (roadmap phase 20a), after Heratio's ahg-static-page: about,
// privacy, terms and any other, each by its slug, written in Markdown in each language it is translated into and shown
// in the visitor's language where it has one, else in the language it was first written in.
// Changed from Heratio: each institution has its own pages; any HTML in the text is stripped when it is shown, where
// Heratio cleans it as it is saved.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Site;

use App\Audit\AuditLog;
use App\Models\Institution;
use App\Models\StaticPage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class StaticPages
{
    /** The pages Heratio serves at their own addresses, linked from the foot of every public page that has them. */
    public const NAMED = ['about', 'privacy', 'terms'];

    /** A slug: lower-case letters, digits and hyphens. */
    public const SLUG = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    public function find(Institution $institution, string $slug): ?StaticPage
    {
        return StaticPage::where(['institution_id' => $institution->id, 'slug' => $slug])->with('translations')->first();
    }

    /** Write the page in a language: a new page, or a new translation of one, or a change to one. */
    public function save(Institution $institution, array $data, string $language, User $actor, ?StaticPage $page = null): StaticPage
    {
        $slug = Str::lower(trim((string) ($data['slug'] ?? $page?->slug ?? '')));
        preg_match(self::SLUG, $slug) || throw new InvalidArgumentException(__('A page\'s address is lower-case letters, digits and hyphens.'));
        StaticPage::where(['institution_id' => $institution->id, 'slug' => $slug])->when($page, fn ($q) => $q->whereKeyNot($page->id))->exists()
            && throw new InvalidArgumentException(__('Another page has that address.'));
        $title = trim((string) ($data['title'] ?? ''));
        $title !== '' || throw new InvalidArgumentException(__('A page has a title.'));

        return AuditLog::actingAs($actor, fn () => DB::transaction(function () use ($institution, $slug, $language, $title, $data, $actor, $page) {
            $new = $page === null;
            $page ??= StaticPage::create(['institution_id' => $institution->id, 'slug' => $slug, 'source_language' => $language, 'created_by_id' => $actor->id]);
            // A place in the footer (phase 26): a number orders the footer's links; none leaves the page out of it.
            $position = array_key_exists('footer_position', $data) ? (($data['footer_position'] ?? '') === '' || $data['footer_position'] === null ? null : (int) $data['footer_position']) : $page->footer_position;
            // About, privacy and terms, which the footer always listed, start in it in that order; their place can be cleared later.
            if ($new && $position === null && in_array($slug, self::NAMED, true)) {
                $position = array_search($slug, self::NAMED, true) + 1;
            }
            $page->update(['slug' => $slug, 'footer_position' => $position]);
            $page->translations()->updateOrCreate(['language' => $language], ['title' => $title, 'content' => trim((string) ($data['content'] ?? '')) ?: null]);
            $page->touch();

            return $page->fresh('translations');
        }));
    }

    public function delete(StaticPage $page, User $actor): void
    {
        AuditLog::actingAs($actor, fn () => $page->delete());
    }

    /** The pages the institution has given a place in its footer, in that order (phase 26; about, privacy and terms to start with). */
    public function footer(Institution $institution): array
    {
        return StaticPage::where('institution_id', $institution->id)->whereNotNull('footer_position')->with('translations')->orderBy('footer_position')->orderBy('slug')->get()->all();
    }
}
