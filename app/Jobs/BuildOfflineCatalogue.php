<?php

// OpenRiC Workspace - an offline catalogue built in the background (roadmap phase 11c), as Heratio's
// ahg:portable-export-worker builds a pending package.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Jobs;

use App\Exchange\OfflineCatalogue\Builder;
use App\Models\BackgroundJob;
use App\Models\OfflineCatalogue;

class BuildOfflineCatalogue extends TrackedJob
{
    // ponytail: one hour covers a catalogue of thousands of descriptions with reference copies; a very large archive
    // with masters wants the builder to stream into the ZIP and resume, which Heratio's worker does not do either.
    public int $timeout = 3600;

    public function __construct(string $jobId, public string $catalogueId)
    {
        parent::__construct($jobId);
        // A file job: the files worker runs it, without outside network where it is installed (R1 hardening).
        $this->onQueue('files');
    }

    protected function run(BackgroundJob $job): array
    {
        return app(Builder::class)->build(OfflineCatalogue::findOrFail($this->catalogueId));
    }
}
