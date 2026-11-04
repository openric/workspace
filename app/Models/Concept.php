<?php

// OpenRiC Workspace - one concept in a controlled vocabulary.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A concept is never deleted and its IRI never changes; the database enforces
// both (migration 2026_10_19_000100). Code that depends on a value compares
// the concept's IRI, never a label. A concept of an institution's own
// vocabulary (institution_id set) is an entity of that institution: its
// changes are audited, and relations may point at it (ADR 0006).

namespace App\Models;

use App\Audit\Audited;
use App\Support\Vocab;
use App\Vocabulary\Dropdowns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Concept extends Model
{
    use Audited, HasUuids;

    /** The URL path segment of a local concept's persistent IRI: {base IRI}/concept/{UUID}. */
    public const IRI_SEGMENT = 'concept';

    protected $fillable = ['vocabulary_id', 'institution_id', 'iri', 'notation', 'broader_id', 'deprecated_at', 'replaced_by_id'];

    /** Only an institution's own concepts; bundled vocabularies are loaded by the importer. */
    public function isAudited(): bool
    {
        return $this->institution_id !== null;
    }

    protected function casts(): array
    {
        return ['deprecated_at' => 'immutable_datetime'];
    }

    public function vocabulary(): BelongsTo
    {
        return $this->belongsTo(Vocabulary::class);
    }

    public function broader(): BelongsTo
    {
        return $this->belongsTo(self::class, 'broader_id');
    }

    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }

    public function labels(): HasMany
    {
        return $this->hasMany(ConceptLabel::class);
    }

    public function narrower(): HasMany
    {
        return $this->hasMany(self::class, 'broader_id');
    }

    public function altLabels(): HasMany
    {
        return $this->hasMany(ConceptAltLabel::class)->orderBy('language')->orderBy('label');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(ConceptMatch::class)->orderBy('target_iri');
    }

    /** @return Collection<int, self> the related concepts (skos:related), kept once for each pair */
    public function related(): Collection
    {
        $ids = DB::table('concept_related')->where('concept_id', $this->id)->pluck('related_id')
            ->merge(DB::table('concept_related')->where('related_id', $this->id)->pluck('concept_id'));

        return self::whereKey($ids)->with('labels', 'vocabulary')->get();
    }

    /** @return list<self> the broader concepts, the broadest first */
    public function ancestors(): array
    {
        $chain = [];
        for ($concept = $this->broader; $concept !== null; $concept = $concept->broader) {
            array_unshift($chain, $concept);
        }

        return $chain;
    }

    /** Concepts that may be chosen for new data. */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('deprecated_at');
    }

    /**
     * A vocabulary's current concepts as a form offers them: the bundled ones and the institution's own, in vocabulary
     * order, or as the institution ordered them in the Dropdown manager, its default first and the terms it hid left
     * out (phase 20d). The institution is the signed-in account's, else the public catalogue's; with none, the
     * vocabulary as it was bundled. Subqueries rather than a join, so a caller's own conditions stay unambiguous.
     */
    public function scopeChoices(Builder $query, string $vocabularyIri): Builder
    {
        $institution = Dropdowns::current();

        return $query->whereNull('concepts.deprecated_at')->whereHas('vocabulary', fn ($q) => $q->where('iri', $vocabularyIri))
            ->where(fn ($q) => $q->whereNull('concepts.institution_id')->when($institution, fn ($q) => $q->orWhere('concepts.institution_id', $institution)))
            ->when($institution, fn ($q) => $q
                ->whereNotIn('concepts.id', fn ($s) => $s->select('concept_id')->from('vocabulary_overlays')->where(['institution_id' => $institution, 'hidden' => true]))
                ->orderByRaw('(SELECT o.is_default FROM vocabulary_overlays o WHERE o.concept_id = concepts.id AND o.institution_id = ?) IS TRUE DESC', [$institution])
                ->orderByRaw('(SELECT o.sort_order FROM vocabulary_overlays o WHERE o.concept_id = concepts.id AND o.institution_id = ?) ASC NULLS LAST', [$institution]))
            ->with('labels')->orderBy('concepts.id');
    }

    /**
     * The term a form chose by its code: the bundled one, else, in a list the Dropdown manager opened, a current term of
     * the institution's own (phase 20d). What resolves a code from a form into an open list goes through here.
     */
    public static function notated(string $vocabularyIri, string $notation): ?self
    {
        $institution = Dropdowns::current();

        return static::where('iri', $vocabularyIri.$notation)->first() ?? ($institution === null ? null : static::where(['institution_id' => $institution, 'notation' => $notation])
            ->whereNull('deprecated_at')->whereHas('vocabulary', fn ($q) => $q->whereNull('institution_id')->where('iri', $vocabularyIri))->first());
    }

    /**
     * The current concepts of a bundled vocabulary, in vocabulary order, then those of the institution's own
     * vocabularies of a kind, by label: what a field takes when an institution may add to a bundled list.
     */
    public static function bundledThenLocal(string $vocabularyIri, string $kind, ?string $institutionId): Collection
    {
        $local = $institutionId === null ? collect() : static::where('institution_id', $institutionId)->whereNull('deprecated_at')
            ->whereHas('vocabulary.kind', fn ($q) => $q->where('iri', Vocab::SCHEME_KINDS.$kind))->with('labels', 'vocabulary')->get()
            ->sortBy(fn (Concept $c) => mb_strtolower($c->anyLabel(['en'])))->values();

        return static::choices($vocabularyIri)->with('vocabulary')->get()->merge($local);
    }

    /** The label in the first of the given languages that has one, or null. */
    public function label(array $languages): ?string
    {
        // The institution's own label for it, where it gave one in the Dropdown manager (phase 20d).
        if (($own = Dropdowns::label($this->id, $languages)) !== null) {
            return $own;
        }
        $labels = $this->labels->keyBy('language');
        foreach ($languages as $language) {
            if (isset($labels[$language])) {
                return $labels[$language]->pref_label;
            }
        }

        return null;
    }

    /**
     * The RiC-O (or OpenRiC extension) term what this concept stands for is kept or exported as,
     * such as rico:scopeAndContent, from the scope note of a Workspace vocabulary: the term after
     * "exported as" or "kept as", else the first term named. Null when the note names none.
     */
    public function ricTerm(): ?string
    {
        $note = (string) ($this->labels->firstWhere('language', 'en') ?? $this->labels->first(fn ($l) => $l->scope_note !== null))?->scope_note;

        return preg_match('/(?:exported|kept) as (?:an? |the )?((?:rico|openricx):\w+)/i', $note, $m)
            || preg_match('/((?:rico|openricx):\w+)/', $note, $m) ? $m[1] : null;
    }

    /** The label in the first of the given languages that has one, else any label, else the notation. */
    public function anyLabel(array $languages): string
    {
        return $this->label($languages) ?? $this->labels->first()?->pref_label ?? (string) $this->notation;
    }
}
