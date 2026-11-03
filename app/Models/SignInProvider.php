<?php

// OpenRiC Workspace - an identity provider the institution's people sign in with, by OpenID Connect (AtoM's
// arOidcPlugin providers; roadmap phase 12b). The client secret is encrypted.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'label', 'issuer_url', 'client_id', 'client_secret', 'scopes', 'match_id', 'roles_claim', 'role_map', 'accepts_requests', 'is_active', 'created_by_id'])]
class SignInProvider extends Model
{
    use Audited, HasUuids;

    protected $hidden = ['client_secret'];

    protected function casts(): array
    {
        return ['client_secret' => 'encrypted', 'scopes' => 'array', 'role_map' => 'array', 'accepts_requests' => 'boolean', 'is_active' => 'boolean'];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'match_id');
    }
}
