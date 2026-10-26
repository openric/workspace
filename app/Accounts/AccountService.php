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
use App\Description\Bcp47;
use App\Models\Institution;
use App\Models\Role;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Database\QueryException;
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

    /** Change the account's name and sign-in email (Heratio's user update, without its username and typed passwords). */
    public function update(User $account, string $name, string $email, ?User $actor = null): void
    {
        $email = mb_strtolower(trim($email));
        if (User::where('email', $email)->whereKeyNot($account->id)->exists()) {
            throw new InvalidArgumentException(__('another account signs in with that email'));
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $account->update(['name' => trim($name), 'email' => $email])));
    }

    /** @return list<string> the languages this account may translate into by itself, besides its roles' */
    public function translationLanguages(User $account): array
    {
        return DB::table('account_translation_languages')->where('user_id', $account->id)->orderBy('language')->pluck('language')->all();
    }

    /** The languages this account may translate into by itself (BCP 47 tags), as Heratio's per-user translate languages. */
    public function setTranslationLanguages(User $account, array $languages, ?User $actor = null): void
    {
        $languages = array_values(array_unique(array_filter(array_map('trim', $languages))));
        sort($languages);
        foreach ($languages as $language) {
            if (! Bcp47::valid($language)) {
                throw new InvalidArgumentException(__(':language is not a BCP 47 language tag', ['language' => $language]));
            }
        }
        DB::transaction(function () use ($account, $languages, $actor) {
            $before = $this->translationLanguages($account);
            if ($before === $languages) {
                return;
            }
            DB::table('account_translation_languages')->where('user_id', $account->id)->delete();
            foreach ($languages as $language) {
                DB::table('account_translation_languages')->insert(['user_id' => $account->id, 'language' => $language]);
            }
            AuditLog::record(Vocab::action('update'), $account, ['translation_languages' => $before], ['translation_languages' => $languages], $actor);
        });
    }

    /** Make the account a member of exactly these roles of its institution. */
    public function setRoles(User $account, array $roleIds, ?User $actor = null): void
    {
        $wanted = Role::where('institution_id', $account->institution_id)->where('is_system', false)->whereIn('id', $roleIds)->get();
        if ($wanted->count() !== count(array_unique($roleIds))) {
            throw new InvalidArgumentException(__('every role must be one of the institution\'s own roles'));
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
    /**
     * Delete an account that has never acted in the Workspace (Heratio's user delete). The audit log, imports and assertions
     * name the account that did each thing, and the database keeps those rows from losing their account, so an account
     * that has signed in, imported or asserted anything cannot be deleted: disable it instead, and the record of who did
     * what stays whole. Its role memberships, own permissions, translation languages and contact details go with it.
     */
    public function delete(User $account, ?User $actor = null): void
    {
        if ($actor !== null && $actor->id === $account->id) {
            throw new AccountException(__('You cannot delete your own account.'));
        }
        try {
            DB::transaction(function () use ($account, $actor) {
                $this->keepAnAdministrator($account);
                DB::table('role_members')->where('user_id', $account->id)->delete();
                DB::table('permissions')->where('user_id', $account->id)->delete();
                DB::table('account_translation_languages')->where('user_id', $account->id)->delete();
                DB::table('contact_points')->where(['subject_table' => 'users', 'subject_id' => $account->id])->delete();
                DB::table('password_reset_tokens')->where('email', $account->email)->delete();
                DB::table('sessions')->where('user_id', $account->id)->delete();
                AuditLog::actingAs($actor, fn () => $account->delete());
            });
        } catch (QueryException $e) {
            if ($e->getCode() !== '23503') {
                throw $e;
            }
            throw new AccountException(__('This account has done work in the Workspace (it has signed in, imported or made assertions), so it is kept: disable it instead.'));
        }
    }

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
            throw new AccountException(__('This is the last active administrator of the institution; give another account the Administrator role first.'));
        }
    }
}
