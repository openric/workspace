<?php

// OpenRiC Workspace - an entity described by language values.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Description;

use App\Models\DescriptionValue;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Relations\HasMany;

trait Describable
{
    public function descriptionValues(): HasMany
    {
        return $this->hasMany(DescriptionValue::class, 'subject_id')->where('subject_table', $this->getTable());
    }

    /** The language the required element was first written in: where a description starts. */
    public function originalLanguage(string $requiredNotation): string
    {
        $iri = Vocab::element($requiredNotation);
        $original = $this->descriptionValues->first(fn (DescriptionValue $v) => $v->element->iri === $iri && $v->is_original);

        return $original?->language ?? 'en';
    }

    /** @return list<string> every language the description has a value in */
    public function languages(): array
    {
        return $this->descriptionValues->pluck('language')->unique()->sort()->values()->all();
    }

    /** The value of one element in the first of the given languages that has one. */
    public function described(string $elementNotation, array $languages): ?string
    {
        $iri = Vocab::element($elementNotation);
        $values = $this->descriptionValues->filter(fn (DescriptionValue $v) => $v->element->iri === $iri)->keyBy('language');
        foreach ($languages as $language) {
            if (isset($values[$language])) {
                return $values[$language]->value;
            }
        }

        return $values->first()?->value;
    }
}
