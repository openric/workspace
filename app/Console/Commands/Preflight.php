<?php

// OpenRiC Workspace - the deployment check (roadmap phase 23): refuses a production configuration that is unsafe.
// After the pre-deployment checklist of Heratio's docs/security.md, made a command so deploy/deploy.sh runs it. An
// installation may waive a check by name in WORKSPACE_PREFLIGHT_WAIVE (comma-separated), as a demonstration instance
// that sends no mail waives "mail"; a waived check is still shown.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Console\Commands;

use Illuminate\Console\Command;

class Preflight extends Command
{
    protected $signature = 'workspace:preflight';

    protected $description = 'Refuse an unsafe production configuration: debug, keys, mail, logging, file permissions';

    public function handle(): int
    {
        $env = app()->environmentFilePath();
        $store = rtrim(config('workspace.object_store') ?: storage_path('app/objects'), '/');
        $https = str_starts_with((string) config('app.url'), 'https://');
        $key = (string) config('workspace.audit_signing_key');
        $checks = [
            'env' => ['APP_ENV is production', app()->environment('production')],
            'debug' => ['APP_DEBUG is off', ! config('app.debug')],
            'key' => ['APP_KEY is set', (string) config('app.key') !== ''],
            'https' => ['APP_URL is https', $https],
            'cookies' => ['session cookies are sent over https only', ! $https || config('session.secure') !== false],
            'mail' => ['mail is sent, not logged or kept in memory', ! in_array(config('mail.default'), ['log', 'array'], true)],
            'log' => ['the log level is not debug', config('logging.channels.'.config('logging.default').'.level', 'debug') !== 'debug'],
            'env-file' => ['.env is not readable by everyone', ! is_file($env) || (fileperms($env) & 0o004) === 0],
            'signing-key' => ['the audit signing key exists and is not readable by everyone', is_file($key) && (fileperms($key) & 0o004) === 0],
            'object-store' => ['the object store is outside the public directory', ! str_starts_with($store.'/', public_path().'/')],
        ];
        $waived = array_filter(array_map('trim', explode(',', (string) config('workspace.preflight_waive'))));
        $failed = [];
        foreach ($checks as $name => [$check, $passed]) {
            $state = $passed ? 'ok' : (in_array($name, $waived, true) ? 'waived' : 'FAIL');
            $this->line(str_pad($state, 8)."$check ($name)");
            if ($state === 'FAIL') {
                $failed[] = $name;
            }
        }
        if ($failed) {
            $this->error(count($failed).' of '.count($checks).' checks failed: '.implode(', ', $failed).'.');

            return self::FAILURE;
        }
        $this->info('The configuration passed'.($waived ? ', with '.implode(', ', $waived).' waived' : '').'.');

        return self::SUCCESS;
    }
}
