<?php

// OpenRiC Workspace - the cart and the shop (roadmap phase 19a, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's CartService and EcommerceService (ahg-cart) and the PayFast half of its MarketplacePaymentService
// (ahg-marketplace): records go into a cart (a visitor's is kept by session and joins the account on sign-in);
// with the shop off, checking out asks for each record as Heratio's request to publish does (here an access
// request to publish); with it on, an order is placed at the prices of the product types, VAT included, numbered
// ORD-YYYYMMDD-NNNN, and paid through PayFast. A PayFast notification is trusted only when its signature, its
// source, PayFast's own validation and its amount all hold, as Heratio's verifyItn requires, and is kept either way.
// Changed: the cart's checkout sends the buyer to PayFast (Heratio's cart never did; its marketplace does); a paid
// digital item gives time-limited downloads of the file its product delivers, kept as hashes; checkout needs an
// account. Stripe is not carried: Heratio keeps its keys and never charges with them.

namespace App\Research;

use App\AccessRequests\AccessRequestService;
use App\Api\PublicCatalogue;
use App\Audit\AuditLog;
use App\Models\AccessRequest;
use App\Models\CartItem;
use App\Models\Concept;
use App\Models\DigitalFile;
use App\Models\DownloadToken;
use App\Models\Institution;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ProductPrice;
use App\Models\ProductType;
use App\Models\RecordResource;
use App\Models\ShopSettings;
use App\Models\User;
use App\Privacy\Screening;
use App\Relations\EntityKinds;
use App\Rights\CommunityProtocols;
use App\Rights\Embargoes;
use App\Support\Vocab;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class Shop
{
    public const ORDER_STATUSES = Vocab::BASE.'order-status/';

    public const PAYMENT_STATUSES = Vocab::BASE.'payment-status/';

    private const HOST_LIVE = 'https://www.payfast.co.za';

    private const HOST_SANDBOX = 'https://sandbox.payfast.co.za';

    private const PAYFAST_HOSTS = ['www.payfast.co.za', 'sandbox.payfast.co.za', 'w1w.payfast.co.za', 'w2w.payfast.co.za'];

    /** The institution's shop, made with Heratio's nine product types the first time it is asked for. */
    public function settings(string $institutionId): ShopSettings
    {
        if (! ProductType::where('institution_id', $institutionId)->exists()) {
            $migration = require database_path('migrations/2026_11_01_000100_create_research_services_19a.php');
            $migration::seedShop($institutionId);
        }

        return ShopSettings::firstOrCreate(['institution_id' => $institutionId]);
    }

    /** Active product types with the price that applies: the repository's own, else the institution's. */
    public function products(string $institutionId, ?string $repositoryId = null): Collection
    {
        $this->settings($institutionId);
        $prices = ProductPrice::where('institution_id', $institutionId)->where('is_active', true)->where(fn ($q) => $q->whereNull('repository_id')->orWhere('repository_id', $repositoryId))->get();

        return ProductType::where(['institution_id' => $institutionId, 'is_active' => true])->orderBy('position')->get()
            ->map(fn (ProductType $t) => tap($t, fn ($t) => $t->setAttribute('price', ($prices->first(fn ($p) => $p->product_type_id === $t->id && $p->repository_id === $repositoryId && $repositoryId !== null)
                ?? $prices->first(fn ($p) => $p->product_type_id === $t->id && $p->repository_id === null))?->price)))
            ->filter(fn ($t) => $t->price !== null)->values();
    }

    /** The key a visitor's cart is kept under, in the session (it survives the new session id signing in gives). */
    public static function visitorKey(): string
    {
        if (! session()->has('cart_key')) {
            session()->put('cart_key', Str::random(40));
        }

        return session('cart_key');
    }

    public function items(?User $user): Collection
    {
        return CartItem::query()->when($user, fn ($q) => $q->where('user_id', $user->id), fn ($q) => $q->where('session_id', self::visitorKey()))
            ->with('record.repository', 'productType')->orderBy('created_at')->get()->filter(fn ($i) => $i->record && $i->record->deleted_at === null)->values();
    }

    public function count(?User $user): int
    {
        return $user ? CartItem::where('user_id', $user->id)->count() : (session()->has('cart_key') ? CartItem::where('session_id', session('cart_key'))->count() : 0);
    }

    public function add(?User $user, RecordResource $unit, ?string $productTypeId = null, int $quantity = 1): CartItem
    {
        if ($user ? ! EntityKinds::readable($user, $unit) : ! (new PublicCatalogue(Institution::find($unit->institution_id)))->view->readable($unit)) {
            throw new InvalidArgumentException(__('That record cannot be added.'));
        }
        $type = $productTypeId ? ProductType::where(['id' => $productTypeId, 'institution_id' => $unit->institution_id, 'is_active' => true])->first() ?? throw new InvalidArgumentException(__('Choose a product from the list.')) : null;
        $owner = $user ? ['user_id' => $user->id] : ['session_id' => self::visitorKey()];
        $item = CartItem::where($owner + ['record_resource_id' => $unit->id, 'product_type_id' => $type?->id])->first();
        if ($item) {
            $item->update(['quantity' => min(100, $item->quantity + max(1, $quantity))]);

            return $item;
        }

        return CartItem::create($owner + ['institution_id' => $unit->institution_id, 'record_resource_id' => $unit->id, 'product_type_id' => $type?->id, 'quantity' => max(1, min(100, $quantity))]);
    }

    public function change(?User $user, CartItem $item, ?string $productTypeId, int $quantity): void
    {
        $this->own($user, $item);
        $type = $productTypeId ? ProductType::where(['id' => $productTypeId, 'institution_id' => $item->institution_id, 'is_active' => true])->first() ?? throw new InvalidArgumentException(__('Choose a product from the list.')) : null;
        $item->update(['product_type_id' => $type?->id, 'quantity' => max(1, min(100, $quantity))]);
    }

    public function remove(?User $user, CartItem $item): void
    {
        $this->own($user, $item);
        $item->delete();
    }

    public function clear(?User $user): int
    {
        return CartItem::query()->when($user, fn ($q) => $q->where('user_id', $user->id), fn ($q) => $q->where('session_id', self::visitorKey()))->delete();
    }

    /** Heratio's mergeGuestCart: on signing in, a visitor's cart joins the account's. */
    public function merge(User $user, ?string $visitorKey): void
    {
        if (! $visitorKey) {
            return;
        }
        foreach (CartItem::where('session_id', $visitorKey)->get() as $item) {
            $mine = CartItem::where(['user_id' => $user->id, 'record_resource_id' => $item->record_resource_id, 'product_type_id' => $item->product_type_id])->first();
            if ($mine) {
                $mine->update(['quantity' => min(100, $mine->quantity + $item->quantity)]);
                $item->delete();
            } else {
                $item->update(['user_id' => $user->id, 'session_id' => null]);
            }
        }
    }

    /**
     * Heratio's calculateCartTotals: each line at its product's price, VAT included in the prices.
     *
     * @return array{lines: Collection, subtotal: float, vat_amount: float, vat_rate: float, total: float, currency: string, unpriced: int}
     */
    public function totals(Collection $items, ShopSettings $settings): array
    {
        $subtotal = 0.0;
        $unpriced = 0;
        $lines = $items->map(function (CartItem $item) use (&$subtotal, &$unpriced, $settings) {
            $product = $item->product_type_id ? $this->products($settings->institution_id, $item->record->repository_id)->firstWhere('id', $item->product_type_id) : null;
            $unit = $product ? (float) $product->price : 0.0;
            $unpriced += $product ? 0 : 1;
            $subtotal += $unit * $item->quantity;

            return ['item' => $item, 'product' => $product, 'unit_price' => $unit, 'line_total' => round($unit * $item->quantity, 2)];
        });
        $rate = (float) $settings->vat_rate;

        return ['lines' => $lines, 'subtotal' => round($subtotal, 2), 'vat_amount' => round($subtotal * ($rate / (100 + $rate)), 2), 'vat_rate' => $rate,
            'total' => round($subtotal, 2), 'currency' => $settings->currency, 'unpriced' => $unpriced];
    }

    /**
     * Check out. With the shop on, an order (paid at once when it costs nothing); with it off, an access request to
     * publish for each record, as Heratio's request to publish.
     *
     * @return Order|list<AccessRequest>
     */
    public function checkout(User $user, array $data): Order|array
    {
        $items = $this->items($user);
        if ($items->isEmpty()) {
            throw new InvalidArgumentException(__('Your cart is empty.'));
        }
        $settings = $this->settings($user->institution_id);
        if (! $settings->is_enabled) {
            if (trim((string) ($data['planned_use'] ?? '')) === '') {
                throw new InvalidArgumentException(__('Say how you plan to use them.'));
            }

            return DB::transaction(function () use ($user, $items, $data) {
                $asked = [];
                foreach ($items as $item) {
                    $asked[] = app(AccessRequestService::class)->createGeneralRequest($user, ['scope' => 'item', 'request_type' => 'publish',
                        'subject' => __('Request to publish: :record', ['record' => EntityKinds::label($item->record)]), 'description' => trim($data['planned_use']),
                        'justification' => trim((string) ($data['motivation'] ?? '')) ?: null, 'urgency' => 'normal'], $item->record);
                    $item->delete();
                }

                return $asked;
            });
        }
        $totals = $this->totals($items, $settings);
        if ($totals['unpriced'] > 0) {
            throw new InvalidArgumentException(__('Choose a product for each record.'));
        }
        if (trim((string) ($data['customer_name'] ?? '')) === '' || ! filter_var($data['customer_email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException(__('Give your name and email address.'));
        }
        if ($totals['lines']->contains(fn ($l) => $l['product']->requires_shipping) && trim((string) ($data['shipping_address'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give the address to send the prints to.'));
        }

        $order = DB::transaction(fn () => AuditLog::actingAs($user, function () use ($user, $totals, $data, $items) {
            $day = now(config('workspace.timezone'))->format('Ymd');
            $n = Order::where('institution_id', $user->institution_id)->where('reference', 'like', "ORD-$day-%")->lockForUpdate()->get(['id'])->count() + 1;
            $order = Order::create(['institution_id' => $user->institution_id, 'reference' => sprintf('ORD-%s-%04d', $day, $n), 'user_id' => $user->id,
                'status_id' => $this->concept(self::ORDER_STATUSES, 'pending'), 'subtotal' => $totals['subtotal'], 'vat_amount' => $totals['vat_amount'],
                'total' => $totals['total'], 'currency' => $totals['currency'], 'customer_name' => trim($data['customer_name']), 'customer_email' => trim($data['customer_email']),
                'customer_phone' => trim((string) ($data['customer_phone'] ?? '')) ?: null, 'billing_address' => trim((string) ($data['billing_address'] ?? '')) ?: null,
                'shipping_address' => trim((string) ($data['shipping_address'] ?? '')) ?: null, 'notes' => trim((string) ($data['notes'] ?? '')) ?: null]);
            foreach ($totals['lines'] as $line) {
                OrderItem::create(['order_id' => $order->id, 'record_resource_id' => $line['item']->record_resource_id, 'record_title' => EntityKinds::label($line['item']->record),
                    'product_type_id' => $line['product']->id, 'product_name' => $line['product']->name, 'quantity' => $line['item']->quantity,
                    'unit_price' => $line['unit_price'], 'line_total' => $line['line_total']]);
            }
            $items->each->delete();

            return $order;
        }));
        if ((float) $order->total === 0.0) {
            $this->paid($order, null, 'free');
        }

        return $order->fresh();
    }

    /** Where the buyer pays: PayFast's page, with the order's fields signed (Heratio's buildProcessUrl). */
    public function payfastUrl(Order $order): string
    {
        $settings = $this->settings($order->institution_id);
        if (! $settings->payfast_merchant_id || ! $settings->payfast_merchant_key) {
            throw new RuntimeException(__('PayFast is not set up yet. Ask the institution to set it up under the shop settings.'));
        }
        [$first, $last] = array_pad(preg_split('/\s+/', trim($order->customer_name) ?: 'Buyer', 2), 2, '');
        $fields = array_filter([
            'merchant_id' => (string) $settings->payfast_merchant_id, 'merchant_key' => (string) $settings->payfast_merchant_key,
            'return_url' => route('shop.orders.show', $order), 'cancel_url' => route('shop.orders.show', $order), 'notify_url' => route('shop.payfast.notify'),
            'name_first' => mb_substr($first, 0, 100), 'name_last' => mb_substr($last, 0, 100), 'email_address' => $order->customer_email,
            'm_payment_id' => $order->reference, 'amount' => number_format((float) $order->total, 2, '.', ''),
            'item_name' => mb_substr(__('Order :reference', ['reference' => $order->reference]), 0, 100),
        ], fn ($v) => $v !== '' && $v !== null);
        $fields['signature'] = self::sign($fields, (string) $settings->payfast_passphrase);

        return ($settings->payfast_sandbox ? self::HOST_SANDBOX : self::HOST_LIVE).'/eng/process?'.http_build_query($fields);
    }

    /** PayFast's signature: the fields in order, URL-encoded with spaces as +, the passphrase last, MD5. */
    public static function sign(array $fields, string $passphrase = ''): string
    {
        unset($fields['signature']);
        $parts = [];
        foreach ($fields as $key => $value) {
            if ($value !== null && $value !== '') {
                $parts[] = $key.'='.str_replace('%20', '+', rawurlencode((string) $value));
            }
        }
        if ($passphrase !== '') {
            $parts[] = 'passphrase='.str_replace('%20', '+', rawurlencode($passphrase));
        }

        return md5(implode('&', $parts));
    }

    /**
     * A PayFast notification (ITN). Trusted only when all of Heratio's checks hold: the signature, PayFast's address
     * (not in the sandbox), PayFast's server-to-server validation, the amount, and the merchant; never for a live shop
     * with no passphrase. Kept either way, with why it was refused.
     */
    public function notify(array $payload, string $ip): Payment
    {
        $order = isset($payload['m_payment_id']) ? Order::where('reference', (string) $payload['m_payment_id'])->first() : null;
        $settings = $order ? $this->settings($order->institution_id) : null;
        $refused = match (true) {
            $order === null => 'unknown order',
            ! $settings->payfast_sandbox && ! $settings->payfast_passphrase => 'no passphrase on a live shop',
            empty($payload['signature']) || strcasecmp((string) $payload['signature'], self::sign($payload, (string) $settings->payfast_passphrase)) !== 0 => 'signature',
            ! $settings->payfast_sandbox && ! $this->fromPayFast($ip) => 'source address',
            $settings->payfast_merchant_id && (string) ($payload['merchant_id'] ?? '') !== (string) $settings->payfast_merchant_id => 'merchant',
            number_format((float) $order->total, 2, '.', '') !== number_format((float) ($payload['amount_gross'] ?? 0), 2, '.', '') => 'amount',
            ! $this->validated($payload, (bool) $settings->payfast_sandbox) => 'PayFast did not validate it',
            default => null,
        };
        $complete = $refused === null && ($payload['payment_status'] ?? '') === 'COMPLETE';
        $payment = Payment::create(['order_id' => $order?->id, 'gateway' => 'payfast', 'transaction_id' => $payload['pf_payment_id'] ?? null,
            'amount' => $payload['amount_gross'] ?? null, 'currency' => $order?->currency, 'gateway_response' => array_diff_key($payload, ['signature' => 1]),
            'status_id' => $this->concept(self::PAYMENT_STATUSES, $refused ? 'failed' : ($complete ? 'completed' : 'pending')), 'refused_because' => $refused,
            'source_ip' => $ip, 'paid_at' => $complete ? now() : null]);
        if ($refused) {
            Log::warning('[PayFast ITN] refused', ['because' => $refused, 'reference' => $payload['m_payment_id'] ?? null]);
        } elseif ($complete && $order->status->notation === 'pending') {
            $this->paid($order, $payload['pf_payment_id'] ?? null, 'payfast');
        }

        return $payment;
    }

    /** Settle an order: paid, and a download for each digital item that delivers a file. */
    public function paid(Order $order, ?string $reference, string $gateway): void
    {
        $settings = $this->settings($order->institution_id);
        DB::transaction(function () use ($order, $reference, $gateway, $settings) {
            $order->update(['status_id' => $this->concept(self::ORDER_STATUSES, 'paid'), 'paid_at' => now(), 'payment_reference' => $reference]);
            if ($gateway === 'free') {
                Payment::create(['order_id' => $order->id, 'gateway' => 'free', 'amount' => 0, 'currency' => $order->currency,
                    'status_id' => $this->concept(self::PAYMENT_STATUSES, 'completed'), 'paid_at' => now()]);
            }
            foreach ($order->items()->with('productType')->get() as $item) {
                if ($item->productType?->is_digital && $item->productType->delivers_use && ! $item->downloads()->exists()) {
                    DownloadToken::create(['order_item_id' => $item->id, 'token_hash' => hash('sha256', $this->downloadToken($item)), 'expires_at' => now()->addDays($settings->download_days),
                        'max_downloads' => $settings->download_limit]);
                }
            }
        });
    }

    /** A download's token is derived from the item and the app's key, so the buyer's page can show it again and only its hash is stored. */
    public function downloadToken(OrderItem $item): string
    {
        return hash_hmac('sha256', 'download:'.$item->id, (string) config('app.key'));
    }

    public function setStatus(User $actor, Order $order, string $status): void
    {
        $allowed = ['pending' => ['paid', 'cancelled'], 'paid' => ['processing', 'completed', 'refunded'], 'processing' => ['completed', 'refunded'], 'completed' => ['refunded']];
        if (! in_array($status, $allowed[$order->status->notation] ?? [], true)) {
            throw new InvalidArgumentException(__('An order that is :from cannot become :to.', ['from' => $order->status->label(['en']), 'to' => $status]));
        }
        if ($status === 'paid') {
            AuditLog::actingAs($actor, fn () => $this->paid($order, __('Recorded by :who', ['who' => $actor->name]), 'manual'));

            return;
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $order->update(['status_id' => $this->concept(self::ORDER_STATUSES, $status),
            'completed_at' => $status === 'completed' ? now() : $order->completed_at, 'cancelled_at' => $status === 'cancelled' ? now() : $order->cancelled_at])));
    }

    /**
     * The file a paid download delivers, when it may still be delivered: the record's file of the product's use, while
     * the token lasts; never past an embargo, screening or a community protocol that withholds it from the buyer.
     */
    public function download(User $user, string $token, string $fileId): ?DigitalFile
    {
        $download = DownloadToken::where('token_hash', hash('sha256', $token))->with('item.order', 'item.productType', 'item.record')->first();
        if ($download === null || $download->item->order->user_id !== $user->id || $download->expires_at->isPast() || $download->download_count >= $download->max_downloads) {
            return null;
        }
        $unit = $download->item->record;
        $file = DigitalFile::with('instantiation', 'use')->find($fileId);
        $use = $download->item->productType->delivers_use;
        if (! $unit || ! $file || $file->instantiation->record_resource_id !== $unit->id || $file->use->notation !== $use || $unit->deleted_at !== null
            || ! app(Embargoes::class)->allows($user, $unit, $use) || app(Screening::class)->withholds($user, $unit) || ! app(CommunityProtocols::class)->allows($user, $unit, 'description')) {
            return null;
        }
        $download->update(['download_count' => $download->download_count + 1, 'last_downloaded_at' => now()]);

        return $file;
    }

    /** The files a paid item delivers. @return Collection<int, DigitalFile> */
    public function deliverables(OrderItem $item): Collection
    {
        $use = $item->productType?->delivers_use;

        return $use && $item->record ? $item->record->instantiations()->with('files.use')->get()->flatMap->files->filter(fn ($f) => $f->use->notation === $use)->values() : collect();
    }

    private function fromPayFast(string $ip): bool
    {
        $allowed = [];
        foreach (self::PAYFAST_HOSTS as $host) {
            $allowed = [...$allowed, ...((array) @gethostbynamel($host))];
        }

        return in_array($ip, $allowed, true);
    }

    private function validated(array $payload, bool $sandbox): bool
    {
        try {
            $response = Http::asForm()->timeout(10)->post(($sandbox ? self::HOST_SANDBOX : self::HOST_LIVE).'/eng/query/validate', $payload);

            return ! $response->failed() && stripos(trim($response->body()), 'VALID') === 0;
        } catch (\Throwable $e) {
            Log::warning('[PayFast ITN] validate request failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function own(?User $user, CartItem $item): void
    {
        if ($user ? $item->user_id !== $user->id : $item->session_id !== self::visitorKey()) {
            throw new InvalidArgumentException(__('That is not in your cart.'));
        }
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', $vocabulary.$notation)->value('id');
    }
}
