<?php

// OpenRiC Workspace - an authority record or place a mention may be, with its rank, name similarity, evidence and
// composite score (Heratio's ahg_mention_candidate; phase 17b2).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthorityCandidate extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['mention_id', 'rank', 'candidate_table', 'candidate_id', 'display_name', 'name_similarity', 'signals', 'evidence', 'composite', 'computed_at'];

    protected function casts(): array
    {
        return ['signals' => 'array', 'evidence' => 'array', 'name_similarity' => 'float', 'composite' => 'float', 'rank' => 'integer', 'computed_at' => 'immutable_datetime'];
    }

    public function mention(): BelongsTo
    {
        return $this->belongsTo(AuthorityMention::class, 'mention_id');
    }

    /** The agent or place itself. */
    public function entity(): ?Model
    {
        return ($this->candidate_table === 'agents' ? Agent::class : Place::class)::find($this->candidate_id);
    }
}
