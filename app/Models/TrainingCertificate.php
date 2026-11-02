<?php

// OpenRiC Workspace - a certificate of a completed training course (roadmap phase 19j, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['enrolment_id', 'certificate_no', 'score', 'issued_at'])]
class TrainingCertificate extends Model
{
    use Audited, HasUuids;

    protected $table = 'training_certificates';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'score' => 'integer'];
    }

    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(TrainingEnrolment::class);
    }
}
