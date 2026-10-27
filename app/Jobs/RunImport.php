<?php

// OpenRiC Workspace - an import's dry run, or the import itself, in the background (roadmap phase 5).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Jobs;

use App\Exchange\Import\ImportService;
use App\Models\BackgroundJob;
use App\Models\Import;

class RunImport extends TrackedJob
{
    public function __construct(string $jobId, public string $importId, public bool $commit)
    {
        parent::__construct($jobId);
    }

    protected function run(BackgroundJob $job): array
    {
        $import = Import::findOrFail($this->importId);
        if ($this->commit && $import->committed_at !== null) {
            return ['skipped' => 'imported already'];
        }

        return ['commit' => $this->commit] + app(ImportService::class)->run($import, $this->commit)->actions();
    }
}
