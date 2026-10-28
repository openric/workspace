<?php

// OpenRiC Workspace - a repository's upload limit (governance/HERATIO-PARITY.md, "Repository", 28 October 2026).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// AtoM's and Heratio's repository.upload_limit, in gigabytes of 1 000 000 000 bytes. AtoM writes -1 for no limit;
// here no limit is no value, and 0 still switches uploads to the repository off.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repositories', fn (Blueprint $table) => $table->decimal('upload_limit_gb', 12, 3)->nullable());
        DB::statement('ALTER TABLE repositories ADD CONSTRAINT repositories_upload_limit CHECK (upload_limit_gb >= 0)');
    }

    public function down(): void
    {
        Schema::table('repositories', fn (Blueprint $table) => $table->dropColumn('upload_limit_gb'));
    }
};
