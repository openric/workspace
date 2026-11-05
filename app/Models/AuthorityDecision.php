<?php

// OpenRiC Workspace - what an archivist decided about a mention, with every candidate and its evidence as they saw
// them; never changed (Heratio's ahg_mention_decision; phase 17b2).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthorityDecision extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['institution_id', 'mention_id', 'decision_id', 'chosen_table', 'chosen_id', 'top_score', 'reason', 'user_id', 'decided_at',
        'evidence_snapshot', 'candidates_snapshot'];

    protected function casts(): array
    {
        return ['evidence_snapshot' => 'array', 'candidates_snapshot' => 'array', 'top_score' => 'float', 'decided_at' => 'immutable_datetime'];
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'decision_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mention(): BelongsTo
    {
        return $this->belongsTo(AuthorityMention::class, 'mention_id');
    }
}
