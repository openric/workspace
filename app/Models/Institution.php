<?php

// OpenRiC Workspace - an institution, the owner of every other record.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'base_iri', 'header_title', 'logo_path', 'logo_mime', 'catalogue_introduction', 'catalogue_credits', 'registration_open', 'interface_languages'])]
class Institution extends Model
{
    protected function casts(): array
    {
        return ['registration_open' => 'boolean', 'interface_languages' => 'array'];
    }

    use Audited, HasUuids;

    /** The institution whose branding a page shows: the signed-in account's, or the installation's only one. */
    public static function current(): ?self
    {
        return auth()->user()?->institution ?? self::orderBy('created_at')->first();
    }

    public function headerTitle(): string
    {
        return $this->header_title ?: (string) config('app.name');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
