<?php

// OpenRiC Workspace - scheduled, archived, shared and versioned reports (roadmap phase 8; WS-016).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// PSIS's ahgReportBuilderPlugin and Heratio's ahg-reports: report_schedule (how often, when, in which format, to
// whom), report_archive (each file made, kept to download again), report_share (a link that opens the report
// without signing in, until it expires) and report_version (a snapshot of the definition, to restore). Unlike
// PSIS, a schedule sends to accounts of the institution, never to typed addresses, and every share link expires.

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
        Schema::create('report_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_id')->constrained('custom_reports')->cascadeOnDelete();
            $table->foreignUuid('frequency_id')->constrained('concepts')->restrictOnDelete();
            // 1 (Monday) to 7 (Sunday), ISO 8601, for a weekly schedule; 1 to 31 for a monthly or quarterly one.
            $table->smallInteger('day_of_week')->nullable();
            $table->smallInteger('day_of_month')->nullable();
            $table->time('time_of_day')->default('08:00');
            // An export format of ReportExport: csv, xlsx, pdf or docx.
            $table->text('format');
            // The accounts of the institution it is sent to.
            $table->jsonb('recipients')->default('[]');
            $table->boolean('active')->default(true);
            $table->timestampTz('last_run_at')->nullable();
            $table->timestampTz('next_run_at');
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['active', 'next_run_at']);
        });
        Schema::create('report_archives', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_id')->constrained('custom_reports')->cascadeOnDelete();
            $table->foreignUuid('schedule_id')->nullable()->constrained('report_schedules')->nullOnDelete();
            $table->text('format');
            // Under the local disk, reports/{id}.{format}.
            $table->text('path');
            $table->bigInteger('size_bytes');
            $table->integer('entities');
            $table->foreignUuid('generated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('generated_at');
            $table->index(['report_id', 'generated_at']);
        });
        Schema::create('report_shares', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_id')->constrained('custom_reports')->cascadeOnDelete();
            $table->char('token', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->integer('opened')->default(0);
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        Schema::create('report_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_id')->constrained('custom_reports')->cascadeOnDelete();
            $table->integer('number');
            // {name, description, source, columns, filters, sort, shared, sections: [{type, title, content, config}]}
            $table->jsonb('snapshot');
            $table->text('summary')->nullable();
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at');
            $table->unique(['report_id', 'number']);
        });
        DB::statement("CREATE TRIGGER report_schedules_frequency_id_vocabulary BEFORE INSERT OR UPDATE ON report_schedules FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('frequency_id', '".self::VOCAB."report-frequency/')");
    }

    public function down(): void
    {
        Schema::dropIfExists('report_versions');
        Schema::dropIfExists('report_shares');
        Schema::dropIfExists('report_archives');
        Schema::dropIfExists('report_schedules');
    }
};
