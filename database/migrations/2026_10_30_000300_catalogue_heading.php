<?php

// OpenRiC Workspace - the heading of the catalogue's home page and whether it shows the counts of what is published,
// as the institution sets them beside its introduction.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            // Empty: "The catalogue of" the institution's name.
            $table->text('catalogue_heading')->nullable();
            $table->boolean('catalogue_show_counts')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('institutions', fn (Blueprint $table) => $table->dropColumn(['catalogue_heading', 'catalogue_show_counts']));
    }
};
