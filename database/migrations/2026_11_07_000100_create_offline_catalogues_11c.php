<?php

// OpenRiC Workspace - offline catalogues (roadmap phase 11c), after Heratio's ahg-portable-export (and PSIS's
// ahgPortableExportPlugin before it): a package of descriptions, authority records, repositories and files, with a
// viewer that opens from a USB stick without a server, its checksums and what it withholds; what it is for (read only,
// editable, archive) a vocabulary row; built in the background; links that let someone without an account download
// it, of which only the hash of the token is kept, perhaps ending on a date or after a number of downloads; making
// them a permission.
// Changed from Heratio: the scope names a repository, a unit or the clipboard by its id rather than a slug; the mode is
// a concept, the status the background job's (job-status); a package is kept in the object store, and purged from it
// when deleted.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Access\RoleService;
use App\Models\Institution;
use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['offline-catalogue-mode', 'job-status', 'job-type', 'permission'] as $vocabulary) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$vocabulary.ttl")));
        }

        Schema::create('offline_catalogues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 255);
            $table->foreignUuid('mode_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            // The scope: one repository, one unit and everything inside it, or the clipboard's units as they were; none,
            // the whole catalogue.
            $table->foreignUuid('repository_id')->nullable()->constrained('repositories')->nullOnDelete();
            $table->foreignUuid('record_resource_id')->nullable()->constrained('record_resources')->nullOnDelete();
            $table->json('scope_items')->nullable();
            // The language values are shown in where a unit is described in several; none, each unit's original language.
            $table->string('language', 35)->nullable();
            $table->boolean('include_files')->default(true);
            $table->boolean('include_masters')->default(false);
            $table->foreignUuid('background_job_id')->nullable()->constrained('background_jobs')->nullOnDelete();
            $table->integer('record_count')->default(0);
            $table->integer('agent_count')->default(0);
            $table->integer('file_count')->default(0);
            $table->integer('withheld_count')->default(0);
            $table->string('storage_path', 1024)->nullable();
            $table->bigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->index(['institution_id', 'created_at']);
        });

        // Links to a package for someone without an account (Heratio's portable_export_share_token).
        Schema::create('offline_catalogue_shares', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('offline_catalogue_id')->constrained('offline_catalogues')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('label', 255)->nullable();
            $table->integer('max_downloads')->nullable();
            $table->integer('download_count')->default(0);
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('last_downloaded_at')->nullable();
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        foreach (Institution::all() as $institution) {
            DB::transaction(fn () => app(RoleService::class)->grantKind($institution, 'institution', ['make-offline-catalogues']));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_catalogue_shares');
        Schema::dropIfExists('offline_catalogues');
    }
};
