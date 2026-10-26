<?php

// OpenRiC Workspace - the institution's own vocabularies and their concepts (ADR 0006, decision 6).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The functions of Heratio's ahg-term-taxonomy (add, edit, use-for labels,
// scope, source and display notes, broader and narrower terms, related
// terms, matches to other vocabularies, move to another taxonomy, merge,
// delete) were read from it; the code is the Workspace's. Unlike Heratio, a
// concept is never deleted: it is deprecated, and a merged concept points to
// the one that replaced it, so every IRI and every old assertion stays
// readable. A concept's labels and notes are per language; each change to
// them, its links and matches is audited on the concept.

namespace App\Vocabulary;

use App\Audit\AuditLog;
use App\Description\Bcp47;
use App\Models\Concept;
use App\Models\ConceptAltLabel;
use App\Models\ConceptLabel;
use App\Models\ConceptMatch;
use App\Models\Institution;
use App\Models\Place;
use App\Models\Relation;
use App\Models\User;
use App\Models\Vocabulary;
use App\Relations\RelationService;
use App\Support\Vocab;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ConceptService
{
    /** The notes a concept has in each language, as columns of concept_labels. */
    public const NOTES = ['definition', 'scope_note', 'history_note', 'source_note', 'display_note'];

    public function createScheme(Institution $institution, string $kind, string $title, string $language, ?string $description = null, ?User $actor = null): Vocabulary
    {
        $kindConcept = $this->concept(Vocab::SCHEME_KINDS.$kind);
        $title = $this->line($title, 'a vocabulary has a title of one line');

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($institution, $kindConcept, $title, $language, $description) {
            $id = (string) Str::uuid7();
            $vocabulary = new Vocabulary(['iri' => $institution->base_iri.'/'.Vocabulary::IRI_SEGMENT.'/'.$id, 'title' => $title,
                'institution_id' => $institution->id, 'kind_id' => $kindConcept->id, 'language' => $this->language($language),
                'description' => $this->text($description)]);
            $vocabulary->id = $id;
            $vocabulary->save();

            return $vocabulary;
        }));
    }

    public function updateScheme(Vocabulary $vocabulary, string $title, string $language, ?string $description, ?User $actor = null): void
    {
        $this->local($vocabulary);
        $title = $this->line($title, 'a vocabulary has a title of one line');
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $vocabulary->update([
            'title' => $title, 'language' => $this->language($language), 'description' => $this->text($description),
        ])));
    }

    /** Delete a vocabulary that has no concepts; one with concepts keeps them, and their IRIs, for good. */
    public function deleteScheme(Vocabulary $vocabulary, ?User $actor = null): void
    {
        $this->local($vocabulary);
        if ($vocabulary->concepts()->exists()) {
            throw new InvalidArgumentException(__('the vocabulary has concepts; it is kept, so their IRIs keep resolving. Deprecate or move its concepts instead.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $vocabulary->delete()));
    }

    /** The institution's first vocabulary of a kind, made (titled after the kind) when it has none. */
    public function schemeOfKind(Institution $institution, string $kind, ?User $actor = null): Vocabulary
    {
        $kindConcept = $this->concept(Vocab::SCHEME_KINDS.$kind);

        return Vocabulary::where(['institution_id' => $institution->id, 'kind_id' => $kindConcept->id])->orderBy('id')->first()
            ?? $this->createScheme($institution, $kind, $kindConcept->label(['en']) ?? $kind, 'en', null, $actor);
    }

    /**
     * Fields: label and language (required); notation; broader (a concept id of the vocabulary);
     * the notes (NOTES); alt_labels and hidden_labels, one per line; narrower, new narrower
     * concepts one per line, in the same language.
     */
    public function create(Vocabulary $vocabulary, array $fields, ?User $actor = null): Concept
    {
        $this->local($vocabulary);
        $language = $this->language((string) ($fields['language'] ?? ''));
        $label = $this->line((string) ($fields['label'] ?? ''), 'a concept has a preferred label of one line');

        return $this->guarded(fn () => AuditLog::actingAs($actor, function () use ($vocabulary, $fields, $language, $label) {
            $concept = $this->newConcept($vocabulary, $this->notation($fields['notation'] ?? null), $this->broader($vocabulary, $fields['broader'] ?? null));
            ConceptLabel::create(['concept_id' => $concept->id, 'language' => $language, 'pref_label' => $label] + $this->notes($fields));
            $this->writeAltLabels($concept, $language, $fields);
            foreach ($this->lines($fields['narrower'] ?? '') as $narrower) {
                $child = $this->newConcept($vocabulary, null, $concept->id);
                ConceptLabel::create(['concept_id' => $child->id, 'language' => $language, 'pref_label' => $narrower]);
                $this->audit($child, [], $this->snapshot($child));
            }
            $this->audit($concept, [], $this->snapshot($concept));

            return $concept;
        }));
    }

    /**
     * What the concept says in one language: its preferred label (empty removes the language,
     * unless it is the only one), notes and alternative labels; and its notation, broader
     * concept and new narrower concepts, which are not per language.
     */
    public function update(Concept $concept, string $language, array $fields, ?User $actor = null): void
    {
        $this->local($concept->vocabulary);
        $language = $this->language($language);
        $label = trim((string) ($fields['label'] ?? ''));
        $this->guarded(fn () => AuditLog::actingAs($actor, function () use ($concept, $language, $fields, $label) {
            $before = $this->snapshot($concept);
            if ($label === '') {
                if (ConceptLabel::where('concept_id', $concept->id)->where('language', '<>', $language)->doesntExist()) {
                    throw new InvalidArgumentException(__('a concept keeps a preferred label in at least one language'));
                }
                ConceptLabel::where(['concept_id' => $concept->id, 'language' => $language])->delete();
                ConceptAltLabel::where(['concept_id' => $concept->id, 'language' => $language])->delete();
            } else {
                $row = ['pref_label' => $this->line($label, 'a concept has a preferred label of one line')] + $this->notes($fields);
                ConceptLabel::where(['concept_id' => $concept->id, 'language' => $language])->exists()
                    ? ConceptLabel::where(['concept_id' => $concept->id, 'language' => $language])->update($row)
                    : ConceptLabel::create(['concept_id' => $concept->id, 'language' => $language] + $row);
                $this->writeAltLabels($concept, $language, $fields);
            }
            $concept->fill(['notation' => $this->notation($fields['notation'] ?? null), 'broader_id' => $this->broader($concept->vocabulary, $fields['broader'] ?? null)]);
            $concept->save();
            foreach ($this->lines($fields['narrower'] ?? '') as $narrower) {
                $child = $this->newConcept($concept->vocabulary, null, $concept->id);
                ConceptLabel::create(['concept_id' => $child->id, 'language' => $language, 'pref_label' => $narrower]);
                $this->audit($child, [], $this->snapshot($child));
            }
            $this->audit($concept, $before, $this->snapshot($concept));
        }));
    }

    /** skos:related, both ways; both concepts of the institution's own vocabularies. */
    public function relate(Concept $concept, Concept $other, ?User $actor = null): void
    {
        if ($concept->id === $other->id || $concept->institution_id === null || $other->institution_id !== $concept->institution_id) {
            throw new InvalidArgumentException(__('a concept is related to another concept of the institution'));
        }
        [$a, $b] = strcmp($concept->id, $other->id) < 0 ? [$concept->id, $other->id] : [$other->id, $concept->id];
        $this->changing($concept, $actor, fn () => DB::table('concept_related')->insertOrIgnore(['concept_id' => $a, 'related_id' => $b, 'created_at' => now(), 'updated_at' => now()]));
    }

    public function unrelate(Concept $concept, Concept $other, ?User $actor = null): void
    {
        [$a, $b] = strcmp($concept->id, $other->id) < 0 ? [$concept->id, $other->id] : [$other->id, $concept->id];
        $this->changing($concept, $actor, fn () => DB::table('concept_related')->where(['concept_id' => $a, 'related_id' => $b])->delete());
    }

    /** A match (concept-match-type notation) to a concept in another vocabulary, by its IRI. */
    public function addMatch(Concept $concept, string $type, string $iri, ?string $label = null, ?string $scheme = null, ?User $actor = null): void
    {
        $this->local($concept->vocabulary);
        $iri = trim($iri);
        if (! preg_match('#^https?://\S+$#', $iri)) {
            throw new InvalidArgumentException(__('a match is the http or https IRI of a concept in another vocabulary'));
        }
        $typeId = $this->concept(Vocab::MATCH_TYPES.$type)->id;
        $this->guarded(fn () => $this->changing($concept, $actor, fn () => ConceptMatch::create(['concept_id' => $concept->id, 'match_type_id' => $typeId,
            'target_iri' => $iri, 'target_label' => $this->text($label), 'target_scheme' => $this->text($scheme)])), 'the concept already has a match to that IRI');
    }

    public function removeMatch(ConceptMatch $match, ?User $actor = null): void
    {
        $concept = Concept::findOrFail($match->concept_id);
        $this->changing($concept, $actor, fn () => $match->delete());
    }

    /** Move the concept and every concept below it to another vocabulary of the same kind; it leaves its broader concept. */
    public function move(Concept $concept, Vocabulary $target, ?User $actor = null): int
    {
        $this->local($target);
        $from = $concept->vocabulary;
        if ($target->institution_id !== $from->institution_id || $target->kind_id !== $from->kind_id || $target->id === $from->id) {
            throw new InvalidArgumentException(__('a concept moves to another vocabulary of the same kind in the institution'));
        }

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($concept, $target) {
            $moved = 0;
            // The concept first, without its broader concept, then each level below it.
            $concept->update(['vocabulary_id' => $target->id, 'broader_id' => null]);
            $level = [$concept->id];
            while ($level !== []) {
                $moved += count($level);
                $children = Concept::whereIn('broader_id', $level)->get();
                $children->each(fn (Concept $c) => $c->update(['vocabulary_id' => $target->id]));
                $level = $children->pluck('id')->all();
            }

            return $moved;
        }));
    }

    /**
     * Merge a duplicate into the concept kept: its narrower concepts, relations, places
     * typed by it, related concepts and matches move to the kept concept; its labels become
     * the kept concept's alternative labels; it is deprecated and replaced by the kept one.
     * A relation the kept concept already has is not made twice; the duplicate's is removed.
     */
    public function merge(Concept $duplicate, Concept $kept, ?User $actor = null): void
    {
        if ($duplicate->id === $kept->id || $duplicate->vocabulary_id !== $kept->vocabulary_id || $kept->deprecated_at !== null) {
            throw new InvalidArgumentException(__('a concept merges into another current concept of the same vocabulary'));
        }
        $this->local($kept->vocabulary);
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($duplicate, $kept) {
            $before = $this->snapshot($kept);
            Concept::where('broader_id', $duplicate->id)->get()->each(fn (Concept $c) => $c->update(['broader_id' => $kept->id]));
            Place::where('place_type_id', $duplicate->id)->get()->each(fn (Place $p) => $p->update(['place_type_id' => $kept->id]));
            foreach (['source', 'target'] as $side) {
                foreach (Relation::where([$side.'_table' => 'concepts', $side.'_id' => $duplicate->id])->get() as $relation) {
                    $other = $side === 'source' ? ['target_table' => $relation->target_table, 'target_id' => $relation->target_id] : ['source_table' => $relation->source_table, 'source_id' => $relation->source_id];
                    $twin = Relation::where(['relation_type_id' => $relation->relation_type_id, $side.'_table' => 'concepts', $side.'_id' => $kept->id] + $other)->exists();
                    $twin ? app(RelationService::class)->remove($relation) : $relation->update([$side.'_id' => $kept->id]);
                }
            }
            foreach ($duplicate->related() as $related) {
                $this->unlink($duplicate, $related);
                if ($related->id !== $kept->id) {
                    $this->link($kept, $related);
                }
            }
            foreach (ConceptMatch::where('concept_id', $duplicate->id)->get() as $match) {
                ConceptMatch::where('concept_id', $kept->id)->where('target_iri', $match->target_iri)->exists()
                    ? $match->delete() : $match->update(['concept_id' => $kept->id]);
            }
            $keptPreferred = ConceptLabel::where('concept_id', $kept->id)->pluck('pref_label', 'language');
            $labels = ConceptLabel::where('concept_id', $duplicate->id)->get(['language', 'pref_label'])->map(fn ($l) => [$l->language, $l->pref_label, false])
                ->merge(ConceptAltLabel::where('concept_id', $duplicate->id)->get()->map(fn ($l) => [$l->language, $l->label, $l->is_hidden]));
            foreach ($labels as [$language, $label, $hidden]) {
                if (($keptPreferred[$language] ?? null) !== $label) {
                    ConceptAltLabel::firstOrCreate(['concept_id' => $kept->id, 'language' => $language, 'label' => $label], ['is_hidden' => $hidden]);
                }
            }
            $duplicate->update(['deprecated_at' => now(), 'replaced_by_id' => $kept->id, 'broader_id' => null]);
            $this->audit($kept, $before, $this->snapshot($kept));
        }));
    }

    /** No longer offered for new data. A concept with current narrower concepts is not deprecated until they are moved or deprecated. */
    public function deprecate(Concept $concept, ?User $actor = null): void
    {
        $this->local($concept->vocabulary);
        if (Concept::where('broader_id', $concept->id)->whereNull('deprecated_at')->exists()) {
            throw new InvalidArgumentException(__('the concept has narrower concepts; move or deprecate them first, or merge it into another concept'));
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $concept->update(['deprecated_at' => now()])));
    }

    public function restore(Concept $concept, ?User $actor = null): void
    {
        $this->local($concept->vocabulary);
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $concept->update(['deprecated_at' => null, 'replaced_by_id' => null])));
    }

    /** @return array{relations: int, places: int} how much refers to the concept */
    public function usage(Concept $concept): array
    {
        return [
            'relations' => Relation::where(fn ($q) => $q->where(['source_table' => 'concepts', 'source_id' => $concept->id])
                ->orWhere(fn ($q) => $q->where(['target_table' => 'concepts', 'target_id' => $concept->id])))->count(),
            'places' => Place::where('place_type_id', $concept->id)->whereNull('deleted_at')->count(),
        ];
    }

    /** Labels, alternative labels, related concepts and matches: what the concept row itself does not hold. */
    public function snapshot(Concept $concept): array
    {
        return [
            'labels' => ConceptLabel::where('concept_id', $concept->id)->orderBy('language')->get(['language', 'pref_label', ...self::NOTES])
                ->map(fn ($l) => array_filter($l->only(['language', 'pref_label', ...self::NOTES]), fn ($v) => $v !== null))->all(),
            'alt_labels' => ConceptAltLabel::where('concept_id', $concept->id)->orderBy('language')->orderBy('label')->get()
                ->map(fn ($l) => ['language' => $l->language, 'label' => $l->label, 'hidden' => $l->is_hidden])->all(),
            'related' => $concept->related()->pluck('iri')->sort()->values()->all(),
            'matches' => ConceptMatch::where('concept_id', $concept->id)->orderBy('target_iri')->with('matchType')->get()
                ->map(fn ($m) => ['type' => $m->matchType->notation, 'iri' => $m->target_iri])->all(),
        ];
    }

    private function newConcept(Vocabulary $vocabulary, ?string $notation, ?string $broaderId): Concept
    {
        $institution = Institution::findOrFail($vocabulary->institution_id);
        $id = (string) Str::uuid7();
        $concept = new Concept(['vocabulary_id' => $vocabulary->id, 'institution_id' => $institution->id,
            'iri' => $institution->base_iri.'/'.Concept::IRI_SEGMENT.'/'.$id, 'notation' => $notation, 'broader_id' => $broaderId]);
        $concept->id = $id;
        $concept->save();

        return $concept;
    }

    /** The alternative (alt_labels) and hidden (hidden_labels) labels of one language, one per line, replace those it had; an absent field is empty. */
    private function writeAltLabels(Concept $concept, string $language, array $fields): void
    {
        // Neither field given: the labels stay as they are.
        if (! array_key_exists('alt_labels', $fields) && ! array_key_exists('hidden_labels', $fields)) {
            return;
        }
        $wanted = [];
        foreach (['alt_labels' => false, 'hidden_labels' => true] as $field => $hidden) {
            foreach ($this->lines($fields[$field] ?? '') as $line) {
                $wanted[$line] = $hidden;
            }
        }
        ConceptAltLabel::where(['concept_id' => $concept->id, 'language' => $language])->whereNotIn('label', array_keys($wanted))->delete();
        foreach ($wanted as $label => $hidden) {
            ConceptAltLabel::updateOrCreate(['concept_id' => $concept->id, 'language' => $language, 'label' => (string) $label], ['is_hidden' => $hidden]);
        }
    }

    private function link(Concept $a, Concept $b): void
    {
        [$x, $y] = strcmp($a->id, $b->id) < 0 ? [$a->id, $b->id] : [$b->id, $a->id];
        DB::table('concept_related')->insertOrIgnore(['concept_id' => $x, 'related_id' => $y, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function unlink(Concept $a, Concept $b): void
    {
        [$x, $y] = strcmp($a->id, $b->id) < 0 ? [$a->id, $b->id] : [$b->id, $a->id];
        DB::table('concept_related')->where(['concept_id' => $x, 'related_id' => $y])->delete();
    }

    /** Run a change to what the concept says outside its own row, audited as an update of the concept. */
    private function changing(Concept $concept, ?User $actor, callable $change): mixed
    {
        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($concept, $change) {
            $before = $this->snapshot($concept);
            $result = $change();
            $this->audit($concept, $before, $this->snapshot($concept));

            return $result;
        }));
    }

    private function audit(Concept $concept, array $before, array $after): void
    {
        $changed = array_keys(array_filter($after, fn ($value, $key) => ($before[$key] ?? []) !== $value, ARRAY_FILTER_USE_BOTH));
        if ($changed !== []) {
            AuditLog::record(Vocab::action('update'), $concept, array_intersect_key($before, array_flip($changed)) ?: null, array_intersect_key($after, array_flip($changed)));
        }
    }

    private function notes(array $fields): array
    {
        $notes = [];
        foreach (self::NOTES as $note) {
            $notes[$note] = $this->text($fields[$note] ?? null);
        }

        return $notes;
    }

    private function broader(Vocabulary $vocabulary, ?string $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        return Concept::where(['id' => $id, 'vocabulary_id' => $vocabulary->id])->value('id')
            ?? throw new InvalidArgumentException(__('a broader concept is a concept of the same vocabulary'));
    }

    private function notation(?string $notation): ?string
    {
        $notation = trim((string) $notation);

        return $notation === '' ? null : $this->line($notation, 'a code is one line');
    }

    /** @return list<string> the distinct non-empty lines */
    private function lines(?string $text): array
    {
        $lines = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', (string) $text)), fn ($l) => $l !== '')));
        foreach ($lines as $line) {
            $this->line($line, 'each label is at most 1024 characters');
        }

        return $lines;
    }

    private function line(string $value, string $message): string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/\R/', $value) || mb_strlen($value) > 1024) {
            throw new InvalidArgumentException($message);
        }

        return $value;
    }

    private function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function language(string $language): string
    {
        return Bcp47::valid($language) ? $language : throw new InvalidArgumentException(__('give the language as a BCP 47 tag, such as en or af'));
    }

    private function local(Vocabulary $vocabulary): void
    {
        if ($vocabulary->institution_id === null) {
            throw new InvalidArgumentException(__('a bundled or published vocabulary is changed only by importing it'));
        }
    }

    private function guarded(callable $write, string $message = 'a concept of this vocabulary already has that code, or the concept already has that label'): mixed
    {
        try {
            return DB::transaction($write);
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException($message);
        }
    }

    private function concept(string $iri): Concept
    {
        return Concept::where('iri', $iri)->whereNull('deprecated_at')->first()
            ?? throw new InvalidArgumentException("$iri is not a current concept");
    }
}
