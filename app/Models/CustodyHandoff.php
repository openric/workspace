<?php

// OpenRiC Workspace - a requested item passed from hand to hand (Heratio's research_custody_handoff; roadmap phase 19b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['material_request_id', 'handoff_type_id', 'from_handler_id', 'to_handler_id', 'from_location', 'to_location', 'condition_id', 'condition_notes', 'signature_confirmed', 'confirmed_at', 'confirmed_by_id', 'barcode_scanned', 'notes', 'created_at', 'created_by_id'])]
class CustodyHandoff extends Model
{
    use Audited, HasUuids;

    protected $table = 'custody_handoffs';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['signature_confirmed' => 'boolean', 'confirmed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    public function handoffType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'handoff_type_id');
    }

    public function condition(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'condition_id');
    }

    public function fromHandler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_handler_id');
    }

    public function toHandler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_handler_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
