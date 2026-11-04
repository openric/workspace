<?php

// OpenRiC Workspace - the Europeana EDM export and loans between union members (roadmap phase 16d), after Heratio's
// ahg-federation: each export of the institution's published records as EDM (ahg_europeana_export) and what it says of
// itself to Europeana (its data provider, country, language and default rights, Heratio's europeana_* settings); and
// requests to borrow an item between members of its union (federation_loan_request).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        app(SkosImporter::class)->import(file_get_contents(resource_path('vocabularies/loan-status.ttl')));
        Schema::table('federation_share_settings', function (Blueprint $table) {
            $table->text('europeana_data_provider')->nullable();
            $table->text('europeana_country')->nullable();
            $table->text('europeana_language')->nullable();
            // The rights statement given where a record has none in force, a RightsStatements.org or Creative Commons IRI.
            $table->text('europeana_default_rights')->nullable();
        });
        Schema::create('europeana_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->timestampTz('since')->nullable();
            $table->integer('record_count')->default(0);
            // The bundle of EDM files and their sitemap, kept in an OCFL object of its own.
            $table->text('storage_path')->nullable();
            $table->bigInteger('bundle_size')->nullable();
            $table->char('bundle_checksum', 64)->nullable();
            $table->text('error')->nullable();
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->index(['institution_id', 'started_at']);
        });
        Schema::create('federation_loan_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('requesting_member_id')->nullable()->constrained('federation_members')->nullOnDelete();
            $table->foreignUuid('holding_member_id')->nullable()->constrained('federation_members')->nullOnDelete();
            // The item: the institution's own unit where it is the holder, else the holder's reference for it.
            $table->foreignUuid('record_resource_id')->nullable()->constrained('record_resources')->nullOnDelete();
            $table->text('item_ref');
            $table->text('item_title');
            $table->text('purpose')->nullable();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->date('needed_from')->nullable();
            $table->date('needed_to')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['institution_id', 'status_id']);
        });
        DB::statement('ALTER TABLE federation_loan_requests ADD CONSTRAINT federation_loan_requests_window CHECK (needed_to IS NULL OR needed_from IS NULL OR needed_to >= needed_from)');
        DB::statement('ALTER TABLE federation_loan_requests ADD CONSTRAINT federation_loan_requests_two_members CHECK (requesting_member_id IS NULL OR holding_member_id IS NULL OR requesting_member_id <> holding_member_id)');
        foreach ([['europeana_exports', 'status_id', 'harvest-status'], ['federation_loan_requests', 'status_id', 'loan-status']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', 'https://workspace.openric.org/vocab/{$vocabulary}/')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('federation_loan_requests');
        Schema::dropIfExists('europeana_exports');
        Schema::table('federation_share_settings', fn (Blueprint $table) => $table->dropColumn(['europeana_data_provider', 'europeana_country', 'europeana_language', 'europeana_default_rights']));
    }
};
