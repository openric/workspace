<?php

// OpenRiC Workspace - privacy in the catalogue (roadmap phase 18b, ADR 0016), after Heratio's ahg-privacy: scans of a
// record for personal information (ahg_pii_scan_report, with ahg_pii_finding_embedded's findings in files' metadata
// kept among its findings), the fields of a description redacted for the public (information_object_privacy_field),
// and where files must be screened before the public receives them (Heratio's settings privacy_screening_repositories
// and privacy_screening_collections, here rows). Statuses, coverage, types and patterns are vocabulary rows.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

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
        foreach (['pii-scan-status', 'pii-scan-coverage', 'redaction-type', 'redaction-pattern'] as $name) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$name.ttl")));
        }

        Schema::create('pii_scan_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->cascadeOnDelete();
            // The law the scan looked for, and the ground of a decision on the files.
            $table->foreignUuid('jurisdiction_id')->constrained('privacy_jurisdictions')->restrictOnDelete();
            // To the microsecond, so the latest of two scans in the same second is known.
            $table->timestampTz('started_at', 6);
            $table->timestampTz('finished_at', 6)->nullable();
            $table->integer('hits_total')->default(0);
            $table->jsonb('hits_by_type')->nullable();
            // At most 500: field, type, value, confidence.
            $table->jsonb('findings')->nullable();
            $table->foreignUuid('coverage_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('scanned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->index(['record_resource_id', 'started_at']);
        });
        DB::statement('ALTER TABLE pii_scan_reports ADD CONSTRAINT pii_scan_reports_hits CHECK (hits_total >= 0 AND (finished_at IS NULL OR finished_at >= started_at))');

        Schema::create('record_redactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->cascadeOnDelete();
            // The element's notation (title, scope-and-content, ...), as the description keeps it.
            $table->text('field');
            $table->foreignUuid('type_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('pattern_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->text('reason');
            // Heratio's legal_basis_reference and is_sensitive (special personal information, POPIA s.26).
            $table->text('legal_basis')->nullable();
            $table->boolean('is_sensitive')->default(false);
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['record_resource_id', 'field']);
        });

        Schema::create('privacy_screening_scopes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            // A repository's records, or a record and everything inside it.
            $table->foreignUuid('repository_id')->nullable()->constrained('repositories')->cascadeOnDelete();
            $table->foreignUuid('record_resource_id')->nullable()->constrained('record_resources')->cascadeOnDelete();
            $table->text('note')->nullable();
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['repository_id']);
            $table->unique(['record_resource_id']);
        });
        DB::statement('ALTER TABLE privacy_screening_scopes ADD CONSTRAINT privacy_screening_scopes_one CHECK ((repository_id IS NOT NULL)::int + (record_resource_id IS NOT NULL)::int = 1)');

        foreach ([['pii_scan_reports', 'coverage_id', 'pii-scan-coverage'], ['pii_scan_reports', 'status_id', 'pii-scan-status'],
            ['record_redactions', 'type_id', 'redaction-type'], ['record_redactions', 'pattern_id', 'redaction-pattern']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_screening_scopes');
        Schema::dropIfExists('record_redactions');
        Schema::dropIfExists('pii_scan_reports');
    }
};
