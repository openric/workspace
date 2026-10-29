<?php

// OpenRiC Workspace - an event of an accession (CAAIS 5.1, AtoM's accession event): type, date, agent, note.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'accession_id', 'event_type_id', 'date', 'agent', 'note', 'position'])]
class AccessionEvent extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function accession(): BelongsTo
    {
        return $this->belongsTo(Accession::class);
    }

    public function eventType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'event_type_id');
    }
}
