<?php

// OpenRiC Workspace - what the public catalogue says about itself on its home page.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// An introduction to the catalogue and its institution, shown first on the
// catalogue's home page, and the credits for data the institution reuses from
// others, shown with it: an open-data licence often requires a credit on each
// reuse. Both are plain text set in Settings; empty, nothing is shown.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->text('catalogue_introduction')->nullable();
            $table->text('catalogue_credits')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->dropColumn(['catalogue_introduction', 'catalogue_credits']);
        });
    }
};
