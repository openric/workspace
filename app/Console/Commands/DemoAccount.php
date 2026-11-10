<?php

// OpenRiC Workspace - the demonstration installation's public account (after Heratio's demo, whose sign-in page shows
// its own): made, or put back, as an administrator of the first institution with the password the sign-in page shows.
// bin/demo-reset runs it after each nightly reset; it does nothing where no demonstration account is configured.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Console\Commands;

use App\Accounts\AccountService;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DemoAccount extends Command
{
    protected $signature = 'workspace:demo:account';

    protected $description = 'Make or put back the demonstration installation\'s public administrator account (WORKSPACE_DEMO_EMAIL)';

    public function handle(AccountService $accounts): int
    {
        $email = mb_strtolower(trim((string) config('workspace.demo_account.email')));
        $password = (string) config('workspace.demo_account.password');
        if ($email === '' || $password === '') {
            $this->info('No demonstration account is configured (WORKSPACE_DEMO_EMAIL, WORKSPACE_DEMO_PASSWORD): nothing to do.');

            return self::SUCCESS;
        }
        if (mb_strlen($password) < 12) {
            $this->error('WORKSPACE_DEMO_PASSWORD must be at least 12 characters, as every password is.');

            return self::INVALID;
        }
        $institution = Institution::query()->orderBy('created_at')->first();
        if ($institution === null) {
            $this->error('The Workspace is not installed: there is no institution.');

            return self::FAILURE;
        }
        $account = User::where('email', $email)->first();
        if ($account === null) {
            $account = $accounts->create($institution, 'Demonstration', $email, $password);
            $this->line("Made $email.");
        } else {
            // Directly, past the guard that keeps it from visitors: this is the guard's own repair.
            DB::transaction(fn () => $account->forceFill(['password' => $password, 'disabled_at' => null, 'two_factor_secret' => null,
                'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save());
        }
        if (! $account->may('manage-institution', 'institution')) {
            $accounts->grantTemplateRole($account, 'administrator');
        }
        $this->info("$email signs in with the demonstration password, as an administrator of {$institution->name}.");

        return self::SUCCESS;
    }
}
