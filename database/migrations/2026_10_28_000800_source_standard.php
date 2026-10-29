<?php

// OpenRiC Workspace - the source standard of a description (governance/HERATIO-PARITY.md, 28 October 2026).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// AtoM's and Heratio's information_object.source_standard and actor.source_standard: the standard the description
// was written to, such as ISAD(G), RAD or DACS, as text in the description control area, in no language.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['record_resources', 'agents'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->text('source_standard')->nullable());
        }
    }

    public function down(): void
    {
        foreach (['record_resources', 'agents'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('source_standard'));
        }
    }
};
