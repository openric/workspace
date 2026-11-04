<?php

// OpenRiC Workspace - a static page's title and text in one language (Heratio's static_page_i18n; roadmap phase 20a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['static_page_id', 'language', 'title', 'content'])]
class StaticPageTranslation extends Model
{
    use Audited, HasUuids;
}
