<?php

// OpenRiC Workspace - visitors ask for an account; an administrator approves or rejects (Heratio's registration).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Heratio's flow (registration-register, registrationApprove, registrationReject)
// without its email verification, since the Workspace sends no mail yet: a
// request waits for an administrator. Approving makes the account with the
// password the visitor chose and the roles the administrator picks; both
// decisions are audited, with the request as their subject.

namespace App\Accounts;

use App\Audit\AuditLog;
use App\Models\Institution;
use App\Models\RegistrationRequest;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;

class RegistrationService
{
    public function __construct(private AccountService $accounts) {}

    public function request(Institution $institution, string $name, string $email, string $password, ?string $reason): RegistrationRequest
    {
        $email = mb_strtolower(trim($email));
        if (! $institution->registration_open) {
            throw new AccountException('This institution does not take requests for accounts.');
        }
        if (User::where('email', $email)->exists() || RegistrationRequest::whereRaw('lower(email) = ?', [$email])->whereNull('approved_at')->whereNull('rejected_at')->exists()) {
            throw new AccountException('An account or a request for one with that email exists already.');
        }

        return RegistrationRequest::create(['institution_id' => $institution->id, 'name' => trim($name), 'email' => $email, 'password' => $password,
            'reason' => trim((string) $reason) ?: null]);
    }

    /** @param  list<string>  $roleIds */
    public function approve(RegistrationRequest $request, array $roleIds, User $actor): User
    {
        if (! $request->isPending()) {
            throw new AccountException('This request has been decided already.');
        }

        return DB::transaction(function () use ($request, $roleIds, $actor) {
            // The password is the hash the visitor's choice became; the hashed cast keeps it as it is.
            $account = $this->accounts->create(Institution::findOrFail($request->institution_id), $request->name, $request->email, $request->getRawOriginal('password'), $actor);
            $this->accounts->setRoles($account, $roleIds, $actor);
            $request->update(['approved_at' => now(), 'decided_by_id' => $actor->id, 'account_id' => $account->id]);
            AuditLog::record(Vocab::action('update'), $request, ['decision' => null], ['decision' => 'approved', 'account_id' => $account->id], $actor);

            return $account;
        });
    }

    public function reject(RegistrationRequest $request, User $actor): void
    {
        if (! $request->isPending()) {
            throw new AccountException('This request has been decided already.');
        }
        DB::transaction(function () use ($request, $actor) {
            $request->update(['rejected_at' => now(), 'decided_by_id' => $actor->id]);
            AuditLog::record(Vocab::action('update'), $request, ['decision' => null], ['decision' => 'rejected'], $actor);
        });
    }
}
