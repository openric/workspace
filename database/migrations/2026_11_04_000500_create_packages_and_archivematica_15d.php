<?php

// OpenRiC Workspace - OAIS information packages and Archivematica (roadmap phase 15d, ADR 0022), after Heratio's
// ahg-preservation packages (preservation_package, its objects and its events) and ahg-archivematica (am_job, am_link,
// and its settings). A package is the institution's; the Archivematica settings are the installation's, set by its
// operators, as replication targets are.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const VOCABULARIES = ['oais-package-type', 'package-status', 'package-format', 'package-object-role', 'archivematica-job-status', 'archivematica-direction',
        'archivematica-link-status'];

    private const CHECKED = [['packages', 'type_id', 'oais-package-type'], ['packages', 'status_id', 'package-status'], ['packages', 'format_id', 'package-format'],
        ['package_files', 'role_id', 'package-object-role'], ['package_events', 'event_type_id', 'premis-event-type'], ['package_events', 'outcome_id', 'premis-event-outcome'],
        ['archivematica_jobs', 'status_id', 'archivematica-job-status'], ['archivematica_jobs', 'direction_id', 'archivematica-direction'],
        ['archivematica_links', 'status_id', 'archivematica-link-status']];

    public function up(): void
    {
        foreach (self::VOCABULARIES as $name) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$name.ttl")));
        }
        Schema::create('packages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('record_resource_id')->nullable()->constrained('record_resources')->nullOnDelete();
            $table->text('name');
            $table->text('description')->nullable();
            $table->foreignUuid('type_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('format_id')->constrained('concepts')->restrictOnDelete();
            $table->text('bagit_version')->default('1.0');
            $table->text('manifest_algorithm')->default('sha256');
            $table->boolean('include_descendants')->default(true);
            $table->integer('object_count')->default(0);
            $table->bigInteger('total_size')->default(0);
            // The serialised bag, kept in an OCFL object of its own; its SHA-256 and size as kept.
            $table->text('storage_path')->nullable();
            $table->char('package_checksum', 64)->nullable();
            $table->bigInteger('package_size')->nullable();
            $table->text('originator')->nullable();
            $table->text('submission_agreement')->nullable();
            $table->text('retention_period')->nullable();
            $table->uuid('parent_package_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('built_at')->nullable();
            $table->timestampTz('validated_at')->nullable();
            $table->timestampTz('exported_at')->nullable();
            $table->timestampsTz();
            $table->index(['institution_id', 'created_at']);
        });
        DB::statement("ALTER TABLE packages ADD CONSTRAINT packages_algorithm CHECK (manifest_algorithm IN ('sha256', 'sha512'))");
        // The package it was made from (a SIP's AIP, an AIP's DIP), once the table has its key.
        Schema::table('packages', fn (Blueprint $table) => $table->foreign('parent_package_id')->references('id')->on('packages')->nullOnDelete());
        // A file in a package outlives the file it was copied from, as a PREMIS event does: no foreign key to it.
        Schema::create('package_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('package_id')->constrained('packages')->cascadeOnDelete();
            $table->uuid('digital_file_id')->nullable();
            $table->text('relative_path');
            $table->text('file_name');
            $table->bigInteger('file_size');
            $table->text('checksum_algorithm');
            $table->text('checksum_value');
            $table->text('mime_type')->nullable();
            $table->text('puid')->nullable();
            $table->foreignUuid('role_id')->constrained('concepts')->restrictOnDelete();
            $table->integer('sequence')->default(0);
            $table->timestampTz('created_at')->nullable();
            $table->unique(['package_id', 'relative_path']);
        });
        Schema::create('package_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('package_id')->constrained('packages')->cascadeOnDelete();
            $table->foreignUuid('event_type_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('outcome_id')->constrained('concepts')->restrictOnDelete();
            $table->text('detail')->nullable();
            $table->text('outcome_detail')->nullable();
            $table->text('agent_name');
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('occurred_at');
            $table->index(['package_id', 'occurred_at']);
        });
        // One row: the installation's Archivematica, its Dashboard and Storage Service (Heratio's ahg_settings am_* keys).
        Schema::create('archivematica_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('dashboard_url')->nullable();
            $table->text('dashboard_username')->nullable();
            $table->text('dashboard_api_key')->nullable();
            $table->text('storage_url')->nullable();
            $table->text('storage_username')->nullable();
            $table->text('storage_api_key')->nullable();
            $table->text('pipeline_uuid')->nullable();
            $table->text('transfer_source_location_uuid')->nullable();
            // The transfer source as Archivematica reads it, and the same folder as this server writes it.
            $table->text('transfer_source_path')->nullable();
            $table->text('staging_path')->nullable();
            $table->text('transfer_type')->default('standard');
            $table->text('dip_match_strategy')->default('identifier');
            // The folder a SWORD deposit may name by Content-Location, as Archivematica sends a DIP to AtoM.
            $table->text('deposit_path')->nullable();
            $table->boolean('fetch_dips')->default(false);
            $table->integer('timeout_seconds')->default(30);
            $table->timestampsTz();
        });
        Schema::create('archivematica_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('record_resource_id')->nullable()->constrained('record_resources')->nullOnDelete();
            $table->foreignUuid('direction_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->text('name')->nullable();
            $table->text('transfer_uuid')->nullable();
            $table->text('sip_uuid')->nullable();
            $table->text('aip_uuid')->nullable();
            $table->text('microservice')->nullable();
            $table->timestampTz('last_polled_at')->nullable();
            $table->text('error')->nullable();
            $table->jsonb('payload')->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['status_id', 'last_polled_at']);
        });
        Schema::create('archivematica_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->nullable()->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('record_resource_id')->nullable()->constrained('record_resources')->nullOnDelete();
            $table->text('transfer_uuid')->nullable();
            $table->text('sip_uuid')->nullable();
            $table->text('aip_uuid')->nullable();
            $table->text('dip_uuid')->nullable()->unique();
            $table->text('pipeline_uuid')->nullable();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->integer('files_taken')->default(0);
            $table->text('note')->nullable();
            $table->timestampsTz();
            $table->index('aip_uuid');
        });
        foreach (self::CHECKED as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', 'https://workspace.openric.org/vocab/{$vocabulary}/')");
        }
    }

    public function down(): void
    {
        foreach (['archivematica_links', 'archivematica_jobs', 'archivematica_settings', 'package_events', 'package_files', 'packages'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
