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
