<?php

// OpenRiC Workspace - a message about a material request, between staff and the researcher, or staff only (Heratio's research_request_correspondence; roadmap phase 19b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['material_request_id', 'from_staff', 'sender_id', 'subject', 'body', 'is_internal', 'attachment_file_id', 'created_at'])]
class MaterialRequestMessage extends Model
{
    use Audited, HasUuids;

    protected $table = 'material_request_messages';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['from_staff' => 'boolean', 'is_internal' => 'boolean', 'created_at' => 'immutable_datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(MaterialRequest::class, 'material_request_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(ResearchFile::class, 'attachment_file_id');
    }
}
