<?php

// OpenRiC Workspace - a value of a field an institution added, after Heratio's custom_field_value (roadmap phase 22b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'definition_id', 'subject_table', 'subject_id', 'value_text', 'value_number', 'value_date', 'value_boolean', 'concept_id', 'sequence'])]
class CustomFieldValue extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['value_number' => 'float', 'value_date' => 'date', 'value_boolean' => 'boolean', 'sequence' => 'integer'];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(CustomFieldDefinition::class, 'definition_id');
    }

    public function concept(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'concept_id');
    }
}
