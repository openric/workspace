<?php

// OpenRiC Workspace - the length of a sound or video file, read by ffprobe (roadmap phase 14, ADR 0014).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A IIIF canvas for sound or video must carry its duration. exiftool's tags give it for many files but not all;
// ffprobe reads the container itself. Dr Johan Pieterse's choice 1A of 3 November 2026: read it at upload, and once
// for the files already held; a file ffprobe cannot read stays out of the manifest, and the validation page says so.

namespace App\Files;

use App\Support\LimitedProcess;
use Symfony\Component\Process\ExecutableFinder;

class MediaDuration
{
    /** Seconds, or null when ffprobe is missing or cannot read a length from the file. */
    public function read(string $path): ?float
    {
        if ((new ExecutableFinder)->find('ffprobe') === null || ! is_file($path)) {
            return null;
        }
        $process = LimitedProcess::make('ffprobe', ['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $path]);
        $process->setTimeout(60);
        $process->run();
        $value = trim($process->getOutput());

        return $process->isSuccessful() && is_numeric($value) && (float) $value > 0 ? round((float) $value, 3) : null;
    }

    public static function timed(string $mime): bool
    {
        return str_starts_with($mime, 'audio/') || str_starts_with($mime, 'video/');
    }
}
