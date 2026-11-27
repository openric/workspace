<?php

// OpenRiC Workspace - the catalogue assistant's conversations are kept 90 days by default again (Dr Johan Pieterse,
// 27 November 2026), the period configurable with AI_CHAT_RETENTION_DAYS; v0.0.170 had made the column's default 30.
// No institution's saved period changes.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_settings', fn (Blueprint $table) => $table->unsignedSmallInteger('chatbot_retention_days')->default(90)->change());
    }

    public function down(): void
    {
        Schema::table('ai_settings', fn (Blueprint $table) => $table->unsignedSmallInteger('chatbot_retention_days')->default(30)->change());
    }
};
