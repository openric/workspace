<?php

// OpenRiC Workspace - background jobs (roadmap phase 5), after Heratio's job and ahg_job_execution tables (ahg-jobs).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// What a person asked to be done in the background, who asked, how far it got and how it
// ended. Laravel's own jobs table is the queue; this is the record people read. The type
// and the status are vocabulary concepts.

use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const VOCAB = 'https://workspace.openric.org/vocab/';

    public function up(): void
    {
        foreach (config('workspace.bundled_vocabularies') as $file) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$file")));
        }

        Schema::create('background_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('type_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->text('name');
            // What the job works on, such as an import or an instantiation.
            $table->text('subject_table')->nullable();
            $table->uuid('subject_id')->nullable();
            $table->jsonb('output')->nullable();
            $table->text('error_message')->nullable();
            $table->text('stack_trace')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->integer('duration')->nullable();
            $table->timestampsTz();
            $table->index(['institution_id', 'created_at']);
            $table->index(['subject_table', 'subject_id']);
        });
        foreach ([['type_id', 'job-type/'], ['status_id', 'job-status/']] as [$column, $vocabulary]) {
            DB::statement("CREATE TRIGGER background_jobs_{$column}_vocabulary BEFORE INSERT OR UPDATE ON background_jobs FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB.$vocabulary."')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('background_jobs');
    }
};
