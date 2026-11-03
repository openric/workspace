<?php

// OpenRiC Workspace - a field an institution adds to one kind of entity, after Heratio's custom_field_definition (roadmap phase 22b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'entity_table', 'field_key', 'label', 'field_type_id', 'vocabulary_id', 'field_group', 'is_required', 'is_searchable', 'is_visible_public', 'is_visible_edit', 'is_repeatable', 'include_in_export', 'default_value', 'help_text', 'validation_rule', 'sort_order', 'is_active'])]
class CustomFieldDefinition extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'is_searchable' => 'boolean', 'is_visible_public' => 'boolean', 'is_visible_edit' => 'boolean', 'is_repeatable' => 'boolean', 'include_in_export' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function fieldType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'field_type_id');
    }

    public function vocabulary(): BelongsTo
    {
        return $this->belongsTo(Vocabulary::class, 'vocabulary_id');
    }
}
