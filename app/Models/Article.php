<?php

// OpenRiC Workspace - an article on the institution's public site, with its attachments, the articles it links to and
// visitors' comments (Heratio's blog_post; roadmap phase 20a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'slug', 'title', 'excerpt', 'body', 'attachments_label', 'author', 'article_group', 'status_id', 'published_at', 'cover_file_id', 'created_by_id'])]
class Article extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['published_at' => 'immutable_datetime'];
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function cover(): BelongsTo
    {
        return $this->belongsTo(SiteFile::class, 'cover_file_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ArticleAttachment::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ArticleComment::class);
    }

    /** Is it on the public site: published, and its publication date come? */
    public function isPublic(): bool
    {
        return $this->status?->notation === 'published' && (! $this->published_at || $this->published_at->isPast());
    }
}
