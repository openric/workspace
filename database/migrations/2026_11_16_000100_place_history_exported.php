<?php

// OpenRiC Workspace - a place's history (RiC-CM 1.0.1 RiC-A21) is exported as rico:history, whose domain in RiC-O 1.1
// includes rico:Place; the description-element scope note is read in again to say so (openric/spec#73).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(SkosImporter::class)->import(file_get_contents(resource_path('vocabularies/description-element.ttl')));
    }

    public function down(): void
    {
        // The vocabulary rows stay: descriptions name them.
    }
};
