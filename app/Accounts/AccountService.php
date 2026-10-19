<?php

// OpenRiC Workspace - create accounts, grant and revoke roles, disable accounts.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Every operation runs in one transaction with its audit row, so a change is
// never stored without its record, or recorded without being stored.

namespace App\Accounts;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AccountService
{
    public function create(Institution $institution, string $name, string $email, string $password, ?User $actor = null): User
    {
        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => User::create([
            'institution_id' => $institution->id,
            'name' => $name,
            'email' => mb_strtolower(trim($email)),
            'password' => $password,
        ])));
    }

    public function grantRole(User $account, string $roleIri, ?User $actor = null): void
    {
        $role = $this->role($roleIri);
        DB::transaction(function () use ($account, $role, $actor) {
            $inserted = DB::table('account_roles')->insertOrIgnore([
                'user_id' => $account->id,
                'role_id' => $role->id,
                'granted_by_id' => $actor?->id,
            ]);
            if ($inserted > 0) {
                AuditLog::record(Vocab::action('grant-role'), $account, null, ['role' => $role->iri], $actor);
            }
        });
        $account->refreshPermissions();
    }

    public function revokeRole(User $account, string $roleIri, ?User $actor = null): void
    {
        $role = $this->role($roleIri);
        DB::transaction(function () use ($account, $role, $actor) {
            $deleted = DB::table('account_roles')->where(['user_id' => $account->id, 'role_id' => $role->id])->delete();
            if ($deleted > 0) {
                AuditLog::record(Vocab::action('revoke-role'), $account, ['role' => $role->iri], null, $actor);
            }
        });
        $account->refreshPermissions();
    }

    public function disable(User $account, ?User $actor = null): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $account->update(['disabled_at' => now()])));
        $account->refreshPermissions();
    }

    /** Seed an institution's role grants from config/workspace.php (installation only). */
    public function seedDefaultGrants(Institution $institution): void
    {
        foreach (config('workspace.default_grants') as $role => $permissions) {
            foreach ($permissions as $permission) {
                DB::table('role_grants')->insertOrIgnore([
                    'institution_id' => $institution->id,
                    'role_id' => $this->role(Vocab::role($role))->id,
                    'permission_id' => $this->concept(Vocab::permission($permission), 'permission')->id,
                ]);
            }
        }
    }

    private function role(string $iri): Concept
    {
        return $this->concept($iri, 'account role');
    }

    private function concept(string $iri, string $kind): Concept
    {
        $concept = Concept::where('iri', $iri)->first();
        if ($concept === null || $concept->deprecated_at !== null) {
            throw new InvalidArgumentException("$iri is not a current $kind");
        }

        return $concept;
    }
}
