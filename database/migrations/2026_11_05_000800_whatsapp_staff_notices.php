<?php

// OpenRiC Workspace - staff notices on WhatsApp (5 November 2026, after CallHub's WhatsAppNotifier): each staff account
// may give its own WhatsApp number and ask for the notices the Workspace mails it to come there too. Nothing is sent
// until the installation switches WhatsApp on (config workspace.whatsapp).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('whatsapp_number', 32)->nullable();
            $table->boolean('whatsapp_notices')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['whatsapp_number', 'whatsapp_notices']));
    }
};
