<?php

// OpenRiC Workspace - the interface language an account chose (WS-005; Heratio's user.preferred_locale).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A BCP 47 tag of config workspace.interface_languages, or null for the
// browser's choice. It is the interface's language only: the languages an
// account describes and translates in are its roles' and its own
// translation languages.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('interface_language', 35)->nullable());
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('interface_language'));
    }
};
