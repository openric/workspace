<?php

// OpenRiC Workspace - an account, belonging to one institution.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Started from the Laravel skeleton's User model (MIT). An account is disabled,
// never deleted. It may do only what one of its roles is granted in its
// institution's role_grants; everything else is denied.

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

#[Fillable(['institution_id', 'name', 'email', 'password', 'disabled_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use Audited, HasUuids, Notifiable;

    /** Permission IRIs, loaded once per request. */
    private ?array $permissions = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
            'password' => 'hashed',
        ];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Concept::class, 'account_roles', 'user_id', 'role_id')
            ->withPivot('granted_by_id', 'granted_at');
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    public function hasPermission(string $permissionIri): bool
    {
        if ($this->isDisabled()) {
            return false;
        }
        $this->permissions ??= DB::table('account_roles as ar')
            ->join('role_grants as g', fn ($j) => $j->on('g.role_id', '=', 'ar.role_id')->where('g.institution_id', '=', $this->institution_id))
            ->join('concepts as p', 'p.id', '=', 'g.permission_id')
            ->where('ar.user_id', $this->id)
            ->whereNull('p.deprecated_at')
            ->pluck('p.iri')->unique()->values()->all();

        return in_array($permissionIri, $this->permissions, true);
    }

    /** Forget cached permissions after a role or grant changes. */
    public function refreshPermissions(): void
    {
        $this->permissions = null;
    }
}
