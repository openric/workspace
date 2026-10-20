<?php

// OpenRiC Workspace - an institution's header text and logo (settings).
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
            // Shown in the header of every page; empty means the product name.
            $table->string('header_title', 255)->nullable();
            // A path on the private disk and its media type; served by the Workspace, never as a static file.
            $table->string('logo_path', 255)->nullable();
            $table->string('logo_mime', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->dropColumn(['header_title', 'logo_path', 'logo_mime']);
        });
    }
};
