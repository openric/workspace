<?php

// OpenRiC Workspace - South Africa's packs (roadmap phase 18d, ADR 0016): transfers to the National Archives and Records
// Service of South Africa, after Heratio's ahg-narssa (narssa_transfer, narssa_transfer_item); and permits to export
// heritage objects under the National Heritage Resources Act, 1999, s.32, after PSIS's ahgSAHRAPlugin (sahra_permit,
// sahra_permit_document), its export section only, by Dr Johan Pieterse's choice 2A of 2 November 2026.
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
        foreach (['narssa-transfer-status', 'export-permit-status'] as $name) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$name.ttl")));
        }
        $who = fn (Blueprint $table, string $column) => $table->foreignUuid($column)->nullable()->constrained('users')->nullOnDelete();

        Schema::create('narssa_transfers', function (Blueprint $table) use ($who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->string('reference', 64);
            $table->text('title');
            $table->text('description')->nullable();
            $table->text('schedule_codes')->nullable();
            $who($table, 'initiated_by_id');
            $table->integer('item_count')->default(0);
            $table->bigInteger('total_size_bytes')->default(0);
            $table->text('package_path')->nullable();
            $table->char('package_sha256', 64)->nullable();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->timestampTz('transmitted_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->text('receipt_reference')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->unique(['institution_id', 'reference']);
        });

        Schema::create('narssa_transfer_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('transfer_id')->constrained('narssa_transfers')->cascadeOnDelete();
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->restrictOnDelete();
            $table->foreignUuid('disposal_action_id')->nullable()->constrained('disposal_actions')->nullOnDelete();
            $table->text('reference_code')->nullable();
            $table->text('title_snapshot')->nullable();
            $table->text('schedule_code')->nullable();
            $table->integer('file_count')->default(0);
            $table->bigInteger('file_bytes')->default(0);
            $table->char('sha256', 64)->nullable();
            $table->timestampTz('created_at');
            $table->unique(['transfer_id', 'record_resource_id']);
        });

        Schema::create('export_permits', function (Blueprint $table) use ($who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->string('application_ref', 50);
            $table->text('permit_number')->nullable();
            // SAHRA, or a provincial heritage resources authority.
            $table->text('issuing_authority')->default('SAHRA');
            $table->foreignUuid('applicant_id')->constrained('users')->restrictOnDelete();
            $table->text('purpose');
            $table->text('description')->nullable();
            $table->text('destination_institution')->nullable();
            $table->char('destination_country', 2)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->text('conditions')->nullable();
            $table->decimal('fee_amount', 10, 2)->nullable();
            $table->boolean('fee_paid')->default(false);
            $table->text('fee_receipt')->nullable();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $who($table, 'approved_by_id');
            $table->timestampTz('approved_at')->nullable();
            $table->text('approval_notes')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->text('authority_reference')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->text('authority_notes')->nullable();
            $table->timestampsTz();
            $table->unique(['institution_id', 'application_ref']);
        });
        DB::statement('ALTER TABLE export_permits ADD CONSTRAINT export_permits_period_check CHECK (end_date IS NULL OR start_date IS NULL OR end_date >= start_date)');

        Schema::create('export_permit_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('permit_id')->constrained('export_permits')->cascadeOnDelete();
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->restrictOnDelete();
            $table->unique(['permit_id', 'record_resource_id']);
        });

        Schema::create('export_permit_documents', function (Blueprint $table) use ($who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('permit_id')->constrained('export_permits')->cascadeOnDelete();
            $table->text('original_name');
            $table->text('storage_path');
            $table->text('mime_type');
            $table->bigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->text('kind')->nullable();
            $who($table, 'uploaded_by_id');
            $table->timestampTz('created_at');
        });

        DB::statement("CREATE TRIGGER narssa_transfers_status_id_vocabulary BEFORE INSERT OR UPDATE ON narssa_transfers FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('status_id', '".self::VOCAB."narssa-transfer-status/')");
        DB::statement("CREATE TRIGGER export_permits_status_id_vocabulary BEFORE INSERT OR UPDATE ON export_permits FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('status_id', '".self::VOCAB."export-permit-status/')");
    }

    public function down(): void
    {
        foreach (['export_permit_documents', 'export_permit_records', 'export_permits', 'narssa_transfer_items', 'narssa_transfers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
