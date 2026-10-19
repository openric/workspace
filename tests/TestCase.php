<?php

// OpenRiC Workspace - base test case.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests;

use App\Audit\AuditChain;
use App\Audit\AuditLog;
use App\Audit\Crypto\KeyPair;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Concept ids change with every rebuilt test database.
        AuditLog::flush();
        AuditChain::flush();
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

    /** Load the Workspace's own vocabularies, as workspace:install does. */
    protected function loadBundledVocabularies(): void
    {
        foreach (config('workspace.bundled_vocabularies') as $file) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$file")));
        }
    }
}
