<?php

// OpenRiC Workspace - an account, belonging to one institution.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Started from the Laravel skeleton's User model (MIT). An account is disabled,
// never deleted. What it may do is decided by AccessControl from its own
// permissions and its roles' (ADR 0005); everything else is denied.

namespace App\Models;

use App\Access\AccessControl;
use App\Audit\Audited;
use App\Support\Vocab;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['institution_id', 'name', 'email', 'password', 'disabled_at', 'interface_language', 'email_bounced_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements HasLocalePreference
{
    use Audited, HasUuids, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
            'email_bounced_at' => 'immutable_datetime',
            'password' => 'hashed',
        ];
    }

    /** Mail to the account is written in the interface language it chose (roadmap phase 7). */
    public function preferredLocale(): ?string
    {
        return $this->interface_language;
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_members')->withPivot('granted_by_id', 'granted_at');
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /**
     * An administrative permission (institution kind) by its IRI, for the Gate and
     * the can: middleware. Record permissions go through AccessControl with the record.
     */
    public function hasPermission(string $permissionIri): bool
    {
        if (! str_starts_with($permissionIri, Vocab::PERMISSIONS)) {
            return false;
        }

        return app(AccessControl::class)->allows($this, substr($permissionIri, strlen(Vocab::PERMISSIONS)), 'institution');
    }

    /** May this account take the action on this kind of record, on this record or in general? */
    public function may(string $action, string $kind, ?Model $subject = null): bool
    {
        return app(AccessControl::class)->allows($this, $action, $kind, $subject);
    }

    /** May this account take the action on this kind somewhere, at any scope? (Lists and menus.) */
    public function mayAnywhere(string $action, string $kind): bool
    {
        return app(AccessControl::class)->allowsAnywhere($this, $action, $kind);
    }

    /** Forget cached roles after a membership changes. */
    public function refreshPermissions(): void
    {
        app(AccessControl::class)->forget($this);
    }
}
