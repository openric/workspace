<?php

// OpenRiC Workspace - identify a newly kept file's format in the background (roadmap phase 15a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Jobs;

use App\Models\DigitalFile;
use App\Preservation\FormatIdentification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class IdentifyFormat implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $fileId) {}

    public function handle(FormatIdentification $identification): void
    {
        if ($file = DigitalFile::find($this->fileId)) {
            $identification->identify($file);
        }
    }
}
