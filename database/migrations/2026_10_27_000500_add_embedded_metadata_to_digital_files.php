<?php

// OpenRiC Workspace - what a file says of itself: EXIF, IPTC, XMP and PDF metadata read with exiftool (roadmap phase 5).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Kept on the master as received, tag => value; null until it is read, {} when the
// file carries none. What a description can use is proposed for review, not written.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('digital_files', function (Blueprint $table) {
            $table->jsonb('embedded_metadata')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('digital_files', function (Blueprint $table) {
            $table->dropColumn('embedded_metadata');
        });
    }
};
