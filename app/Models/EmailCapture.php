<?php

// OpenRiC Workspace - an e-mail captured as a record (roadmap phase 13c, ADR 0015).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'message_id', 'from_address', 'to_addresses', 'cc_addresses', 'subject', 'sent_at', 'received_at', 'body_text', 'body_html', 'attachment_count', 'attachments', 'original_name', 'storage_path', 'sha256', 'size_bytes', 'record_resource_id', 'file_plan_node_id', 'disposal_class_id', 'source_id', 'status_id', 'captured_by_id'])]
class EmailCapture extends Model
{
    use Audited, HasUuids;

    protected $table = 'email_captures';

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'received_at' => 'datetime', 'attachments' => 'array', 'attachment_count' => 'integer', 'size_bytes' => 'integer'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(RecordResource::class, 'record_resource_id');
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(FilePlanNode::class, 'file_plan_node_id');
    }

    public function disposalClass(): BelongsTo
    {
        return $this->belongsTo(DisposalClass::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by_id');
    }
}
