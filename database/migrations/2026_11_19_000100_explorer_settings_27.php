<?php

// OpenRiC Workspace - the explorer settings of a record set (roadmap phase 27): the general words its explorer hides,
// the fewest units a name must be on, the year it prefers, and whether names proposed from the text but not yet reviewed
// show, above a least confidence (Heratio's explorer_hide, explorer_min_mentions and explorer_ner_min_confidence).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('explorer_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('record_resource_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('hide')->nullable();
            $table->unsignedSmallInteger('min_mentions')->default(1);
            $table->boolean('unreviewed')->default(false);
            $table->float('min_confidence')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('explorer_settings');
    }
};
