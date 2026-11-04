<?php

// OpenRiC Workspace - one of the institution's static pages, such as about, privacy or terms, in each language it is
// written in (Heratio's static_page; roadmap phase 20a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'slug', 'source_language', 'created_by_id'])]
class StaticPage extends Model
{
    use Audited, HasUuids;

    public function translations(): HasMany
    {
        return $this->hasMany(StaticPageTranslation::class);
    }

    /** The page in the first of the languages that it is written in, else in its own. */
    public function translation(array $languages): ?StaticPageTranslation
    {
        $all = $this->translations->keyBy('language');
        foreach ([...$languages, $this->source_language] as $language) {
            if ($all->has($language)) {
                return $all[$language];
            }
        }

        return $all->first();
    }
}
