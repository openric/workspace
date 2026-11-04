<?php

// OpenRiC Workspace - the Dropdown manager (roadmap phase 20d), after Heratio's ahg-dropdown-manage: an institution's own
// take on the bundled vocabularies its forms offer. Every bundled vocabulary may have its terms relabelled in any
// language, put in another order and given a default, for this institution alone; the vocabularies open to it (those no
// code relies on and no standard fixes, config workspace.open_vocabularies) may also take terms of its own and have
// terms hidden. A bundled term keeps its IRI and its RiC-O mapping whatever an institution calls it, so what is exported
// does not change; a term of the institution's own may narrow a bundled one. A hidden term is no longer offered, but
// what already uses it keeps it.
// Changed from Heratio: the lists are the bundled SKOS vocabularies, not a table of codes; an institution changes them
// for itself, not for the installation; and its own terms are concepts with their own IRIs.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Vocabulary;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\User;
use App\Models\Vocabulary;
use App\Support\Vocab;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class Dropdowns
{
    /** @var array<string, array<string, object>> institution id => concept id => its overlay */
    private static array $overlays = [];

    /** The institution whose lists are being shown: the signed-in account's, else the public catalogue's. */
    public static function current(): ?string
    {
        if ($user = auth()->user()) {
            return $user->institution_id;
        }
        $institution = app()->bound('request') ? request()->attributes->get('institution') : null;

        return $institution instanceof Institution ? $institution->id : null;
    }

    /** @return array<string, object> concept id => overlay, for the institution */
    public static function overlays(?string $institutionId): array
    {
        if ($institutionId === null) {
            return [];
        }

        return self::$overlays[$institutionId] ??= DB::table('vocabulary_overlays')->where('institution_id', $institutionId)->get()
            ->mapWithKeys(fn ($o) => [$o->concept_id => (object) ['labels' => (array) json_decode($o->labels, true), 'sort_order' => $o->sort_order,
                'is_default' => (bool) $o->is_default, 'hidden' => (bool) $o->hidden]])->all();
    }

    /** The institution's own label for a concept, in the first of the languages it has one in. */
    public static function label(string $conceptId, array $languages): ?string
    {
        $labels = self::overlays(self::current())[$conceptId]->labels ?? [];
        foreach ($languages as $language) {
            if (($labels[$language] ?? '') !== '') {
                return $labels[$language];
            }
        }

        return null;
    }

    public static function flush(): void
    {
        self::$overlays = [];
    }

    public function isOpen(string $name): bool
    {
        return in_array($name, (array) config('workspace.open_vocabularies'), true);
    }

    /** @return Collection<int, Vocabulary> every bundled vocabulary, by title */
    public function vocabularies(): Collection
    {
        $names = array_map(fn ($f) => basename($f, '.ttl'), (array) config('workspace.bundled_vocabularies'));

        return Vocabulary::whereNull('institution_id')->whereIn('iri', array_map(fn ($n) => Vocab::BASE.$n.'/', $names))->get()
            ->sortBy(fn (Vocabulary $v) => mb_strtolower((string) $v->title))->values();
    }

    public static function name(Vocabulary $vocabulary): string
    {
        return rtrim(substr($vocabulary->iri, strlen(Vocab::BASE)), '/');
    }

    /**
     * A vocabulary's terms as the institution's forms offer them, with what it changed: hidden ones last.
     *
     * @return Collection<int, array{concept: Concept, overlay: ?object, own: bool}>
     */
    public function terms(Vocabulary $vocabulary, Institution $institution): Collection
    {
        $overlays = self::overlays($institution->id);

        return Concept::where('vocabulary_id', $vocabulary->id)->whereNull('deprecated_at')
            ->where(fn ($q) => $q->whereNull('institution_id')->orWhere('institution_id', $institution->id))->with('labels')->get()
            ->map(fn (Concept $c) => ['concept' => $c, 'overlay' => $overlays[$c->id] ?? null, 'own' => $c->institution_id !== null])
            ->sortBy(fn ($t) => [($t['overlay']->hidden ?? false) ? 1 : 0, ($t['overlay']->is_default ?? false) ? 0 : 1, $t['overlay']->sort_order ?? PHP_INT_MAX, $t['concept']->id])->values();
    }

    /** The institution's labels, order, default and (for an open vocabulary) hiding of each term it sends. */
    public function save(Vocabulary $vocabulary, Institution $institution, array $terms, ?string $default, User $actor): void
    {
        $open = $this->isOpen(self::name($vocabulary));
        $ids = Concept::where('vocabulary_id', $vocabulary->id)->where(fn ($q) => $q->whereNull('institution_id')->orWhere('institution_id', $institution->id))->pluck('id')->flip();
        AuditLog::actingAs($actor, fn () => DB::transaction(function () use ($terms, $default, $ids, $open, $institution, $actor) {
            foreach ($terms as $conceptId => $term) {
                if (! isset($ids[$conceptId])) {
                    continue;
                }
                $labels = array_filter(array_map(fn ($l) => Str::limit(trim((string) $l), 255, ''), (array) ($term['labels'] ?? [])), fn ($l) => $l !== '');
                $values = ['labels' => json_encode($labels, JSON_UNESCAPED_UNICODE), 'sort_order' => is_numeric($term['sort_order'] ?? null) ? (int) $term['sort_order'] : null,
                    'is_default' => $default === $conceptId, 'hidden' => $open && ! empty($term['hidden']) && $default !== $conceptId, 'updated_by_id' => $actor->id, 'updated_at' => now()];
                $empty = $labels === [] && $values['sort_order'] === null && ! $values['is_default'] && ! $values['hidden'];
                $empty ? DB::table('vocabulary_overlays')->where(['institution_id' => $institution->id, 'concept_id' => $conceptId])->delete()
                    : DB::table('vocabulary_overlays')->upsert([['institution_id' => $institution->id, 'concept_id' => $conceptId, 'created_at' => now()] + $values],
                        ['institution_id', 'concept_id'], array_keys($values));
            }
            // The overlays are rows of their own, not models, so the change is recorded here.
            AuditLog::record(Vocab::action('update'), $institution, null, ['dropdown' => array_keys($terms)], $actor, $institution->id);
        }));
        self::flush();
    }

    /** A term of the institution's own in an open vocabulary, perhaps narrowing a bundled one. */
    public function add(Vocabulary $vocabulary, Institution $institution, array $data, User $actor): Concept
    {
        $name = self::name($vocabulary);
        $this->isOpen($name) || throw new InvalidArgumentException(__('This list is fixed: the Workspace relies on its terms, or a standard does.'));
        $label = trim((string) ($data['label'] ?? ''));
        $label !== '' || throw new InvalidArgumentException(__('A term has a label.'));
        $language = (string) ($data['language'] ?? 'en');
        $notation = Str::slug((string) ($data['notation'] ?? '') ?: $label) ?: 'term';
        Concept::where(['vocabulary_id' => $vocabulary->id, 'notation' => $notation])->where(fn ($q) => $q->whereNull('institution_id')->orWhere('institution_id', $institution->id))
            ->exists() && throw new InvalidArgumentException(__('The list has a term with that code already.'));
        $broader = ($data['broader'] ?? null) ? Concept::where(['id' => $data['broader'], 'vocabulary_id' => $vocabulary->id])->whereNull('institution_id')->first() : null;

        return AuditLog::actingAs($actor, fn () => DB::transaction(function () use ($vocabulary, $institution, $name, $notation, $broader, $label, $language) {
            $concept = Concept::create(['vocabulary_id' => $vocabulary->id, 'institution_id' => $institution->id, 'notation' => $notation, 'broader_id' => $broader?->id,
                'iri' => rtrim($institution->base_iri, '/').'/vocab/'.$name.'/'.$notation]);
            $concept->labels()->create(['language' => $language, 'pref_label' => $label,
                'scope_note' => $broader ? __('The institution\'s own term, narrowing :term.', ['term' => $broader->iri]) : __('The institution\'s own term.')]);

            return $concept;
        }));
    }

    /** Take a term of the institution's own out of its forms: it is deprecated, so what uses it keeps it. */
    public function remove(Concept $concept, User $actor): void
    {
        $concept->institution_id === $actor->institution_id || throw new InvalidArgumentException(__('Only the institution\'s own terms are removed.'));
        AuditLog::actingAs($actor, fn () => $concept->update(['deprecated_at' => now()]));
    }
}
