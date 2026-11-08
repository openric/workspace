<?php

// OpenRiC Workspace - normalise a newly kept master in the background, where its institution chose to (roadmap phase 15c).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Jobs;

use App\Models\DigitalFile;
use App\Models\User;
use App\Preservation\Conversions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class NormaliseFile implements ShouldQueue
{
    use Queueable;

    public $timeout = 7500;

    public function __construct(public string $fileId, public ?string $userId = null)
    {
        // A file job: the files worker runs it, without outside network where it is installed (R1 hardening).
        $this->onQueue('files');
    }

    public function handle(Conversions $conversions): void
    {
        if ($file = DigitalFile::find($this->fileId)) {
            $actor = $this->userId ? User::find($this->userId) : null;
            foreach (['preservation', 'access'] as $purpose) {
                $conversions->normalise($file, $purpose, $actor);
            }
        }
    }
}
