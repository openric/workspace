<?php

// OpenRiC Workspace - check that every kept file is still what was received (WS-007).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Rehashes each file and compares its SHA-256 and size with what was
// recorded on upload. A failure is recorded on the file, audited, shown on
// its instantiation's page, and makes the command exit with failure, so a
// scheduler or monitor notices. Run daily from cron:
//   15 2 * * * www-data php /usr/share/nginx/openric-workspace-live/artisan workspace:fixity

namespace App\Console\Commands;

use App\Description\InstantiationService;
use App\Models\DigitalFile;
use Illuminate\Console\Command;

class CheckFixity extends Command
{
    protected $signature = 'workspace:fixity {--older-than=0 : Check only files not checked for this many days}';

    protected $description = 'Check the SHA-256 of every kept file against what was recorded when it came in';

    public function handle(InstantiationService $instantiations): int
    {
        $days = (int) $this->option('older-than');
        $checked = $failed = 0;
        DigitalFile::query()->when($days > 0, fn ($q) => $q->where(fn ($q) => $q->whereNull('fixity_checked_at')->orWhere('fixity_checked_at', '<', now()->subDays($days))))
            ->orderBy('id')->chunkById(200, function ($files) use ($instantiations, &$checked, &$failed) {
                foreach ($files as $file) {
                    $checked++;
                    if (! $instantiations->checkFixity($file)) {
                        $failed++;
                        $this->error("Fixity failed: file {$file->id} ({$file->original_name}), SHA-256 {$file->sha256}");
                    }
                }
            });
        $this->line("$checked files checked, $failed failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
