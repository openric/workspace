<?php

// OpenRiC Workspace - a conversation with the catalogue's assistant (roadmap phase 17f; Heratio's ahg_ai_chatbot_session).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'user_id', 'token_hash', 'public', 'language', 'last_message_at'])]
class ChatConversation extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['public' => 'boolean', 'last_message_at' => 'immutable_datetime'];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id');
    }
}
