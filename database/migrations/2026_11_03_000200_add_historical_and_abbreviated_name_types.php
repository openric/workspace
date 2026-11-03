<?php

// OpenRiC Workspace - historical and abbreviated forms of name, narrower than other forms, after Croatia's eArhiv, and
// Afrikaans labels for the name types (Dr Johan Pieterse, 3 November 2026).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(SkosImporter::class)->import(file_get_contents(resource_path('vocabularies/name-type.ttl')));
    }

    public function down(): void
    {
        // The two concepts stay: names may already be of their types.
    }
};
