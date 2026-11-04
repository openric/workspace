<?php

// OpenRiC Workspace - check the OCFL storage root and every object in it against OCFL 1.1 (roadmap phase 15b, ADR 0022).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Console\Commands;

use App\Files\FileStore;
use Illuminate\Console\Command;

class VerifyOcfl extends Command
{
    protected $signature = 'workspace:ocfl:verify';

    protected $description = 'Check the OCFL storage root and every object in it: inventories, sidecars, versions and content digests';

    public function handle(FileStore $store): int
    {
        $ocfl = $store->ocfl();
        if (! $ocfl->initialized()) {
            $this->line('No OCFL storage root yet at '.$store->root().': nothing is kept.');

            return self::SUCCESS;
        }
        $errors = [];
        is_file($ocfl->root.'/ocfl_layout.json') || $errors[] = 'the storage root has no ocfl_layout.json';
        $ids = $ocfl->ids();
        foreach ($ids as $id) {
            array_push($errors, ...$ocfl->verify($id));
        }
        foreach ($errors as $error) {
            $this->error($error);
        }
        $this->line(count($ids).' objects checked, '.count($errors).' problems.');

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }
}
