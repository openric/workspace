<?php

// OpenRiC Workspace - the search statistics' log (WS-016, roadmap phase 6), after Heratio's ahg_search_query_log.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// What was searched and how many it found; nothing about who searched: no
// account, no address, not even a hash of one.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_queries', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->boolean('public');
            $table->string('query', 512);
            $table->jsonb('filters')->nullable();
            $table->integer('result_count');
            $table->integer('response_ms');
            $table->timestampTz('executed_at')->useCurrent();
            $table->index(['institution_id', 'executed_at']);
            $table->index(['institution_id', 'result_count']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_queries');
    }
};
