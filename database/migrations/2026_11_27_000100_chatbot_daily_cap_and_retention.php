<?php

// OpenRiC Workspace - the public assistant's daily cap and a shorter default retention (AHG-AIIA-003, ASK-R2 and ASK-R3).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// An institution answers at most chatbot_daily_cap public questions a day with the model (200 unless an administrator
// sets another; 0 stops it), so a misuse of the open form cannot run up the institution's AI use. A new institution's
// conversations are deleted after 30 days, not 90. An institution's saved retention is left as it is.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->unsignedInteger('chatbot_daily_cap')->default(200);
            $table->unsignedSmallInteger('chatbot_retention_days')->default(30)->change();
        });
    }

    public function down(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->dropColumn('chatbot_daily_cap');
            $table->unsignedSmallInteger('chatbot_retention_days')->default(90)->change();
        });
    }
};
