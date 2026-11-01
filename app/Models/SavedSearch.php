<?php

// OpenRiC Workspace - a search kept to run again, with its results when last looked at and its alerts (Heratio's research_saved_search; roadmap phase 19c).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'user_id', 'name', 'description', 'search_query', 'search_filters', 'result_snapshot', 'total_results_at_save', 'last_result_count', 'last_run_at', 'alert_enabled', 'alert_frequency_id', 'last_alert_at', 'new_results_count', 'is_public', 'project_id'])]
class SavedSearch extends Model
{
    use Audited, HasUuids;

    protected $table = 'saved_searches';

    protected function casts(): array
    {
        return ['search_filters' => 'array', 'result_snapshot' => 'array', 'total_results_at_save' => 'integer', 'last_result_count' => 'integer', 'last_run_at' => 'immutable_datetime', 'alert_enabled' => 'boolean', 'last_alert_at' => 'immutable_datetime', 'new_results_count' => 'integer', 'is_public' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function alertFrequency(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'alert_frequency_id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(SearchAlertLogEntry::class, 'saved_search_id')->orderByDesc('created_at');
    }

    /** The research project it belongs to (phase 19f). */
    public function project(): BelongsTo
    {
        return $this->belongsTo(ResearchProject::class);
    }
}
