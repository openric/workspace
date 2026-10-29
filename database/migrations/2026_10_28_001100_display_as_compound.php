<?php

// OpenRiC Workspace - whether a description shows the units inside as one compound object (governance/HERATIO-PARITY.md, 28 October 2026).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// AtoM's and Heratio's displayAsCompoundObject ("View children as compound?"): when it is yes, the default as in
// Heratio, the public page shows the pictures of the units inside as one strip (the imageflow); no leaves them to be
// reached one by one.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('record_resources', fn (Blueprint $table) => $table->boolean('display_as_compound')->default(true));
    }

    public function down(): void
    {
        Schema::table('record_resources', fn (Blueprint $table) => $table->dropColumn('display_as_compound'));
    }
};
