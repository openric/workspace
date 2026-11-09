<?php

// OpenRiC Workspace - one message in a conversation with the assistant (roadmap phase 17f; Heratio's ahg_ai_chatbot_message).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['conversation_id', 'role_id', 'content', 'sources', 'inference_id', 'guardrail', 'flagged_at', 'flag_note', 'review_id', 'reviewed_by_id', 'reviewed_at', 'created_at'])]
class ChatMessage extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['sources' => 'array', 'guardrail' => 'array', 'flagged_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'role_id');
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'review_id');
    }
}
