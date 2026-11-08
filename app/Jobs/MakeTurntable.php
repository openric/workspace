<?php

// OpenRiC Workspace - a 3D model's turntable video made in the background (roadmap phase 21, after Heratio's
// ahg:3d-multiangle), on the files queue.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Jobs;

use App\Description\InstantiationService;
use App\Models\BackgroundJob;
use App\Models\Instantiation;
use App\Models\User;

class MakeTurntable extends TrackedJob
{
    public function __construct(string $jobId, public string $instantiationId, public ?string $userId = null)
    {
        parent::__construct($jobId);
        // A file job: the files worker runs it, without outside network where it is installed (R1 hardening).
        $this->onQueue('files');
    }

    protected function run(BackgroundJob $job): array
    {
        $made = app(InstantiationService::class)->makeTurntable(Instantiation::with('files.use')->findOrFail($this->instantiationId), $this->userId ? User::find($this->userId) : null);

        return ['made' => $made];
    }
}
