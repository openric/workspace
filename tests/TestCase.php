<?php

// OpenRiC Workspace - base test case.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests;

use App\Audit\AuditLog;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Concept ids change with every rebuilt test database.
        AuditLog::flush();
    }

    /** Load the Workspace's own vocabularies, as workspace:install does. */
    protected function loadBundledVocabularies(): void
    {
        foreach (config('workspace.bundled_vocabularies') as $file) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$file")));
        }
    }
}
