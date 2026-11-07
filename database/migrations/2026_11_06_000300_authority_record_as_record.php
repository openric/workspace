<?php

// OpenRiC Workspace - an agent's authority record exported as a rico:Record of its own, of the documentary form
// Authority record (the record-documentary-form vocabulary), after Richard Williamson's advice (EGAD) on the Records
// in Contexts users list of 6 November 2026; and the scope notes of the ISAAR(CPF) control elements, which now say so.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['record-documentary-form', 'description-element'] as $vocabulary) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$vocabulary.ttl")));
        }
    }

    public function down(): void
    {
        // The vocabulary rows stay: descriptions may name them.
    }
};
