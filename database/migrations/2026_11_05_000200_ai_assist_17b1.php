<?php

// OpenRiC Workspace - asking a model for names, summaries and condition (roadmap phase 17b1): the model an institution
// summarises with, beside the vision models it reads pages with, and the job type the work runs as.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        app(SkosImporter::class)->import(file_get_contents(resource_path('vocabularies/job-type.ttl')));
        Schema::table('ai_settings', fn (Blueprint $table) => $table->string('chat_model', 200)->nullable());
    }

    public function down(): void
    {
        Schema::table('ai_settings', fn (Blueprint $table) => $table->dropColumn('chat_model'));
    }
};
