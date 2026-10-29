<?php

// OpenRiC Workspace - the relation types: the bundled ones and an institution's own (migration 2026_10_29_000100).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// What config workspace.relation_types held, read from rows: for each type, the pairs of kinds it connects, whether
// it is symmetric, takes an agent role, is an access point or shown in a section of its own, the kinds of
// vocabulary a concept at either end comes from, and how it reads in each language. An institution's own type, as
// AtoM's and Heratio's relation type taxonomies allow, narrows a bundled type: it is keyed by its concept's UUID
// rather than a notation, reads in its own words and converse term, and takes everything else from the bundled
// type, whose notation is its kind (Relation::kind), so that EAD, MODS, EAC-CPF and the storage pages treat it
// as that type.

namespace App\Relations;

use App\Audit\AuditLog;
use App\Description\Bcp47;
use App\Models\Concept;
use App\Models\Relation;
use App\Models\RelationType;
use App\Models\RelationTypePhrase;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RelationTypes
{
    /** @var \WeakMap<object, array<string, array<string, array<string, mixed>>>>|null per request, by institution */
    private static ?\WeakMap $memo = null;

    /** Forget what was read, after a type changed. */
    public static function forget(): void
    {
        self::$memo = null;
    }

    /**
     * The bundled types, in the order they were listed, each followed by the institution's own types that narrow it.
     * Keyed by the bundled type's notation or the institution's type's concept UUID.
     *
     * @return array<string, array{key: string, concept_id: string, iri: string, kind: string, local: bool, base_concept_id: ?string,
     *     deprecated: bool, pairs: list<array{0: string, 1: string}>, symmetric: bool, role: bool, schemes: list<string>,
     *     access_point: bool, own_section: bool, record_set_term: ?string, phrases: array<string, array{forward: string, inverse: string}>,
     *     forward: string, inverse: string}>
     */
    public static function all(?string $institutionId): array
    {
        $request = request();
        self::$memo ??= new \WeakMap;
        $key = $institutionId ?? '';
        if (isset(self::$memo[$request][$key])) {
            return self::$memo[$request][$key];
        }

        $rows = DB::table('relation_types as t')->join('concepts as c', 'c.id', '=', 't.concept_id')
            ->where(fn ($q) => $q->whereNull('t.institution_id')->when($institutionId !== null, fn ($q) => $q->orWhere('t.institution_id', $institutionId)))
            ->get(['t.*', 'c.notation', 'c.iri', 'c.deprecated_at']);
        $ids = $rows->pluck('concept_id')->all();
        $pairs = DB::table('relation_type_pairs')->whereIn('relation_type_id', $ids)->orderBy('id')->get()->groupBy('relation_type_id');
        $schemes = DB::table('relation_type_schemes as s')->join('concepts as k', 'k.id', '=', 's.scheme_kind_id')
            ->whereIn('s.relation_type_id', $ids)->get(['s.relation_type_id', 'k.notation'])->groupBy('relation_type_id');
        $phrases = DB::table('relation_type_phrases')->whereIn('relation_type_id', $ids)->orderBy('created_at')->get()->groupBy('relation_type_id');
        $wording = fn (string $id) => ($phrases[$id] ?? collect())->mapWithKeys(fn ($p) => [$p->language => ['forward' => $p->forward, 'inverse' => $p->inverse]])->all();

        $bundled = [];
        foreach ($rows->whereNull('institution_id')->sortBy(fn ($t) => isset($pairs[$t->concept_id]) ? $pairs[$t->concept_id]->min('id') : PHP_INT_MAX) as $t) {
            $words = $wording($t->concept_id);
            $bundled[$t->concept_id] = ['key' => $t->notation, 'concept_id' => $t->concept_id, 'iri' => $t->iri, 'kind' => $t->notation, 'local' => false,
                'base_concept_id' => null, 'deprecated' => $t->deprecated_at !== null,
                'pairs' => ($pairs[$t->concept_id] ?? collect())->map(fn ($p) => [$p->source_kind, $p->target_kind])->values()->all(),
                'symmetric' => (bool) $t->self_reciprocal, 'role' => (bool) $t->role, 'schemes' => ($schemes[$t->concept_id] ?? collect())->pluck('notation')->all(),
                'access_point' => (bool) $t->access_point, 'own_section' => (bool) $t->own_section, 'record_set_term' => $t->record_set_term,
                'phrases' => $words, 'forward' => $words['en']['forward'] ?? (string) $t->notation, 'inverse' => $words['en']['inverse'] ?? (string) $t->notation];
        }
        $local = $rows->whereNotNull('institution_id')->groupBy('base_id');
        $types = [];
        foreach ($bundled as $id => $type) {
            $types[$type['key']] = $type;
            foreach ($local[$id] ?? [] as $t) {
                $words = $wording($t->concept_id);
                $english = $words['en'] ?? (reset($words) ?: ['forward' => $type['forward'], 'inverse' => $type['inverse']]);
                $types[$t->concept_id] = ['key' => $t->concept_id, 'concept_id' => $t->concept_id, 'iri' => $t->iri, 'local' => true, 'base_concept_id' => $id,
                    'deprecated' => $t->deprecated_at !== null, 'phrases' => $words, 'forward' => $english['forward'], 'inverse' => $english['inverse']] + $type;
            }
        }
        $sets = self::$memo[$request] ?? [];
        $sets[$key] = $types;
        self::$memo[$request] = $sets;

        return $types;
    }

    /** A type by its key (a bundled notation, or an institution's type's UUID), or null. */
    public static function get(string $key, ?string $institutionId): ?array
    {
        return self::all($institutionId)[$key] ?? null;
    }

    /** The relation's type. */
    public static function of(Relation $relation): ?array
    {
        foreach (self::all($relation->institution_id) as $type) {
            if ($type['concept_id'] === $relation->relation_type_id) {
                return $type;
            }
        }

        return null;
    }

    /** How the type reads from its source (forward) or its target, in the interface language, else English, else any. */
    public static function phrase(array $type, bool $forward): string
    {
        $side = $forward ? 'forward' : 'inverse';
        foreach (Vocab::languages() as $language) {
            if (isset($type['phrases'][$language])) {
                return $type['phrases'][$language][$side];
            }
        }

        return $type[$side];
    }

    /** The bundled types an institution's own type may narrow: all but storage and location, which have pages of their own. */
    public static function narrowable(): array
    {
        return array_filter(self::all(null), fn (array $type) => ! $type['own_section'] && ! $type['deprecated']);
    }

    /**
     * Make a concept of one of the institution's relation-type vocabularies a relation type narrowing the bundled
     * type $base, or change its wording in a language: forward, how it reads from its source, and inverse, its
     * converse term (the forward wording again when the bundled type is symmetric, Heratio's self-reciprocal).
     * Its bundled type is kept once relations are of it, since what they connect follows from it.
     */
    public function define(Concept $concept, string $base, string $language, string $forward, ?string $inverse, ?User $actor = null): RelationType
    {
        $concept->loadMissing('vocabulary.kind');
        if ($concept->institution_id === null || $concept->vocabulary?->kind?->notation !== 'relation-type') {
            throw new InvalidArgumentException(__('a relation type of the institution is a concept of one of its relation-type vocabularies'));
        }
        $bundled = self::narrowable()[$base] ?? throw new InvalidArgumentException(__('choose the bundled relation type it narrows'));
        if (! Bcp47::valid($language)) {
            throw new InvalidArgumentException(__('give the language as a BCP 47 tag, such as en or af'));
        }
        $words = fn (?string $value) => ($value = trim((string) $value)) === '' || preg_match('/\R/', $value) || mb_strlen($value) > 255 ? null : $value;
        $forward = $words($forward) ?? throw new InvalidArgumentException(__('say how the relation reads, in one line'));
        $inverse = $bundled['symmetric'] ? $forward : ($words($inverse) ?? throw new InvalidArgumentException(__('give the converse term, in one line')));
        $existing = RelationType::find($concept->id);
        if ($existing && $existing->base_id !== $bundled['concept_id'] && Relation::where('relation_type_id', $concept->id)->exists()) {
            throw new InvalidArgumentException(__('relations are of this type; the bundled type it narrows is kept'));
        }

        $type = DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($concept, $bundled, $language, $forward, $inverse, $existing) {
            $type = $existing ? tap($existing)->update(['base_id' => $bundled['concept_id']])
                : RelationType::create(['concept_id' => $concept->id, 'institution_id' => $concept->institution_id, 'base_id' => $bundled['concept_id']]);
            if ($bundled['symmetric']) {
                // A symmetric type reads the same both ways in every language.
                $type->phrases()->whereColumn('forward', '<>', 'inverse')->get()->each(fn (RelationTypePhrase $p) => $p->update(['inverse' => $p->forward]));
            }
            RelationTypePhrase::updateOrCreate(['relation_type_id' => $concept->id, 'language' => $language],
                ['institution_id' => $concept->institution_id, 'forward' => $forward, 'inverse' => $inverse]);

            return $type;
        }));
        self::forget();

        return $type;
    }
}
