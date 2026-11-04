<?php

// OpenRiC Workspace - when each institution's published RiC-O was last put in the SPARQL triple store (roadmap phase 16e),
// so the next sync takes only what changed since.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sparql_sync_state', function (Blueprint $table) {
            $table->foreignUuid('institution_id')->primary()->constrained('institutions')->cascadeOnDelete();
            $table->timestampTz('last_synced_at');
            $table->integer('last_put')->default(0);
            $table->integer('last_dropped')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sparql_sync_state');
    }
};
