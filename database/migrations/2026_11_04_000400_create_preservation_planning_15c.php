<?php

// OpenRiC Workspace - preservation planning (roadmap phase 15c, ADR 0022), after Heratio's ahg-preservation: the
// normalisation rules (its preservation_normalization_rule, Archivematica's format policy registry), each conversion
// made (preservation_format_conversion), the migration pathways between formats (preservation_migration_pathway), the
// migration plans an institution runs (preservation_migration_plan and its objects), and the replication targets the
// object store is copied to, with each run (preservation_replication_target, preservation_replication_log).
// Rules, pathways and targets are the installation's, set by its operators, as file formats are; conversions and plans
// are each institution's.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const VOCABULARIES = ['file-use', 'normalisation-purpose', 'conversion-tool', 'conversion-status', 'migration-plan-status', 'migration-quality-impact',
        'replication-target-type', 'replication-operation', 'replication-status'];

    private const CHECKED = [['normalisation_rules', 'purpose_id', 'normalisation-purpose'], ['normalisation_rules', 'tool_id', 'conversion-tool'],
        ['format_conversions', 'tool_id', 'conversion-tool'], ['format_conversions', 'status_id', 'conversion-status'],
        ['migration_pathways', 'tool_id', 'conversion-tool'], ['migration_pathways', 'quality_impact_id', 'migration-quality-impact'],
        ['migration_plans', 'status_id', 'migration-plan-status'], ['migration_plan_files', 'status_id', 'conversion-status'],
        ['replication_targets', 'type_id', 'replication-target-type'], ['replication_targets', 'frequency_id', 'fixity-frequency'],
        ['replication_targets', 'last_status_id', 'replication-status'],
        ['replication_runs', 'operation_id', 'replication-operation'], ['replication_runs', 'status_id', 'replication-status']];

    /** Heratio's default rules, as its service provider seeds them. */
    private const RULES = [
        ['image/jpeg', 'TIFF', 'tiff', 'image/tiff', 'imagemagick', 'preservation'], ['image/png', 'TIFF', 'tiff', 'image/tiff', 'imagemagick', 'preservation'],
        ['image/gif', 'TIFF', 'tiff', 'image/tiff', 'imagemagick', 'preservation'], ['image/bmp', 'TIFF', 'tiff', 'image/tiff', 'imagemagick', 'preservation'],
        ['application/pdf', 'PDF/A', 'pdf', 'application/pdf', 'ghostscript', 'preservation'],
        ['application/msword', 'PDF', 'pdf', 'application/pdf', 'libreoffice', 'preservation'],
        ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'PDF', 'pdf', 'application/pdf', 'libreoffice', 'preservation'],
        ['application/vnd.ms-excel', 'PDF', 'pdf', 'application/pdf', 'libreoffice', 'preservation'],
        ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'PDF', 'pdf', 'application/pdf', 'libreoffice', 'preservation'],
        ['application/vnd.ms-powerpoint', 'PDF', 'pdf', 'application/pdf', 'libreoffice', 'preservation'],
        ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'PDF', 'pdf', 'application/pdf', 'libreoffice', 'preservation'],
        ['application/vnd.oasis.opendocument.text', 'PDF', 'pdf', 'application/pdf', 'libreoffice', 'preservation'],
        ['audio/mpeg', 'WAV', 'wav', 'audio/x-wav', 'ffmpeg', 'preservation'], ['audio/ogg', 'WAV', 'wav', 'audio/x-wav', 'ffmpeg', 'preservation'],
        ['audio/aac', 'WAV', 'wav', 'audio/x-wav', 'ffmpeg', 'preservation'],
        ['video/mp4', 'MKV', 'mkv', 'video/x-matroska', 'ffmpeg', 'preservation'], ['video/quicktime', 'MKV', 'mkv', 'video/x-matroska', 'ffmpeg', 'preservation'],
        ['video/x-msvideo', 'MKV', 'mkv', 'video/x-matroska', 'ffmpeg', 'preservation'],
        ['image/tiff', 'JPEG', 'jpg', 'image/jpeg', 'imagemagick', 'access'], ['image/png', 'JPEG', 'jpg', 'image/jpeg', 'imagemagick', 'access'],
        ['image/bmp', 'JPEG', 'jpg', 'image/jpeg', 'imagemagick', 'access'],
        ['application/msword', 'PDF', 'pdf', 'application/pdf', 'libreoffice', 'access'],
        ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'PDF', 'pdf', 'application/pdf', 'libreoffice', 'access'],
        ['application/vnd.ms-excel', 'PDF', 'pdf', 'application/pdf', 'libreoffice', 'access'],
        ['application/vnd.ms-powerpoint', 'PDF', 'pdf', 'application/pdf', 'libreoffice', 'access'],
        ['application/vnd.oasis.opendocument.text', 'PDF', 'pdf', 'application/pdf', 'libreoffice', 'access'],
        ['audio/x-wav', 'MP3', 'mp3', 'audio/mpeg', 'ffmpeg', 'access'], ['audio/wav', 'MP3', 'mp3', 'audio/mpeg', 'ffmpeg', 'access'],
        ['video/x-matroska', 'MP4', 'mp4', 'video/mp4', 'ffmpeg', 'access'], ['video/quicktime', 'MP4', 'mp4', 'video/mp4', 'ffmpeg', 'access'],
    ];

    public function up(): void
    {
        foreach (self::VOCABULARIES as $name) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$name.ttl")));
        }
        Schema::create('normalisation_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('source_puid')->nullable();
            $table->text('source_mime')->nullable();
            $table->foreignUuid('purpose_id')->constrained('concepts')->restrictOnDelete();
            $table->text('target_format');
            $table->text('target_ext');
            $table->text('target_mime');
            $table->foreignUuid('tool_id')->constrained('concepts')->restrictOnDelete();
            $table->jsonb('options')->nullable();
            $table->integer('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->index(['source_mime', 'is_active']);
        });
        DB::statement('ALTER TABLE normalisation_rules ADD CONSTRAINT normalisation_rules_source CHECK (source_puid IS NOT NULL OR source_mime IS NOT NULL)');
        DB::statement("ALTER TABLE normalisation_rules ADD CONSTRAINT normalisation_rules_ext CHECK (target_ext ~ '^[a-z0-9]{1,8}$')");
        Schema::create('migration_pathways', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('source_puid');
            $table->text('target_puid');
            $table->foreignUuid('tool_id')->constrained('concepts')->restrictOnDelete();
            $table->text('target_ext');
            $table->text('target_mime');
            $table->jsonb('options')->nullable();
            $table->foreignUuid('quality_impact_id')->constrained('concepts')->restrictOnDelete();
            $table->integer('fidelity_score')->nullable();
            $table->boolean('is_recommended')->default(false);
            $table->boolean('is_automated')->default(false);
            $table->integer('priority')->default(100);
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->unique(['source_puid', 'target_puid', 'tool_id']);
        });
        DB::statement("ALTER TABLE migration_pathways ADD CONSTRAINT migration_pathways_ext CHECK (target_ext ~ '^[a-z0-9]{1,8}$')");
        DB::statement('ALTER TABLE migration_pathways ADD CONSTRAINT migration_pathways_fidelity CHECK (fidelity_score IS NULL OR fidelity_score BETWEEN 0 AND 100)');
        Schema::table('file_formats', function (Blueprint $table) {
            // The format its files are to be migrated to (Heratio's preservation_format.migration_target_id).
            $table->foreignUuid('migration_target_id')->nullable()->constrained('file_formats')->nullOnDelete();
            $table->text('extension')->nullable();
            $table->text('risk_notes')->nullable();
            $table->timestampTz('risk_assessed_at')->nullable();
        });
        Schema::create('migration_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->text('name');
            $table->text('description')->nullable();
            $table->text('source_puid');
            $table->text('target_puid');
            $table->foreignUuid('pathway_id')->constrained('migration_pathways')->restrictOnDelete();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            // Its scope: the institution's files of the source format, or those of one repository or one unit and what it includes.
            $table->foreignUuid('repository_id')->nullable()->constrained('repositories')->cascadeOnDelete();
            $table->foreignUuid('record_resource_id')->nullable()->constrained('record_resources')->cascadeOnDelete();
            $table->boolean('run_fixity_after')->default(true);
            $table->timestampTz('scheduled_start')->nullable();
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
        });
        // A conversion outlives the files it was made from and into, as a PREMIS event does: no foreign key to them.
        Schema::create('format_conversions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->uuid('instantiation_id')->nullable();
            $table->uuid('source_file_id')->nullable();
            $table->uuid('output_file_id')->nullable();
            $table->foreignUuid('rule_id')->nullable()->constrained('normalisation_rules')->nullOnDelete();
            $table->foreignUuid('pathway_id')->nullable()->constrained('migration_pathways')->nullOnDelete();
            $table->foreignUuid('migration_plan_id')->nullable()->constrained('migration_plans')->nullOnDelete();
            $table->foreignUuid('purpose_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->text('source_format')->nullable();
            $table->text('source_mime_type')->nullable();
            $table->text('target_format');
            $table->text('target_mime_type');
            $table->foreignUuid('tool_id')->constrained('concepts')->restrictOnDelete();
            $table->text('tool_version')->nullable();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->bigInteger('source_size')->nullable();
            $table->char('source_checksum', 64)->nullable();
            $table->bigInteger('output_size')->nullable();
            $table->char('output_checksum', 64)->nullable();
            $table->jsonb('options')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['institution_id', 'created_at']);
            $table->index('source_file_id');
        });
        Schema::create('migration_plan_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained('migration_plans')->cascadeOnDelete();
            $table->uuid('digital_file_id');
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('conversion_id')->nullable()->constrained('format_conversions')->nullOnDelete();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->integer('retry_count')->default(0);
            $table->timestampsTz();
            $table->unique(['plan_id', 'digital_file_id']);
        });
        Schema::create('replication_targets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('name');
            $table->text('description')->nullable();
            $table->foreignUuid('type_id')->constrained('concepts')->restrictOnDelete();
            // Heratio's connection_config, as columns: no password is kept, an SSH key file is named instead.
            $table->text('path');
            $table->text('host')->nullable();
            $table->text('username')->nullable();
            $table->integer('port')->nullable();
            $table->text('ssh_key_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('frequency_id')->constrained('concepts')->restrictOnDelete();
            $table->timestampTz('last_sync_at')->nullable();
            $table->timestampTz('next_sync_at')->nullable();
            $table->foreignUuid('last_status_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->integer('last_sync_files')->nullable();
            $table->bigInteger('last_sync_bytes')->nullable();
            $table->timestampsTz();
        });
        Schema::create('replication_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('target_id')->constrained('replication_targets')->cascadeOnDelete();
            $table->foreignUuid('operation_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            foreach (['files_total', 'files_synced', 'files_failed'] as $column) {
                $table->integer($column)->default(0);
            }
            $table->bigInteger('bytes_transferred')->default(0);
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->text('error_message')->nullable();
            $table->jsonb('details')->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['target_id', 'started_at']);
        });
        Schema::table('institutions', function (Blueprint $table) {
            // Whether a file kept is normalised by the rules at once (Heratio normalises at ingest); off until chosen.
            $table->boolean('normalise_on_ingest')->default(false);
        });
        foreach (self::CHECKED as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', 'https://workspace.openric.org/vocab/{$vocabulary}/')");
        }
        $concept = fn (string $iri) => DB::table('concepts')->where('iri', 'https://workspace.openric.org/vocab/'.$iri)->value('id');
        foreach (self::RULES as [$mime, $format, $ext, $targetMime, $tool, $purpose]) {
            DB::table('normalisation_rules')->insert(['id' => (string) Str::uuid7(), 'source_mime' => $mime, 'purpose_id' => $concept("normalisation-purpose/$purpose"),
                'target_format' => $format, 'target_ext' => $ext, 'target_mime' => $targetMime, 'tool_id' => $concept("conversion-tool/$tool"), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('institutions', fn (Blueprint $table) => $table->dropColumn('normalise_on_ingest'));
        foreach (['replication_runs', 'replication_targets', 'migration_plan_files', 'format_conversions', 'migration_plans'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('file_formats', function (Blueprint $table) {
            $table->dropConstrainedForeignId('migration_target_id');
            $table->dropColumn(['extension', 'risk_notes', 'risk_assessed_at']);
        });
        Schema::dropIfExists('migration_pathways');
        Schema::dropIfExists('normalisation_rules');
    }
};
