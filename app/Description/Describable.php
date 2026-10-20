<?php

// OpenRiC Workspace - an entity described by language values and names.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A name element of the description (title, authorised, parallel and other
// forms of name; config workspace.name_elements) reads its rico:Name
// entities; every other element reads its description values. Both carry a
// value, a language, whether it is the original, and a state.

namespace App\Description;

use App\Models\Concept;
use App\Models\DescriptionValue;
use App\Models\Name;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

trait Describable
{
    public function descriptionValues(): HasMany
    {
        return $this->hasMany(DescriptionValue::class, 'subject_id')->where('subject_table', $this->getTable());
    }

    public function names(): HasMany
    {
        return $this->hasMany(Name::class, 'subject_id')->where('subject_table', $this->getTable())->orderBy('created_at');
    }

    /** The names of one name type (name-type notation), in every language. */
    public function namesOfType(string $type): Collection
    {
        return $this->names->filter(fn (Name $n) => $n->nameType->iri === Vocab::NAME_TYPES.$type)->values();
    }

    /** The values of one element: names for a name element, description values for the rest. */
    public function valuesOf(Concept $element): Collection
    {
        $type = Elements::nameType($element->notation);

        return $type ? $this->namesOfType($type) : $this->descriptionValues->where('element_id', $element->id)->values();
    }

    /** The language the required element was first written in: where a description starts. */
    public function originalLanguage(string $requiredNotation): string
    {
        $original = $this->valuesByNotation($requiredNotation)->first(fn ($v) => $v->is_original);

        return $original?->language ?? 'en';
    }

    /** @return list<string> every language the description has a value or a name in */
    public function languages(): array
    {
        return $this->descriptionValues->pluck('language')->merge($this->names->pluck('language'))->unique()->sort()->values()->all();
    }

    /** The value of one element in the first of the given languages that has one. */
    public function described(string $elementNotation, array $languages): ?string
    {
        $values = $this->valuesByNotation($elementNotation)->keyBy('language');
        foreach ($languages as $language) {
            if (isset($values[$language])) {
                return $values[$language]->value;
            }
        }

        return $values->first()?->value;
    }

    private function valuesByNotation(string $notation): Collection
    {
        $type = Elements::nameType($notation);
        if ($type) {
            return $this->namesOfType($type);
        }
        $iri = Vocab::element($notation);

        return $this->descriptionValues->filter(fn (DescriptionValue $v) => $v->element->iri === $iri)->values();
    }
}
