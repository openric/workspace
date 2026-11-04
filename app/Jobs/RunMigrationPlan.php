<?php

// OpenRiC Workspace - run a migration plan's queued files in the background (roadmap phase 15c).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Jobs;

use App\Models\MigrationPlan;
use App\Models\User;
use App\Preservation\MigrationPlans;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunMigrationPlan implements ShouldQueue
{
    use Queueable;

    public $timeout = 0;

    public function __construct(public string $planId, public ?string $userId = null) {}

    public function handle(MigrationPlans $plans): void
    {
        if ($plan = MigrationPlan::find($this->planId)) {
            $plans->process($plan, $this->userId ? User::find($this->userId) : null);
        }
    }
}
