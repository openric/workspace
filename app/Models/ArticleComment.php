<?php

// OpenRiC Workspace - a visitor's comment on an article, and whether it is shown (Heratio's blog_comment; roadmap phase 20a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['article_id', 'author_name', 'body', 'status_id', 'ip', 'user_agent'])]
class ArticleComment extends Model
{
    use Audited, HasUuids;

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }
}
