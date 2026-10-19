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
use Illuminate\Support\Facades\Password;
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
            if ($role->iri === Vocab::role('administrator')) {
                $this->keepAnAdministrator($account);
            }
            $deleted = DB::table('account_roles')->where(['user_id' => $account->id, 'role_id' => $role->id])->delete();
            if ($deleted > 0) {
                AuditLog::record(Vocab::action('revoke-role'), $account, ['role' => $role->iri], null, $actor);
            }
        });
        $account->refreshPermissions();
    }

    public function disable(User $account, ?User $actor = null): void
    {
        DB::transaction(function () use ($account, $actor) {
            $this->keepAnAdministrator($account);
            AuditLog::actingAs($actor, fn () => $account->update(['disabled_at' => now()]));
        });
        $account->refreshPermissions();
    }

    public function enable(User $account, ?User $actor = null): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $account->update(['disabled_at' => null])));
        $account->refreshPermissions();
    }

    /**
     * Grant exactly the given roles: grant the missing ones, revoke the rest.
     *
     * @param  list<string>  $roleIris
     */
    public function setRoles(User $account, array $roleIris, ?User $actor = null): void
    {
        DB::transaction(function () use ($account, $roleIris, $actor) {
            $current = $account->roles()->pluck('iri')->all();
            foreach (array_diff($roleIris, $current) as $iri) {
                $this->grantRole($account, $iri, $actor);
            }
            foreach (array_diff($current, $roleIris) as $iri) {
                $this->revokeRole($account, $iri, $actor);
            }
        });
    }

    /**
     * A one-time link for the account to set its own password, valid for
     * auth.passwords.users.expire minutes. The administrator passes it on;
     * nobody else ever sees the password.
     */
    public function issuePasswordLink(User $account, ?User $actor = null): string
    {
        $token = DB::transaction(function () use ($account, $actor) {
            $token = Password::broker()->createToken($account);
            AuditLog::record(Vocab::action('issue-password-link'), $account, null, null, $actor);

            return $token;
        });

        return route('password.set', ['token' => $token, 'email' => $account->email]);
    }

    /** Set the password from a one-time link. Returns false if the link is not valid. */
    public function setPasswordWithToken(string $email, string $token, string $password): bool
    {
        $status = Password::broker()->reset(
            ['email' => mb_strtolower(trim($email)), 'token' => $token, 'password' => $password],
            function (User $account, string $password) {
                DB::transaction(function () use ($account, $password) {
                    AuditLog::actingAs($account, fn () => $account->forceFill(['password' => $password, 'remember_token' => null])->save());
                    AuditLog::record(Vocab::action('set-password'), $account, null, null, $account);
                });
            },
        );

        return $status === Password::PASSWORD_RESET;
    }

    public function changePassword(User $account, string $password): void
    {
        DB::transaction(function () use ($account, $password) {
            AuditLog::actingAs($account, fn () => $account->update(['password' => $password]));
            AuditLog::record(Vocab::action('set-password'), $account, null, null, $account);
        });
    }

    /**
     * Refuse a change that would leave the institution with no active
     * administrator, so nobody can lock the institution out of its own accounts.
     * ponytail: two administrators removing each other at the same instant could
     * both pass; a per-institution advisory lock closes that if it ever matters.
     */
    private function keepAnAdministrator(User $account): void
    {
        $admin = Concept::where('iri', Vocab::role('administrator'))->value('id');
        $isActiveAdmin = $account->disabled_at === null
            && DB::table('account_roles')->where(['user_id' => $account->id, 'role_id' => $admin])->exists();
        if (! $isActiveAdmin) {
            return;
        }
        $others = DB::table('account_roles as ar')->join('users as u', 'u.id', '=', 'ar.user_id')
            ->where('ar.role_id', $admin)->where('u.institution_id', $account->institution_id)
            ->whereNull('u.disabled_at')->where('u.id', '<>', $account->id)->exists();
        if (! $others) {
            throw new AccountException('This is the last active administrator of the institution; give another account the administrator role first.');
        }
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
