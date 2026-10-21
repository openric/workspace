<?php

// OpenRiC Workspace - languages an account may translate into, besides those of its roles.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// As Heratio's per-user translate languages: a BCP 47 tag per row. An account
// translates into the languages of its roles and these.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_translation_languages', function (Blueprint $table) {
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('language', 35);
            $table->primary(['user_id', 'language']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_translation_languages');
    }
};
