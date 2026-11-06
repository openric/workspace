<?php

// OpenRiC Workspace - subject suggestions by Annif (roadmap phase 17e; Dr Johan Pieterse's choice of 3 November 2026):
// each institution names its Annif project behind the AI gateway, the vocabulary its suggestions come from, how many
// and how sure; a suggestion is a proposed subject access point (the proposal kind subject), applied only when a
// reviewer accepts it; accepted subjects are taught back to the project; and each trial against the subjects already
// given to published records is kept, so its precision is known before it is relied on.
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
        app(SkosImporter::class)->import(file_get_contents(resource_path('vocabularies/proposal-kind.ttl')));

        Schema::table('ai_settings', function (Blueprint $table) {
            // The Annif project behind the gateway, such as openric-riverside-subjects; empty means no suggestions.
            $table->string('annif_project', 100)->nullable();
            $table->foreignUuid('annif_vocabulary_id')->nullable()->constrained('vocabularies')->nullOnDelete();
            $table->smallInteger('annif_limit')->default(5);
            $table->decimal('annif_threshold', 3, 2)->default(0.20);
            // Accepted subjects taught to the project up to this moment.
            $table->timestampTz('annif_learned_at')->nullable();
        });

        Schema::create('annif_trials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->string('project', 100);
            $table->unsignedInteger('records');
            $table->unsignedSmallInteger('k');
            // Of what Annif suggested, how much was a subject the record already has; and of those it has, how much was suggested.
            $table->decimal('precision', 5, 4);
            $table->decimal('recall', 5, 4);
            $table->jsonb('details');
            $table->foreignUuid('run_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('run_at');
            $table->index(['institution_id', 'run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annif_trials');
        Schema::table('ai_settings', fn (Blueprint $table) => $table->dropConstrainedForeignId('annif_vocabulary_id'));
        Schema::table('ai_settings', fn (Blueprint $table) => $table->dropColumn(['annif_project', 'annif_limit', 'annif_threshold', 'annif_learned_at']));
    }
};
