<?php

// OpenRiC Workspace - a vocabulary an institution synchronises with a federation peer (Heratio's federation_vocab_sync; roadmap phase 16c).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['peer_id', 'vocabulary_id', 'remote_vocabulary', 'remote_title', 'direction_id', 'conflict_rule_id', 'sync_enabled', 'sync_interval_hours', 'last_sync_at', 'last_status_id', 'last_terms_added', 'last_terms_updated', 'last_conflicts'])]
class FederationVocabularySync extends Model
{
    use HasUuids;

    protected $table = 'federation_vocabulary_syncs';
}
