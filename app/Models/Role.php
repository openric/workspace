<?php

// OpenRiC Workspace - a role of an institution (ADR 0005; Heratio and AtoM groups).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable(['institution_id', 'template_id', 'parent_id', 'is_system'])]
class Role extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'template_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function labels(): HasMany
    {
        return $this->hasMany(RoleLabel::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'role_members')->withPivot('granted_by_id', 'granted_at');
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(Permission::class);
    }

    /** @return list<string> BCP 47 tags this role may translate into */
    public function translationLanguages(): array
    {
        return DB::table('role_translation_languages')->where('role_id', $this->id)->orderBy('language')->pluck('language')->all();
    }

    public function name(array $languages = ['en']): string
    {
        $labels = $this->labels->keyBy('language');
        foreach ([...$languages, 'en', 'und'] as $language) {
            if (isset($labels[$language])) {
                return $labels[$language]->name;
            }
        }

        return $labels->first()?->name ?? $this->id;
    }

    /** Is this the institution's role made from the given account-role template, such as administrator? */
    public function isTemplate(string $notation): bool
    {
        return $this->template?->notation === $notation;
    }

    /** This role and every role above it, nearest first. */
    public function lineage(): array
    {
        $chain = [];
        $seen = [];
        for ($role = $this; $role !== null && ! isset($seen[$role->id]); $role = $role->parent) {
            $seen[$role->id] = true;
            $chain[] = $role;
        }

        return $chain;
    }
}
