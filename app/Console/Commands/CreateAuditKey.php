<?php

// OpenRiC Workspace - create the Ed25519 key that signs the audit log (ADR 0004).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Console\Commands;

use App\Audit\AuditChain;
use Illuminate\Console\Command;

class CreateAuditKey extends Command
{
    protected $signature = 'workspace:audit:create-key {--if-missing : Do nothing when a key already exists}';

    protected $description = 'Create the audit signing key and register its public half';

    public function handle(): int
    {
        if (file_exists(AuditChain::keyPath())) {
            if ($this->option('if-missing')) {
                return self::SUCCESS;
            }
            $this->error('A signing key already exists at '.AuditChain::keyPath().'; it is never replaced.');

            return self::FAILURE;
        }
        $kid = AuditChain::createKey();
        $this->info("Audit signing key $kid created at ".AuditChain::keyPath());
        $this->warn('Back this key up with the database: without it no change can be recorded, and the Workspace refuses changes.');

        return self::SUCCESS;
    }
}
