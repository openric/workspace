<?php

// OpenRiC Workspace - the provenance and custody history of a unit of description, after Heratio's
// ahg-information-object-manage provenance_overview, provenance_entry and provenance_document (PSIS's
// ahgProvenancePlugin before it; roadmap phase 9e, ADR 0010).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A step of the chain is a row of the record with its own persistent IRI, exported as a rico:Activity that affects the
// record, with its owner (an authority record, or a name) taking part. Its dates are EDTF, each exported as a Date of
// its own. The overview, one per record, is Heratio's governance header; documents go through the file store.

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

        Schema::create('provenance_overviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('record_resource_id')->unique()->constrained('record_resources')->cascadeOnDelete();
            $table->foreignUuid('current_status_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('custody_type_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('acquisition_type_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->date('acquisition_date')->nullable();
            $table->text('acquisition_date_text')->nullable();
            $table->decimal('acquisition_price', 15, 2)->nullable();
            $table->char('acquisition_currency', 3)->nullable();
            $table->foreignUuid('certainty_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->boolean('has_gaps')->default(false);
            $table->text('gap_description')->nullable();
            $table->foreignUuid('research_status_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->text('research_notes')->nullable();
            $table->boolean('nazi_era_checked')->default(false);
            $table->boolean('nazi_era_clear')->nullable();
            $table->text('nazi_era_notes')->nullable();
            $table->foreignUuid('cultural_property_status_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->text('cultural_property_notes')->nullable();
            $table->text('summary')->nullable();
            $table->boolean('is_complete')->default(false);
            $table->boolean('is_public')->default(true);
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        Schema::create('provenance_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('iri')->unique();
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->cascadeOnDelete();
            $table->integer('position');
            // The owner or holder: an authority record when there is one, and the name as the source gives it.
            $table->foreignUuid('owner_id')->nullable()->constrained('agents')->restrictOnDelete();
            $table->text('owner_name');
            $table->foreignUuid('owner_type_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->text('owner_location')->nullable();
            // A Getty Thesaurus of Geographic Names identifier.
            $table->string('owner_location_tgn', 100)->nullable();
            // EDTF, as the Workspace's dates are, with a qualifier from the date-qualifier vocabulary.
            $table->string('start_date', 64)->nullable();
            $table->foreignUuid('start_qualifier_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->string('end_date', 64)->nullable();
            $table->foreignUuid('end_qualifier_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('transfer_type_id')->constrained('concepts')->restrictOnDelete();
            $table->text('transfer_details')->nullable();
            $table->decimal('sale_price', 15, 2)->nullable();
            $table->char('sale_currency', 3)->nullable();
            $table->text('auction_house')->nullable();
            $table->string('auction_lot', 50)->nullable();
            $table->foreignUuid('certainty_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->text('sources')->nullable();
            $table->foreignUuid('evidence_type_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->text('evidence_description')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_gap')->default(false);
            $table->text('gap_explanation')->nullable();
            $table->timestampsTz();
            // A step is marked deleted, never removed, so its IRI keeps resolving.
            $table->timestampTz('deleted_at')->nullable();
            $table->index(['record_resource_id', 'position']);
            $table->index('owner_id');
        });
        Schema::create('provenance_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->cascadeOnDelete();
            // The step it evidences, when it evidences one.
            $table->foreignUuid('provenance_entry_id')->nullable()->constrained('provenance_entries')->nullOnDelete();
            $table->foreignUuid('document_type_id')->constrained('concepts')->restrictOnDelete();
            $table->text('title')->nullable();
            $table->text('description')->nullable();
            $table->date('document_date')->nullable();
            $table->text('document_date_text')->nullable();
            $table->text('external_url')->nullable();
            $table->text('archive_reference')->nullable();
            $table->boolean('is_public')->default(false);
            // The file, when one is uploaded, kept by the file store.
            $table->text('original_name')->nullable();
            $table->text('storage_path')->nullable();
            $table->text('mime_type')->nullable();
            $table->bigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->text('scanned_with')->nullable();
            $table->timestampTz('scanned_at')->nullable();
            $table->foreignUuid('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE provenance_overviews ADD CONSTRAINT provenance_overviews_price CHECK ((acquisition_price IS NULL OR acquisition_price >= 0) AND (acquisition_currency IS NULL OR acquisition_currency ~ '^[A-Z]{3}$'))");
        DB::statement("ALTER TABLE provenance_entries ADD CONSTRAINT provenance_entries_values CHECK (btrim(owner_name) <> '' AND position > 0 AND (sale_price IS NULL OR sale_price >= 0) AND (sale_currency IS NULL OR sale_currency ~ '^[A-Z]{3}$'))");
        DB::statement('ALTER TABLE provenance_documents ADD CONSTRAINT provenance_documents_source CHECK (storage_path IS NOT NULL OR external_url IS NOT NULL OR archive_reference IS NOT NULL)');
        DB::statement("ALTER TABLE provenance_documents ADD CONSTRAINT provenance_documents_sha256 CHECK (sha256 IS NULL OR sha256 ~ '^[0-9a-f]{64}$')");
        DB::statement('CREATE TRIGGER provenance_entries_iri_guard BEFORE UPDATE OR DELETE ON provenance_entries FOR EACH ROW EXECUTE FUNCTION persistent_iri_guard()');

        foreach ([['provenance_overviews', 'current_status_id', 'provenance-current-status'], ['provenance_overviews', 'custody_type_id', 'provenance-custody-type'],
            ['provenance_overviews', 'acquisition_type_id', 'provenance-acquisition-type'], ['provenance_overviews', 'certainty_id', 'provenance-certainty'],
            ['provenance_overviews', 'research_status_id', 'provenance-research-status'], ['provenance_overviews', 'cultural_property_status_id', 'cultural-property-status'],
            ['provenance_entries', 'owner_type_id', 'provenance-owner-type'], ['provenance_entries', 'start_qualifier_id', 'date-qualifier'],
            ['provenance_entries', 'end_qualifier_id', 'date-qualifier'], ['provenance_entries', 'transfer_type_id', 'provenance-transfer-type'],
            ['provenance_entries', 'certainty_id', 'provenance-certainty'], ['provenance_entries', 'evidence_type_id', 'provenance-evidence-type'],
            ['provenance_documents', 'document_type_id', 'provenance-document-type']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }
    }

    public function down(): void
    {
        foreach (['provenance_documents', 'provenance_entries', 'provenance_overviews'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
