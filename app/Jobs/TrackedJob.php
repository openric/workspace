<?php

// OpenRiC Workspace - queued work that keeps its record in background_jobs (roadmap phase 5).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The record is started when a worker takes the job, completed with what the work
// returns, or failed with the error. A job cancelled while it waited is skipped. A failed
// job is not tried again: a person reads the error and asks again.

namespace App\Jobs;

use App\Models\BackgroundJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

abstract class TrackedJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public string $jobId)
    {
        $this->afterCommit();
    }

    /** The work; what it returns is kept as the job's output. */
    abstract protected function run(BackgroundJob $job): array;

    public function handle(JobsService $jobs): void
    {
        $job = BackgroundJob::find($this->jobId);
        if ($job === null || ! $jobs->start($job)) {
            return;
        }
        try {
            $jobs->complete($job, $this->run($job));
        } catch (Throwable $e) {
            report($e);
            $jobs->fail($job, $e->getMessage(), $e->getTraceAsString());
        }
    }
}
