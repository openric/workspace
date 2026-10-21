<?php

// OpenRiC Workspace - a repository whose holdings carry complete reference codes.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// As AtoM's "inherit reference code" setting, but per repository: some archives (the Archives nationales de France)
// give every unit its whole code, so a unit's reference code is the repository code and its own identifier, not the
// identifiers of the units above it joined together.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->boolean('complete_reference_codes')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->dropColumn('complete_reference_codes');
        });
    }
};
