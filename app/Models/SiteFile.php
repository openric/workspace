<?php

// OpenRiC Workspace - a file or image shown on the institution's public site, kept in an OCFL object of its own: an
// article's attachment, cover or picture, or a landing page's image (Heratio's public blog and landing-page uploads;
// roadmap phase 20a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'article_id', 'file_name', 'mime_type', 'size_bytes', 'sha256', 'storage_path', 'created_by_id'])]
class SiteFile extends Model
{
    use Audited, HasUuids;

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
