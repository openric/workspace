<?php

// OpenRiC Workspace - the length of a sound or video file in seconds, read by ffprobe, for its IIIF canvas (roadmap
// phase 14, ADR 0014; Dr Johan Pieterse's choice 1A of 3 November 2026).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('digital_files', fn (Blueprint $table) => $table->decimal('duration_seconds', 12, 3)->nullable());
    }

    public function down(): void
    {
        Schema::table('digital_files', fn (Blueprint $table) => $table->dropColumn('duration_seconds'));
    }
};
