<?php

// OpenRiC Workspace - saved clipboards (Heratio's clipboard_save and clipboard_save_item).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The clipboard itself lives in the session; saving it keeps its entries under a
// seven-digit clipboard ID anyone may load again later, as in Heratio. Entries
// are entities by table and id; loading keeps only those that still exist and
// that the reader may see.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clipboard_saves', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('password', 16)->unique();
            $table->timestampsTz();
            $table->index(['user_id', 'created_at']);
        });
        Schema::create('clipboard_save_items', function (Blueprint $table) {
            $table->foreignUuid('save_id')->constrained('clipboard_saves')->cascadeOnDelete();
            $table->string('subject_table', 64);
            $table->uuid('subject_id');
            $table->primary(['save_id', 'subject_table', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clipboard_save_items');
        Schema::dropIfExists('clipboard_saves');
    }
};
