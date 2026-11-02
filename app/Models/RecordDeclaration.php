<?php

// OpenRiC Workspace - the declaration of a unit of description as a record (roadmap phase 13c, ADR 0015).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['record_resource_id', 'status_id', 'requested_by_id', 'requested_at', 'declared_by_id', 'declared_at', 'notes'])]
class RecordDeclaration extends Model
{
    use Audited, HasUuids;

    protected $table = 'record_declarations';

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'declared_at' => 'datetime'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function declaredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declared_by_id');
    }
}
