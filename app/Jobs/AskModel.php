<?php

// OpenRiC Workspace - asking a model, in the background, to find names in a unit, summarise its pages or assess the
// condition of an object from its picture (roadmap phase 17b1), or translate it (phase 17c). What it answers waits for review as proposals.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Jobs;

use App\Ai\AiUnavailable;
use App\Ai\Assist;
use App\Ai\Translate;
use App\Models\BackgroundJob;
use App\Models\Instantiation;
use App\Models\RecordResource;
use InvalidArgumentException;

class AskModel extends TrackedJob
{
    public const TASKS = ['names', 'summary', 'condition', 'translate'];

    /** @param  array{source?: string, target?: string, elements?: list<string>}  $options  for translate (phase 17c) */
    public function __construct(string $jobId, public string $task, public string $subjectId, public array $options = [])
    {
        parent::__construct($jobId);
    }

    protected function run(BackgroundJob $job): array
    {
        $assist = app(Assist::class);
        try {
            return match ($this->task) {
                'names' => ['proposed' => $assist->names(RecordResource::findOrFail($this->subjectId), $job->user)],
                'summary' => ['proposed' => $assist->summary(RecordResource::findOrFail($this->subjectId), $job->user) ? 1 : 0],
                'condition' => ['proposed' => $assist->condition(Instantiation::findOrFail($this->subjectId), $job->user) ? 1 : 0],
                'translate' => ['proposed' => app(Translate::class)->record(RecordResource::findOrFail($this->subjectId), $job->user,
                    (string) $this->options['source'], (string) $this->options['target'], (array) ($this->options['elements'] ?? []))],
            };
        } catch (AiUnavailable|InvalidArgumentException $e) {
            return ['proposed' => 0, 'reason' => $e->getMessage()];
        }
    }
}
