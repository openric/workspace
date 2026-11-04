<?php

// OpenRiC Workspace - a file attached to an article: a guide, template, report or other kind, in its section and order
// (Heratio's blog_attachment; roadmap phase 20a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['article_id', 'site_file_id', 'kind_id', 'group_label', 'title', 'description', 'sort_order', 'created_by_id'])]
class ArticleAttachment extends Model
{
    use Audited, HasUuids;

    public function file(): BelongsTo
    {
        return $this->belongsTo(SiteFile::class, 'site_file_id');
    }

    public function kind(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'kind_id');
    }

    public function isVideo(): bool
    {
        return str_starts_with((string) $this->file?->mime_type, 'video/');
    }
}
