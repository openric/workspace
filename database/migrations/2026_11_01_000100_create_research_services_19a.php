<?php

// OpenRiC Workspace - research services, part 19a (roadmap phase 19, ADR 0017): favourites and their folders
// (Heratio's ahg-favorites: favorites, favorites_folder, favorites_share), the cart and the shop (ahg-cart: cart,
// ahg_ecommerce_settings, ahg_product_type, ahg_product_pricing, ahg_order, ahg_order_item, ahg_payment,
// ahg_download_token), feedback (ahg-feedback: feedback, feedback_i18n) and share links (ahg-share-link:
// information_object_share_token, information_object_share_access). Statuses, types and visibilities are vocabulary
// rows; product types are reference data of the institution, seeded with Heratio's nine.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Access\RoleService;
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

    public const VOCABULARIES = ['favourite-visibility', 'order-status', 'payment-status', 'feedback-type', 'feedback-status', 'share-link-event', 'permission'];

    public function up(): void
    {
        foreach (self::VOCABULARIES as $name) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$name.ttl")));
        }

        // Favourites (ahg-favorites).
        Schema::create('favourite_folders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->text('name');
            $table->text('description')->nullable();
            $table->foreignUuid('visibility_id')->constrained('concepts')->restrictOnDelete();
            // The hash of the link that opens it to anyone holding it; the link itself is shown once.
            $table->string('share_token_hash', 64)->nullable()->unique();
            $table->timestampTz('share_expires_at')->nullable();
            $table->integer('share_access_count')->default(0);
            $table->timestampTz('share_accessed_at')->nullable();
            $table->integer('position')->default(0);
            $table->timestampsTz();
        });
        Schema::table('favourite_folders', fn (Blueprint $table) => $table->foreign('parent_id')->references('id')->on('favourite_folders')->nullOnDelete());

        Schema::create('favourites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('folder_id')->nullable()->constrained('favourite_folders')->nullOnDelete();
            // An entity of the Workspace, by table and id; or a link of one's own.
            $table->text('subject_table')->nullable();
            $table->uuid('subject_id')->nullable();
            $table->text('url')->nullable();
            $table->text('title')->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('last_viewed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'subject_table', 'subject_id']);
            $table->index(['user_id', 'folder_id']);
        });
        DB::statement('ALTER TABLE favourites ADD CONSTRAINT favourites_what CHECK ((subject_id IS NOT NULL AND subject_table IS NOT NULL AND url IS NULL) OR (subject_id IS NULL AND subject_table IS NULL AND url IS NOT NULL AND title IS NOT NULL))');

        // The cart and the shop (ahg-cart).
        Schema::create('product_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->text('name');
            $table->text('description')->nullable();
            $table->boolean('is_digital')->default(true);
            $table->boolean('requires_shipping')->default(false);
            // What a digital product delivers: the file of this use (file-use vocabulary notation), watermarked or not.
            $table->text('delivers_use')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('position')->default(0);
            $table->timestampsTz();
        });
        Schema::create('product_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            // A repository's own price, else the institution's.
            $table->foreignUuid('repository_id')->nullable()->constrained('repositories')->cascadeOnDelete();
            $table->foreignUuid('product_type_id')->constrained('product_types')->cascadeOnDelete();
            $table->decimal('price', 10, 2)->default(0);
            $table->boolean('price_includes_vat')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['product_type_id', 'repository_id']);
        });
        DB::statement('ALTER TABLE product_prices ADD CONSTRAINT product_prices_price CHECK (price >= 0)');
        Schema::create('shop_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->unique()->constrained('institutions')->cascadeOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->string('currency', 3)->default('ZAR');
            $table->decimal('vat_rate', 5, 2)->default(15);
            $table->text('vat_number')->nullable();
            $table->text('terms')->nullable();
            // PayFast (Heratio's gateway); the key and passphrase are kept encrypted.
            $table->text('payfast_merchant_id')->nullable();
            $table->text('payfast_merchant_key')->nullable();
            $table->text('payfast_passphrase')->nullable();
            $table->boolean('payfast_sandbox')->default(true);
            // Days a paid download stays open, and how many times.
            $table->integer('download_days')->default(7);
            $table->integer('download_limit')->default(5);
            $table->timestampsTz();
        });
        Schema::create('cart_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            // An account's cart, or a visitor's by session until they sign in.
            $table->foreignUuid('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->text('session_id')->nullable();
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->cascadeOnDelete();
            $table->foreignUuid('product_type_id')->nullable()->constrained('product_types')->nullOnDelete();
            $table->integer('quantity')->default(1);
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->index(['user_id']);
            $table->index(['session_id']);
        });
        DB::statement('ALTER TABLE cart_items ADD CONSTRAINT cart_items_owner CHECK ((user_id IS NOT NULL) OR (session_id IS NOT NULL)) ');
        DB::statement('ALTER TABLE cart_items ADD CONSTRAINT cart_items_quantity CHECK (quantity BETWEEN 1 AND 100)');
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('reference')->unique();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('vat_amount', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->string('currency', 3)->default('ZAR');
            $table->text('customer_name');
            $table->text('customer_email');
            $table->text('customer_phone')->nullable();
            $table->text('billing_address')->nullable();
            $table->text('shipping_address')->nullable();
            $table->text('notes')->nullable();
            $table->text('payment_reference')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_amounts CHECK (subtotal >= 0 AND vat_amount >= 0 AND total >= 0)');
        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignUuid('record_resource_id')->nullable()->constrained('record_resources')->nullOnDelete();
            $table->text('record_title');
            $table->foreignUuid('product_type_id')->nullable()->constrained('product_types')->nullOnDelete();
            $table->text('product_name');
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->decimal('line_total', 10, 2)->default(0);
            $table->timestampsTz();
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->text('gateway');
            $table->text('transaction_id')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            // Every notification is kept, with why it was not trusted when it was not.
            $table->jsonb('gateway_response')->nullable();
            $table->text('refused_because')->nullable();
            $table->ipAddress('source_ip')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();
        });
        Schema::create('download_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->integer('max_downloads')->default(5);
            $table->integer('download_count')->default(0);
            $table->timestampTz('last_downloaded_at')->nullable();
            $table->timestampsTz();
        });

        // Feedback (ahg-feedback).
        Schema::create('feedback', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('record_resource_id')->nullable()->constrained('record_resources')->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('type_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->text('subject');
            $table->text('remarks');
            $table->text('name');
            $table->text('surname');
            $table->text('email');
            $table->text('phone')->nullable();
            $table->text('relationship')->nullable();
            $table->string('language', 35)->default('en');
            $table->text('staff_notes')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->foreignUuid('completed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        // Share links (ahg-share-link).
        Schema::create('share_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->foreignUuid('issued_by_id')->constrained('users')->restrictOnDelete();
            $table->text('recipient_email')->nullable();
            $table->text('recipient_note')->nullable();
            $table->timestampTz('expires_at');
            $table->integer('max_access')->nullable();
            $table->integer('access_count')->default(0);
            $table->boolean('allows_download')->default(false);
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignUuid('revoked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('revoke_reason')->nullable();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE share_links ADD CONSTRAINT share_links_quota CHECK (max_access IS NULL OR max_access > 0)');
        Schema::create('share_link_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('share_link_id')->constrained('share_links')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('concepts')->restrictOnDelete();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('happened_at');
        });

        foreach ([['favourite_folders', 'visibility_id', 'favourite-visibility'], ['orders', 'status_id', 'order-status'], ['payments', 'status_id', 'payment-status'],
            ['feedback', 'type_id', 'feedback-type'], ['feedback', 'status_id', 'feedback-status'], ['share_link_events', 'event_id', 'share-link-event']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }

        foreach (Institution::all() as $institution) {
            self::seedShop($institution->id);
            DB::transaction(fn () => app(RoleService::class)->grantKind($institution, 'institution', ['manage-shop']));
        }
    }

    /** Heratio's nine product types and their prices, for an institution. */
    public static function seedShop(string $institutionId): void
    {
        $now = now();
        $types = json_decode(file_get_contents(resource_path('research/product-types.json')), true);
        foreach ($types as $i => $type) {
            $id = (string) Str::uuid();
            DB::table('product_types')->insert(['id' => $id, 'institution_id' => $institutionId, 'name' => $type['name'], 'description' => $type['description'],
                'is_digital' => $type['is_digital'], 'requires_shipping' => $type['requires_shipping'], 'delivers_use' => $type['delivers_use'], 'position' => $i,
                'created_at' => $now, 'updated_at' => $now]);
            DB::table('product_prices')->insert(['id' => (string) Str::uuid(), 'institution_id' => $institutionId, 'product_type_id' => $id, 'price' => $type['price'],
                'created_at' => $now, 'updated_at' => $now]);
        }
        DB::table('shop_settings')->insertOrIgnore(['id' => (string) Str::uuid(), 'institution_id' => $institutionId, 'created_at' => $now, 'updated_at' => $now]);
    }

    public function down(): void
    {
        foreach (['share_link_events', 'share_links', 'feedback', 'download_tokens', 'payments', 'order_items', 'orders', 'cart_items', 'shop_settings', 'product_prices',
            'product_types', 'favourites', 'favourite_folders'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
