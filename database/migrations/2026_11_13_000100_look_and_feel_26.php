<?php

// OpenRiC Workspace - look and feel (roadmap phase 26): the colour an institution gives its public catalogue, the text of its
// footer (an address, contact details, opening hours), and which of its static pages its footer lists, in what order.
// About, privacy and terms, which the footer listed before, start in it.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->string('brand_colour', 7)->nullable();
            $table->text('footer_text')->nullable();
        });
        Schema::table('static_pages', function (Blueprint $table) {
            $table->smallInteger('footer_position')->nullable();
        });
        foreach (['about' => 1, 'privacy' => 2, 'terms' => 3] as $slug => $position) {
            DB::table('static_pages')->where('slug', $slug)->update(['footer_position' => $position]);
        }
    }

    public function down(): void
    {
        Schema::table('static_pages', fn (Blueprint $table) => $table->dropColumn('footer_position'));
        Schema::table('institutions', fn (Blueprint $table) => $table->dropColumn(['brand_colour', 'footer_text']));
    }
};
