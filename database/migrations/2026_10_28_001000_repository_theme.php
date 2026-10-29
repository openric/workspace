<?php

// OpenRiC Workspace - a repository's theme (governance/HERATIO-PARITY.md, "Repository", 28 October 2026).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// AtoM's and Heratio's edit-theme: a background colour, a banner, a logo and page content shown on the repository's
// public page. AtoM keeps them as settings scoped to the repository; here they are its columns. The page content is
// Markdown with any HTML removed, where AtoM takes HTML and inline CSS: the public page shows it to everyone.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->char('theme_background_colour', 7)->nullable();
            // Under the local disk, repository-theme/{id}/.
            $table->text('theme_banner_path')->nullable();
            $table->text('theme_logo_path')->nullable();
            $table->text('theme_content')->nullable();
        });
        DB::statement("ALTER TABLE repositories ADD CONSTRAINT repositories_theme_background_colour CHECK (theme_background_colour ~ '^#[0-9a-f]{6}$')");
    }

    public function down(): void
    {
        Schema::table('repositories', fn (Blueprint $table) => $table->dropColumn(['theme_background_colour', 'theme_banner_path', 'theme_logo_path', 'theme_content']));
    }
};
