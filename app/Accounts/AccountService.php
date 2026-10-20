<?php

// OpenRiC Workspace - create, disable and enable accounts, set their roles and passwords.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Every operation runs in one transaction with its audit row, so a change is
// never stored without its record, or recorded without being stored. Role
// membership itself is RoleService's (ADR 0005).

namespace App\Accounts;

use App\Access\RoleService;
use App\Audit\AuditLog;
use App\Models\Institution;
use App\Models\Role;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use InvalidArgumentException;

class AccountService
{
    public function __construct(private RoleService $roles) {}

    public function create(Institution $institution, string $name, string $email, string $password, ?User $actor = null): User
    {
        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => User::create([
            'institution_id' => $institution->id,
            'name' => $name,
            'email' => mb_strtolower(trim($email)),
            'password' => $password,
        ])));
    }

    /** Make the account a member of exactly these roles of its institution. */
    public function setRoles(User $account, array $roleIds, ?User $actor = null): void
    {
        $wanted = Role::where('institution_id', $account->institution_id)->where('is_system', false)->whereIn('id', $roleIds)->get();
        if ($wanted->count() !== count(array_unique($roleIds))) {
            throw new InvalidArgumentException('every role must be one of the institution\'s own roles');
        }
        DB::transaction(function () use ($account, $wanted, $actor) {
            $current = $account->roles()->get();
            foreach ($wanted->whereNotIn('id', $current->pluck('id')) as $role) {
                $this->roles->addMember($role, $account, $actor);
            }
            foreach ($current->whereNotIn('id', $wanted->pluck('id')) as $role) {
                $this->roles->removeMember($role, $account, $actor);
            }
        });
    }

    /** Make the account a member of the institution's role made from this template, such as administrator. */
    public function grantTemplateRole(User $account, string $template, ?User $actor = null): void
    {
        $role = $this->roles->byTemplate($account->institution, $template)
            ?? throw new InvalidArgumentException("the institution has no $template role");
        $this->roles->addMember($role, $account, $actor);
    }

    public function revokeTemplateRole(User $account, string $template, ?User $actor = null): void
    {
        $role = $this->roles->byTemplate($account->institution, $template)
            ?? throw new InvalidArgumentException("the institution has no $template role");
        $this->roles->removeMember($role, $account, $actor);
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
     * Refuse to disable the last active member of the Administrator role, so
     * nobody can lock the institution out of its own accounts.
     * ponytail: two administrators disabling each other at the same instant could
     * both pass; a per-institution advisory lock closes that if it ever matters.
     */
    private function keepAnAdministrator(User $account): void
    {
        $admin = $this->roles->byTemplate($account->institution, 'administrator');
        if ($admin === null || $account->disabled_at !== null
            || ! DB::table('role_members')->where(['role_id' => $admin->id, 'user_id' => $account->id])->exists()) {
            return;
        }
        $others = DB::table('role_members as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.role_id', $admin->id)->whereNull('u.disabled_at')->where('u.id', '<>', $account->id)->exists();
        if (! $others) {
            throw new AccountException('This is the last active administrator of the institution; give another account the Administrator role first.');
        }
    }
}
