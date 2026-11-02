<?php

// OpenRiC Workspace - an activity described as an ISDF function (roadmap phase 24).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// RiC-CM 1.0.1 makes a function an Activity: the entity covers functions, activities and transactions alike, and the
// Workspace's activities are described with ISDF's elements already. The Digital Object Linkage profile serves
// functions at /functions as openricx:Function (mapping section 6.4). Rather than a second kind that would duplicate
// activities, an activity is marked as a function; it is then served there, and not as a dated event at /activities.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', fn (Blueprint $table) => $table->boolean('is_function')->default(false));
    }

    public function down(): void
    {
        Schema::table('activities', fn (Blueprint $table) => $table->dropColumn('is_function'));
    }
};
