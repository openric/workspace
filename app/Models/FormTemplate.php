<?php

// OpenRiC Workspace - a form template: the fields a form shows for one kind of entity, after Heratio's ahg_form_template (roadmap phase 22b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'name', 'description', 'entity_table', 'is_default', 'is_active', 'version', 'created_by_id'])]
class FormTemplate extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean', 'version' => 'integer'];
    }

    public function fields(): HasMany
    {
        return $this->hasMany(FormTemplateField::class, 'template_id')->orderBy('sort_order');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(FormAssignment::class, 'template_id');
    }
}
