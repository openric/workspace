<?php

// OpenRiC Workspace - make the copies for viewing of masters kept without them (WS-007).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// An upload makes a master's copies for viewing at once. A master kept
// before the Workspace made a kind of copy (a GLB 3D model's, from 0.0.34),
// or whose copies could not be made then, has only its master; this makes
// them as an upload would now. A master that already has a copy is left
// alone, so the command can be run again.

namespace App\Console\Commands;

use App\Description\InstantiationService;
use App\Models\Instantiation;
use Illuminate\Console\Command;

class MakeCopies extends Command
{
    protected $signature = 'workspace:copies';

    protected $description = 'Make the reference copy and thumbnail of every kept master that has none';

    public function handle(InstantiationService $instantiations): int
    {
        $looked = $made = 0;
        Instantiation::query()->whereNull('deleted_at')->whereHas('files')->with('files.use')->orderBy('id')
            ->chunkById(200, function ($chunk) use ($instantiations, &$looked, &$made) {
                foreach ($chunk as $instantiation) {
                    $looked++;
                    if ($added = $instantiations->makeCopies($instantiation)) {
                        $made += $added;
                        $this->line("Instantiation {$instantiation->id}: $added ".($added === 1 ? 'copy' : 'copies').' made of '.$instantiation->file('master')->original_name);
                    }
                }
            });
        $this->line("$looked instantiations with files looked at, $made copies made.");

        return self::SUCCESS;
    }
}
