<?php

// OpenRiC Workspace - one normalisation or migration of a file, with what it was made from and into, by which
// tool, and how it ended (Heratio's preservation_format_conversion; roadmap phase 15c).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'instantiation_id', 'source_file_id', 'output_file_id', 'rule_id', 'pathway_id', 'migration_plan_id', 'purpose_id', 'source_format', 'source_mime_type', 'target_format', 'target_mime_type', 'tool_id', 'tool_version', 'status_id', 'source_size', 'source_checksum', 'output_size', 'output_checksum', 'options', 'started_at', 'completed_at', 'duration_ms', 'error_message', 'created_by_id'])]
class FormatConversion extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['options' => 'array', 'source_size' => 'integer', 'output_size' => 'integer', 'duration_ms' => 'integer', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function tool(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'tool_id');
    }

    public function purpose(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'purpose_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(NormalisationRule::class, 'rule_id');
    }

    public function pathway(): BelongsTo
    {
        return $this->belongsTo(MigrationPathway::class, 'pathway_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MigrationPlan::class, 'migration_plan_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(DigitalFile::class, 'source_file_id');
    }

    public function output(): BelongsTo
    {
        return $this->belongsTo(DigitalFile::class, 'output_file_id');
    }

    public function instantiation(): BelongsTo
    {
        return $this->belongsTo(Instantiation::class, 'instantiation_id');
    }
}
