<?php

// OpenRiC Workspace - walk the log of inferences, recompute every hash and check every signature (roadmap phase 17a),
// as workspace:audit:verify does for the audit log and Heratio's ahg-inference-receipts verifier does for its receipts.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Console\Commands;

use App\Ai\Inferences;
use Illuminate\Console\Command;

class VerifyInferences extends Command
{
    protected $signature = 'workspace:ai:verify';

    protected $description = 'Walk the log of inferences, recompute every hash and check every signature';

    public function handle(Inferences $inferences): int
    {
        $result = $inferences->verify();
        if (! $result['intact']) {
            $this->error("The log of inferences is broken: {$result['problem']}.");

            return self::FAILURE;
        }
        $this->info("The log of inferences is intact: {$result['rows']} receipts.");

        return self::SUCCESS;
    }
}
