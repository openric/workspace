<?php

// OpenRiC Workspace - a report built in the report builder (roadmap phase 8; PSIS ahgReportBuilderPlugin, Heratio ahg-reports custom_report).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'user_id', 'name', 'description', 'source', 'columns', 'filters', 'sort', 'status_id', 'shared', 'template_id'])]
class CustomReport extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['columns' => 'array', 'filters' => 'array', 'sort' => 'array', 'shared' => 'boolean'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ReportSchedule::class, 'report_id')->orderBy('created_at');
    }

    public function archives(): HasMany
    {
        return $this->hasMany(ReportArchive::class, 'report_id')->orderByDesc('generated_at');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(ReportShare::class, 'report_id')->orderByDesc('created_at');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ReportVersion::class, 'report_id')->orderByDesc('number');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(CustomReportSection::class, 'report_id')->orderBy('position')->orderBy('created_at');
    }
}
