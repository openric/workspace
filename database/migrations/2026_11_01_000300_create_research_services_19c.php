<?php

// OpenRiC Workspace - research services, part 19c (roadmap phase 19, ADR 0017): reproductions, bibliographies, the
// citation log and saved searches, after Heratio's ahg-research: research_reproduction_request,
// research_reproduction_item, research_reproduction_file (and research_request_status_history for them),
// research_bibliography, research_bibliography_entry, research_citation_log, research_saved_search and
// research_search_alert_log. Reproduction prices are reference data of the institution, seeded with Heratio's.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Institution;
use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const VOCAB = 'https://workspace.openric.org/vocab/';

    /** Heratio's default reproduction prices: a base, and so much a unit. */
    public const PRICES = ['photocopy' => [2.00, 0.50, 'page'], 'scan' => [5.00, 1.00, 'page'], 'photograph' => [15.00, 5.00, 'image'],
        'digital-copy' => [10.00, 2.00, 'file'], 'transcription' => [50.00, 25.00, 'page'], 'certification' => [25.00, 10.00, 'document']];

    public function up(): void
    {
        foreach (['reproduction-status', 'reproduction-type', 'colour-mode', 'reproduction-item-status', 'delivery-method', 'bibliography-entry-type', 'citation-style', 'alert-frequency'] as $name) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$name.ttl")));
        }
        $concept = fn (Blueprint $table, string $column, bool $nullable = false) => $nullable
            ? $table->foreignUuid($column)->nullable()->constrained('concepts')->restrictOnDelete()
            : $table->foreignUuid($column)->constrained('concepts')->restrictOnDelete();
        $who = fn (Blueprint $table, string $column) => $table->foreignUuid($column)->nullable()->constrained('users')->nullOnDelete();

        Schema::create('reproduction_prices', function (Blueprint $table) use ($concept) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $concept($table, 'reproduction_type_id');
            $table->decimal('base_price', 10, 2)->default(0);
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->text('unit')->default('page');
            $table->timestampsTz();
            $table->unique(['institution_id', 'reproduction_type_id']);
        });

        Schema::create('reproduction_requests', function (Blueprint $table) use ($concept, $who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('researcher_id')->constrained('researchers')->cascadeOnDelete();
            $table->text('reference')->unique();
            $table->text('purpose')->nullable();
            $table->text('intended_use')->nullable();
            $table->text('publication_details')->nullable();
            $concept($table, 'status_id');
            $table->decimal('estimated_cost', 10, 2)->nullable();
            $table->decimal('final_cost', 10, 2)->nullable();
            $table->string('currency', 3)->default('ZAR');
            $table->text('payment_reference')->nullable();
            $table->date('payment_date')->nullable();
            $table->text('payment_method')->nullable();
            $table->text('invoice_number')->nullable();
            $table->date('invoice_date')->nullable();
            $concept($table, 'delivery_method_id');
            $table->text('delivery_address')->nullable();
            $table->text('delivery_email')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $who($table, 'processed_by_id');
            $table->text('notes')->nullable();
            $table->text('admin_notes')->nullable();
            $table->date('sla_due_on')->nullable();
            $who($table, 'assigned_to_id');
            $table->timestampTz('closed_at')->nullable();
            $who($table, 'closed_by_id');
            $table->text('closure_reason')->nullable();
            $table->timestampsTz();
        });

        Schema::create('reproduction_items', function (Blueprint $table) use ($concept) {
            $table->uuid('id')->primary();
            $table->foreignUuid('reproduction_request_id')->constrained('reproduction_requests')->cascadeOnDelete();
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->restrictOnDelete();
            // A particular file of the record, when the researcher chose one.
            $table->foreignUuid('digital_file_id')->nullable()->constrained('digital_files')->nullOnDelete();
            $concept($table, 'reproduction_type_id');
            $table->text('format')->nullable();
            $table->text('resolution')->nullable();
            $concept($table, 'colour_mode_id', true);
            $table->integer('quantity')->default(1);
            $table->text('page_range')->nullable();
            $table->text('special_instructions')->nullable();
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->decimal('total_price', 10, 2)->nullable();
            $concept($table, 'status_id');
            $table->timestampTz('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE reproduction_items ADD CONSTRAINT reproduction_items_quantity CHECK (quantity BETWEEN 1 AND 10000)');

        Schema::create('reproduction_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('reproduction_item_id')->constrained('reproduction_items')->cascadeOnDelete();
            $table->foreignUuid('research_file_id')->constrained('research_files')->restrictOnDelete();
            $table->string('download_token_hash', 64)->unique();
            $table->timestampTz('download_expires_at')->nullable();
            $table->integer('download_count')->default(0);
            $table->timestampsTz();
        });

        Schema::create('reproduction_status_changes', function (Blueprint $table) use ($who) {
            $table->uuid('id')->primary();
            $table->foreignUuid('reproduction_request_id')->constrained('reproduction_requests')->cascadeOnDelete();
            $table->text('old_status')->nullable();
            $table->text('new_status');
            $who($table, 'changed_by_id');
            $table->text('notes')->nullable();
            $table->timestampTz('created_at');
        });

        Schema::create('bibliographies', function (Blueprint $table) use ($concept) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('name');
            $table->text('description')->nullable();
            $concept($table, 'citation_style_id');
            $table->boolean('is_public')->default(false);
            $table->string('share_token_hash', 64)->nullable()->unique();
            $table->timestampsTz();
        });

        Schema::create('bibliography_entries', function (Blueprint $table) use ($concept) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bibliography_id')->constrained('bibliographies')->cascadeOnDelete();
            $table->foreignUuid('record_resource_id')->nullable()->constrained('record_resources')->nullOnDelete();
            $concept($table, 'entry_type_id');
            $table->jsonb('csl_data')->nullable();
            $table->text('title');
            // Authors as written, "Surname, Given" separated by semicolons (Heratio's).
            $table->text('authors')->nullable();
            $table->text('date')->nullable();
            $table->text('publisher')->nullable();
            $table->text('container_title')->nullable();
            $table->text('volume')->nullable();
            $table->text('issue')->nullable();
            $table->text('pages')->nullable();
            $table->text('doi')->nullable();
            $table->text('url')->nullable();
            $table->date('accessed_on')->nullable();
            $table->text('archive_name')->nullable();
            $table->text('archive_location')->nullable();
            $table->text('collection_title')->nullable();
            $table->text('box')->nullable();
            $table->text('folder')->nullable();
            $table->text('notes')->nullable();
            $table->integer('position')->default(0);
            $table->timestampsTz();
        });

        Schema::create('citation_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->cascadeOnDelete();
            $table->text('format');
            $table->timestampTz('created_at');
        });

        Schema::create('saved_searches', function (Blueprint $table) use ($concept) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('name');
            $table->text('description')->nullable();
            $table->text('search_query');
            $table->jsonb('search_filters')->nullable();
            // The results when last looked at (table and id of each), to see what is new since.
            $table->jsonb('result_snapshot')->nullable();
            $table->integer('total_results_at_save')->default(0);
            $table->integer('last_result_count')->default(0);
            $table->timestampTz('last_run_at')->nullable();
            $table->boolean('alert_enabled')->default(false);
            $concept($table, 'alert_frequency_id', true);
            $table->timestampTz('last_alert_at')->nullable();
            $table->integer('new_results_count')->default(0);
            $table->boolean('is_public')->default(false);
            $table->timestampsTz();
        });

        Schema::create('search_alert_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('saved_search_id')->constrained('saved_searches')->cascadeOnDelete();
            $table->integer('previous_count');
            $table->integer('new_count');
            $table->integer('new_items_count');
            $table->boolean('notification_sent')->default(false);
            $table->timestampTz('created_at');
        });

        foreach ([['reproduction_prices', 'reproduction_type_id', 'reproduction-type'], ['reproduction_requests', 'status_id', 'reproduction-status'],
            ['reproduction_requests', 'delivery_method_id', 'delivery-method'], ['reproduction_items', 'reproduction_type_id', 'reproduction-type'],
            ['reproduction_items', 'colour_mode_id', 'colour-mode'], ['reproduction_items', 'status_id', 'reproduction-item-status'],
            ['bibliographies', 'citation_style_id', 'citation-style'], ['bibliography_entries', 'entry_type_id', 'bibliography-entry-type'],
            ['saved_searches', 'alert_frequency_id', 'alert-frequency']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }
        foreach (Institution::all() as $institution) {
            self::seedPrices($institution->id);
        }
    }

    public static function seedPrices(string $institutionId): void
    {
        foreach (self::PRICES as $type => [$base, $unit, $per]) {
            $typeId = DB::table('concepts')->where('iri', self::VOCAB."reproduction-type/$type")->value('id');
            DB::table('reproduction_prices')->insertOrIgnore(['id' => (string) Str::uuid(), 'institution_id' => $institutionId, 'reproduction_type_id' => $typeId,
                'base_price' => $base, 'unit_price' => $unit, 'unit' => $per, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (['search_alert_log', 'saved_searches', 'citation_log', 'bibliography_entries', 'bibliographies', 'reproduction_status_changes', 'reproduction_files',
            'reproduction_items', 'reproduction_requests', 'reproduction_prices'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
