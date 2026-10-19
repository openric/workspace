<?php

// OpenRiC Workspace - first installation: vocabularies, the institution, its
// role grants and the first administrator (WS-001).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Console\Commands;

use App\Accounts\AccountService;
use App\Audit\AuditLog;
use App\Models\Institution;
use App\Support\Vocab;
use App\Vocabulary\SkosImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class Install extends Command
{
    protected $signature = 'workspace:install
        {--institution= : Name of the institution}
        {--base-iri= : Base of the IRIs the institution mints, e.g. https://archives.example.org}
        {--admin-name= : Name of the first administrator}
        {--admin-email= : Email the first administrator signs in with}
        {--skip-openric-vocabularies : Do not fetch the OpenRiC vocabularies from openric.org (offline installs)}';

    protected $description = 'Install the Workspace: vocabularies, the institution and its first administrator';

    public function handle(SkosImporter $importer, AccountService $accounts): int
    {
        if (Institution::exists()) {
            $this->error('The Workspace is already installed: an institution exists.');

            return self::FAILURE;
        }

        $input = [
            'institution' => $this->option('institution') ?? $this->ask('Institution name'),
            'base_iri' => rtrim((string) ($this->option('base-iri') ?? $this->ask('Base IRI (https://...)')), '/'),
            'name' => $this->option('admin-name') ?? $this->ask('Administrator name'),
            'email' => $this->option('admin-email') ?? $this->ask('Administrator email'),
        ];
        $validator = Validator::make($input, [
            'institution' => ['required', 'string', 'max:500'],
            'base_iri' => ['required', 'url:https', 'max:500'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::INVALID;
        }

        // Vocabularies first: each import is its own transaction, and the institution
        // below cannot be created without the roles, permissions and audit actions.
        foreach (config('workspace.bundled_vocabularies') as $file) {
            $this->line($importer->import(file_get_contents(resource_path("vocabularies/$file")))->summary());
        }
        if (! $this->option('skip-openric-vocabularies')) {
            $this->call('workspace:vocabulary:import', ['--configured' => true]);
        }

        $password = $this->input->isInteractive() ? $this->secret('Administrator password (at least 12 characters; empty to generate one)') : null;
        $generated = $password === null || $password === '';
        if ($generated) {
            $password = Str::password(24);
        } elseif (Validator::make(['password' => $password], ['password' => [Password::min(12)]])->fails()) {
            $this->error('The password must be at least 12 characters.');

            return self::INVALID;
        }

        // Every audit row is signed (ADR 0004), so the key comes before the first one.
        $this->call('workspace:audit:create-key', ['--if-missing' => true]);

        $admin = DB::transaction(function () use ($input, $password, $accounts) {
            $institution = Institution::create(['name' => $input['institution'], 'base_iri' => $input['base_iri']]);
            $accounts->seedDefaultGrants($institution);
            $admin = $accounts->create($institution, $input['name'], $input['email'], $password);
            $accounts->grantRole($admin, Vocab::role('administrator'));
            AuditLog::record(Vocab::action('install'), $institution, null, ['administrator' => $admin->email]);

            return $admin;
        });

        $this->info("Installed: {$input['institution']}, administrator {$admin->email}.");
        if ($generated) {
            $this->warn('Generated password, shown once: '.$password);
        }

        return self::SUCCESS;
    }
}
