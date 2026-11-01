<?php

// OpenRiC Workspace - the deployment check (roadmap phase 23): an unsafe production configuration is refused, a
// safe one passes, and a waived check is shown but does not stop the release.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use Tests\TestCase;

class PreflightTest extends TestCase
{
    private function safe(): void
    {
        $key = storage_path('framework/testing/preflight.sk');
        @mkdir(dirname($key), 0o755, true);
        file_put_contents($key, 'test');
        chmod($key, 0o600);
        // The environment file read is a private one, whatever mode the checkout's .env has (CI copies .env.example).
        file_put_contents($env = dirname($key).'/.env', "APP_ENV=production\n");
        chmod($env, 0o600);
        $this->app->useEnvironmentPath(dirname($key));
        $this->app['env'] = 'production';
        config(['app.debug' => false, 'app.url' => 'https://archives.example.org', 'session.secure' => true, 'mail.default' => 'smtp',
            'logging.default' => 'stack', 'logging.channels.stack' => ['driver' => 'stack', 'channels' => ['single']], 'logging.channels.single.level' => 'info', 'workspace.audit_signing_key' => $key,
            'workspace.object_store' => sys_get_temp_dir().'/objects', 'workspace.preflight_waive' => '']);
    }

    public function test_a_safe_configuration_passes(): void
    {
        $this->safe();
        $this->artisan('workspace:preflight')->expectsOutputToContain('The configuration passed.')->assertSuccessful();
    }

    public function test_debug_logged_mail_and_a_public_object_store_are_refused(): void
    {
        $this->safe();
        config(['app.debug' => true, 'mail.default' => 'log', 'workspace.object_store' => public_path('objects')]);
        $this->artisan('workspace:preflight')->expectsOutputToContain('3 of 10 checks failed: debug, mail, object-store.')->assertFailed();
        // A stack logs at the levels of the channels it holds.
        config(['app.debug' => false, 'mail.default' => 'smtp', 'workspace.object_store' => sys_get_temp_dir().'/objects', 'logging.channels.single.level' => 'debug']);
        $this->artisan('workspace:preflight')->expectsOutputToContain('1 of 10 checks failed: log.')->assertFailed();
    }

    public function test_a_waived_check_is_shown_and_does_not_stop_the_release(): void
    {
        $this->safe();
        config(['mail.default' => 'log', 'workspace.preflight_waive' => 'mail']);
        $this->artisan('workspace:preflight')->expectsOutputToContain('with mail waived')->assertSuccessful();
    }
}
