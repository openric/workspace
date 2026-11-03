<?php

// OpenRiC Workspace - base test case.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests;

use App\Audit\AuditChain;
use App\Audit\AuditLog;
use App\Audit\Crypto\KeyPair;
use App\Description\Elements;
use App\Vocabulary\SkosImporter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /** Whether this run's test database was given the bundled vocabularies when it was migrated. */
    private static bool $vocabulariesLoaded = false;

    /**
     * The test database is migrated once a run, as RefreshDatabase does, and given the bundled vocabularies then, before
     * any test's transaction begins, so no test imports them again: importing all of them cost about ten seconds a test
     * (Dr Johan Pieterse's choice 1 of 3 November 2026). Each test still runs in its own transaction, rolled back.
     */
    protected function setUpTraits()
    {
        if (! RefreshDatabaseState::$migrated && isset(($this->traitsUsedByTest ?? class_uses_recursive(static::class))[RefreshDatabase::class])) {
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());
            $this->importBundledVocabularies();
            $this->app[Kernel::class]->setArtisan(null);
            RefreshDatabaseState::$migrated = self::$vocabulariesLoaded = true;
        }

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Concept ids change with every rebuilt test database.
        AuditLog::flush();
        AuditChain::flush();
        Elements::flush();
        if (in_array(RefreshDatabase::class, class_uses_recursive($this), true)) {
            $this->useTestSigningKey();
        }
    }

    /** One throwaway signing key for the test run, registered in each fresh database. */
    protected function useTestSigningKey(): void
    {
        $path = storage_path('framework/testing/audit-signing.sk');
        config(['workspace.audit_signing_key' => $path]);
        if (! is_file($path)) {
            KeyPair::generate()->saveTo($path, $path.'.pub');
        }
        $key = KeyPair::loadFrom($path);
        DB::table('audit_signing_keys')->insertOrIgnore(['kid' => $key->kid(), 'public_key' => $key->publicKeyBase64()]);
    }

    /** Load the Workspace's own vocabularies, as workspace:install does: loaded once a run, when the database is migrated. */
    protected function loadBundledVocabularies(): void
    {
        if (! self::$vocabulariesLoaded) {
            $this->importBundledVocabularies();
        }
    }

    private function importBundledVocabularies(): void
    {
        foreach (config('workspace.bundled_vocabularies') as $file) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$file")));
        }
    }
}
