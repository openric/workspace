<?php

// OpenRiC Workspace - the reference copy and thumbnail of an uploaded file, in the background (roadmap phase 5).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Jobs;

use App\Description\InstantiationService;
use App\Models\BackgroundJob;
use App\Models\Instantiation;

class MakeViewingCopies extends TrackedJob
{
    /** $remake: make them again, replacing the copies there are (after the watermark changed). */
    public function __construct(string $jobId, public string $instantiationId, public bool $remake = false)
    {
        parent::__construct($jobId);
    }

    protected function run(BackgroundJob $job): array
    {
        $instantiation = Instantiation::findOrFail($this->instantiationId);
        $service = app(InstantiationService::class);

        return ['copies' => $this->remake ? $service->remakeCopies($instantiation, $job->user) : $service->makeCopies($instantiation, $job->user)];
    }
}
