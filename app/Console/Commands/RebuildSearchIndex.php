<?php

// OpenRiC Workspace - rebuild the full-text search index from the store (ADR 0009).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Console\Commands;

use App\Models\Institution;
use App\Search\SearchIndex;
use Illuminate\Console\Command;

class RebuildSearchIndex extends Command
{
    protected $signature = 'workspace:search:rebuild';

    protected $description = 'Index every described entity of every institution again, for full-text search';

    public function handle(SearchIndex $index): int
    {
        foreach (Institution::all() as $institution) {
            $this->info($institution->name.': '.$index->rebuild($institution).' entities indexed');
        }

        return self::SUCCESS;
    }
}
