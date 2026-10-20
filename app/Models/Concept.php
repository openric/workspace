<?php

// OpenRiC Workspace - one concept in a controlled vocabulary.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A concept is never deleted and its IRI never changes; the database enforces
// both (migration 2026_10_19_000100). Code that depends on a value compares
// the concept's IRI, never a label.

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Concept extends Model
{
    use HasUuids;

    protected $fillable = ['vocabulary_id', 'iri', 'notation', 'broader_id', 'deprecated_at', 'replaced_by_id'];

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

    /** Concepts that may be chosen for new data. */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('deprecated_at');
    }

    /** The current concepts of one vocabulary (its IRI), with their labels, in vocabulary order. */
    public function scopeChoices(Builder $query, string $vocabularyIri): Builder
    {
        return $query->whereNull('deprecated_at')->whereHas('vocabulary', fn ($q) => $q->where('iri', $vocabularyIri))->with('labels')->orderBy('id');
    }

    /** The label in the first of the given languages that has one, or null. */
    public function label(array $languages): ?string
    {
        $labels = $this->labels->keyBy('language');
        foreach ($languages as $language) {
            if (isset($labels[$language])) {
                return $labels[$language]->pref_label;
            }
        }

        return null;
    }
}
