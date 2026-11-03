<?php

// OpenRiC Workspace - where a form template applies, after Heratio's ahg_form_assignment (roadmap phase 22b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['template_id', 'repository_id', 'level_id', 'collection_id', 'priority', 'inherit_to_children', 'is_active'])]
class FormAssignment extends Model
{
    use Audited, HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['inherit_to_children' => 'boolean', 'is_active' => 'boolean', 'priority' => 'integer'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(FormTemplate::class, 'template_id');
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class, 'repository_id');
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'level_id');
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'collection_id');
    }
}
