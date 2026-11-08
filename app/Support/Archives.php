<?php

// OpenRiC Workspace - what an archive the Workspace opens may hold (roadmap R1 hardening, after the external
// production-readiness assessment of 7 November 2026): an import ZIP, a BagIt package, an Archivematica DIP or a deposit
// is counted from its own listing before anything is read out of it, and refused when its files together, uncompressed,
// or their number pass config workspace.archive_limits, so a small file that unpacks to fill the disk is turned away.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Support;

use Illuminate\Support\Number;
use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

class Archives
{
    /** An open ZIP: its central directory gives each entry's uncompressed size. */
    public static function zip(ZipArchive $zip): void
    {
        $bytes = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $bytes += (int) ($zip->statIndex($i)['size'] ?? 0);
        }
        self::within($bytes, $zip->numFiles);
    }

    /** A tar or 7z file, from tar's verbose listing or 7z's technical one. */
    public static function file(string $archive, string $format): void
    {
        $process = LimitedProcess::make($format, $format === '7z' ? ['7z', 'l', '-slt', $archive] : ['tar', '-tvf', $archive]);
        $process->setTimeout(600)->run();
        $process->isSuccessful() || throw new RuntimeException(__('The package file cannot be opened.'));
        $lines = array_filter(explode("\n", $process->getOutput()));
        if ($format === '7z') {
            $sizes = array_map(fn ($l) => (int) substr($l, 7), preg_grep('/^Size = \d+$/', $lines) ?: []);
            $entries = count(preg_grep('/^Path = /', $lines) ?: []) - 1; // the first Path is the archive itself
        } else {
            // GNU tar: permissions, owner/group, size, date, time, name.
            $sizes = array_map(fn ($l) => (int) (preg_split('/\s+/', trim($l))[2] ?? 0), $lines);
            $entries = count($lines);
        }
        self::within(array_sum($sizes), max(0, $entries));
    }

    private static function within(int $bytes, int $entries): void
    {
        $max = (int) config('workspace.archive_limits.max_bytes');
        $count = (int) config('workspace.archive_limits.max_entries');
        if ($entries > $count) {
            throw new InvalidArgumentException(__('The archive holds :count files; no more than :max are taken.', ['count' => number_format($entries), 'max' => number_format($count)]));
        }
        if ($bytes > $max) {
            throw new InvalidArgumentException(__('The archive unpacks to :size; no more than :max are taken.', ['size' => Number::fileSize($bytes, 1), 'max' => Number::fileSize($max, 1)]));
        }
    }
}
