<?php

// OpenRiC Workspace - check that every kept file is still what was received (WS-007).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Rehashes each file and compares its SHA-256 and size with what was
// recorded on upload, as one fixity run per institution in its ledger. A
// failure is recorded on the file, audited, kept open under Admin, Fixity,
// shown on its instantiation's page, and makes the command exit with failure,
// so a monitor notices. Fixity schedules (phase 12a) run the same checks from
// the scheduler; this command is for a check of everything at once.

namespace App\Console\Commands;

use App\Fixity\Fixity;
use App\Models\Institution;
use Illuminate\Console\Command;

class CheckFixity extends Command
{
    protected $signature = 'workspace:fixity {--older-than=0 : Check only files not checked for this many days}';

    protected $description = 'Check the SHA-256 of every kept file against what was recorded when it came in';

    public function handle(Fixity $fixity): int
    {
        $days = (int) $this->option('older-than');
        [$checked, $failed] = [0, 0];
        // One run for each institution, kept in its fixity ledger beside its scheduled runs (phase 12a).
        foreach (Institution::all() as $institution) {
            $run = $fixity->run($institution, null, 'command', null, $days);
            foreach ($run->checks()->with('outcome', 'file')->get()->reject(fn ($c) => $c->outcome->notation === 'pass') as $check) {
                $this->error("Fixity failed ({$check->outcome->notation}): file {$check->digital_file_id} ({$check->file?->original_name}), SHA-256 {$check->expected_sha256}");
            }
            [$checked, $failed] = [$checked + $run->files_checked, $failed + $run->files_changed + $run->files_missing];
        }
        $this->line("$checked files checked, $failed failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
