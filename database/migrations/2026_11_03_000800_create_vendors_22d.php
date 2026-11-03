<?php

// OpenRiC Workspace - vendors and the work they do on the institution's records (roadmap phase 22d, ADR 0020), after
// Heratio's ahg-vendor: a vendor is an authority record with a vendor profile (its code, type, status, registration,
// bank details kept encrypted, insurance, ratings), the services it offers at its rates, and transactions that send
// records to it for a service and bring them back, item by item with their condition before and after, each change
// of status kept, and the quotes, invoices and condition reports filed with it.
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
    /** Vocabulary-checked columns: table, column, vocabulary. */
    private const CHECKED = [['vendors', 'vendor_type_id', 'vendor-type'], ['vendors', 'status_id', 'vendor-status'],
        ['vendor_services', 'service_type_id', 'vendor-service-type'], ['vendor_services', 'currency_id', 'currency'],
        ['vendor_service_settings', 'service_type_id', 'vendor-service-type'],
        ['vendor_transactions', 'service_type_id', 'vendor-service-type'], ['vendor_transactions', 'status_id', 'vendor-transaction-status'],
        ['vendor_transactions', 'payment_status_id', 'vendor-payment-status'], ['vendor_transactions', 'currency_id', 'currency'],
        ['vendor_transaction_items', 'condition_before_grade_id', 'condition-grade'], ['vendor_transaction_items', 'condition_after_grade_id', 'condition-grade'],
        ['vendor_transaction_history', 'status_from_id', 'vendor-transaction-status'], ['vendor_transaction_history', 'status_to_id', 'vendor-transaction-status'],
        ['vendor_transaction_attachments', 'type_id', 'vendor-attachment-type']];

    public function up(): void
    {
        foreach (['record-kind', 'vendor-type', 'vendor-status', 'vendor-service-type', 'vendor-transaction-status', 'vendor-payment-status', 'condition-grade', 'vendor-attachment-type'] as $name) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$name.ttl")));
        }
        Schema::create('vendors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            // The vendor is an authority record: its names, contact points and relations are the agent's.
            $table->foreignUuid('agent_id')->unique()->constrained('agents')->restrictOnDelete();
            $table->text('vendor_code');
            $table->foreignUuid('vendor_type_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->text('registration_number')->nullable();
            $table->text('vat_number')->nullable();
            // Encrypted by the model, as Heratio encrypts them.
            foreach (['bank_name', 'bank_branch', 'bank_account_number', 'bank_branch_code', 'bank_account_type'] as $column) {
                $table->text($column)->nullable();
            }
            $table->boolean('has_insurance')->default(false);
            $table->text('insurance_provider')->nullable();
            $table->text('insurance_policy_number')->nullable();
            $table->date('insurance_expiry_date')->nullable();
            $table->decimal('insurance_coverage_amount', 15, 2)->nullable();
            foreach (['quality_rating', 'reliability_rating', 'price_rating'] as $column) {
                $table->smallInteger($column)->nullable();
            }
            $table->boolean('is_preferred')->default(false);
            $table->boolean('is_bbbee_compliant')->default(false);
            $table->text('notes')->nullable();
            $table->foreignUuid('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['institution_id', 'vendor_code']);
        });
        DB::statement('ALTER TABLE vendors ADD CONSTRAINT vendors_ratings CHECK ((quality_rating IS NULL OR quality_rating BETWEEN 1 AND 5) AND (reliability_rating IS NULL OR reliability_rating BETWEEN 1 AND 5) AND (price_rating IS NULL OR price_rating BETWEEN 1 AND 5))');
        Schema::create('vendor_services', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignUuid('service_type_id')->constrained('concepts')->restrictOnDelete();
            $table->decimal('hourly_rate', 10, 2)->nullable();
            $table->decimal('fixed_rate', 10, 2)->nullable();
            $table->foreignUuid('currency_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->boolean('is_preferred')->default(false);
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->unique(['vendor_id', 'service_type_id']);
        });
        // What each service needs at this institution; a service without a row has Heratio's defaults (App\Vendors\Vendors::SERVICE_DEFAULTS).
        Schema::create('vendor_service_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('service_type_id')->constrained('concepts')->restrictOnDelete();
            $table->boolean('requires_insurance')->default(false);
            $table->boolean('requires_valuation')->default(false);
            $table->integer('typical_duration_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['institution_id', 'service_type_id']);
        });
        Schema::create('vendor_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('transaction_number');
            $table->foreignUuid('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->foreignUuid('service_type_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->date('request_date');
            foreach (['approval_date', 'dispatch_date', 'expected_return_date', 'completion_date', 'actual_return_date', 'invoice_date', 'payment_date'] as $column) {
                $table->date($column)->nullable();
            }
            foreach (['requested_by_id', 'approved_by_id', 'dispatched_by_id', 'received_by_id'] as $column) {
                $table->foreignUuid($column)->nullable()->constrained('users')->nullOnDelete();
            }
            $table->decimal('estimated_cost', 12, 2)->nullable();
            $table->decimal('actual_cost', 12, 2)->nullable();
            $table->foreignUuid('currency_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('payment_status_id')->constrained('concepts')->restrictOnDelete();
            $table->decimal('total_insured_value', 15, 2)->nullable();
            $table->boolean('insurance_arranged')->default(false);
            foreach (['quote_reference', 'invoice_reference', 'insurance_reference', 'shipping_method', 'tracking_number', 'courier_company',
                'dispatch_notes', 'vendor_notes', 'return_notes', 'internal_notes'] as $column) {
                $table->text($column)->nullable();
            }
            $table->timestampsTz();
            $table->unique(['institution_id', 'transaction_number']);
            $table->index(['institution_id', 'expected_return_date']);
        });
        Schema::create('vendor_transaction_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('transaction_id')->constrained('vendor_transactions')->cascadeOnDelete();
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->restrictOnDelete();
            $table->text('condition_before')->nullable();
            $table->foreignUuid('condition_before_grade_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->text('condition_after')->nullable();
            $table->foreignUuid('condition_after_grade_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->decimal('declared_value', 15, 2)->nullable();
            $table->text('service_description')->nullable();
            $table->boolean('service_completed')->default(false);
            $table->text('service_notes')->nullable();
            $table->decimal('item_cost', 10, 2)->nullable();
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('returned_at')->nullable();
            $table->timestampsTz();
            $table->unique(['transaction_id', 'record_resource_id']);
        });
        Schema::create('vendor_transaction_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('transaction_id')->constrained('vendor_transactions')->cascadeOnDelete();
            $table->foreignUuid('status_from_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('status_to_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('changed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestampTz('created_at');
        });
        Schema::create('vendor_transaction_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('transaction_id')->constrained('vendor_transactions')->cascadeOnDelete();
            $table->foreignUuid('type_id')->constrained('concepts')->restrictOnDelete();
            $table->text('original_name');
            $table->text('storage_path');
            $table->char('sha256', 64);
            $table->bigInteger('size_bytes');
            $table->text('mime_type');
            $table->text('scanned_with')->nullable();
            $table->timestampTz('scanned_at')->nullable();
            $table->text('description')->nullable();
            $table->foreignUuid('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at');
        });
        foreach (self::CHECKED as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', 'https://workspace.openric.org/vocab/{$vocabulary}/')");
        }
        foreach (Institution::all() as $institution) {
            DB::transaction(fn () => app(RoleService::class)->grantKind($institution, 'vendor'));
        }
    }

    public function down(): void
    {
        foreach (['vendor_transaction_attachments', 'vendor_transaction_history', 'vendor_transaction_items', 'vendor_transactions', 'vendor_service_settings', 'vendor_services', 'vendors'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
