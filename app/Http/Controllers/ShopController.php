<?php

// OpenRiC Workspace - the cart and the shop (roadmap phase 19a, ADR 0017), after Heratio's CartController (ahg-cart):
// the cart, checkout, one's orders and their downloads, paying through PayFast and PayFast's notification, and for
// who may manage the shop its orders and its settings, products and prices.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Audit\AuditLog;
use App\Description\InstantiationService;
use App\Files\FileResponse;
use App\Models\CartItem;
use App\Models\Concept;
use App\Models\Order;
use App\Models\ProductPrice;
use App\Models\ProductType;
use App\Models\RecordResource;
use App\Research\Shop;
use App\Support\HostInstitution;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class ShopController extends Controller
{
    public function __construct(private Shop $shop) {}

    public function cart(Request $request): View
    {
        $items = $this->shop->items($request->user());
        $institution = $items->first()?->institution_id ?? $request->user()?->institution_id ?? HostInstitution::for($request)?->id;
        $settings = $institution ? $this->shop->settings($institution) : null;

        return view('shop.cart', ['items' => $items, 'settings' => $settings, 'totals' => $settings && $settings->is_enabled ? $this->shop->totals($items, $settings) : null,
            'products' => $institution ? $this->shop->products($institution) : collect()]);
    }

    public function add(Request $request, RecordResource $record): RedirectResponse
    {
        $data = $request->validate(['product_type_id' => ['nullable', 'uuid'], 'quantity' => ['nullable', 'integer', 'min:1', 'max:100']]);
        try {
            $this->shop->add($request->user(), $record, $data['product_type_id'] ?? null, (int) ($data['quantity'] ?? 1));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['cart' => $e->getMessage()]);
        }

        return back()->with('status', __('Added to your cart.'));
    }

    public function change(Request $request, CartItem $item): RedirectResponse
    {
        $data = $request->validate(['product_type_id' => ['nullable', 'uuid'], 'quantity' => ['required', 'integer', 'min:1', 'max:100']]);

        return $this->attempt(fn () => $this->shop->change($request->user(), $item, $data['product_type_id'] ?? null, (int) $data['quantity']), __('Saved.'), 'shop.cart');
    }

    public function remove(Request $request, CartItem $item): RedirectResponse
    {
        return $this->attempt(fn () => $this->shop->remove($request->user(), $item), __('Removed from your cart.'), 'shop.cart');
    }

    public function clear(Request $request): RedirectResponse
    {
        $this->shop->clear($request->user());

        return redirect()->route('shop.cart')->with('status', __('Your cart is empty.'));
    }

    public function checkoutForm(Request $request): View|RedirectResponse
    {
        $items = $this->shop->items($request->user());
        if ($items->isEmpty()) {
            return redirect()->route('shop.cart')->withErrors(['cart' => __('Your cart is empty.')]);
        }
        $settings = $this->shop->settings($request->user()->institution_id);

        return view('shop.checkout', ['items' => $items, 'settings' => $settings, 'totals' => $settings->is_enabled ? $this->shop->totals($items, $settings) : null]);
    }

    public function checkout(Request $request): RedirectResponse
    {
        $data = $request->validate(['customer_name' => ['nullable', 'string', 'max:255'], 'customer_email' => ['nullable', 'string', 'max:255'], 'customer_phone' => ['nullable', 'string', 'max:50'],
            'billing_address' => ['nullable', 'string', 'max:2000'], 'shipping_address' => ['nullable', 'string', 'max:2000'], 'notes' => ['nullable', 'string', 'max:5000'],
            'planned_use' => ['nullable', 'string', 'max:5000'], 'motivation' => ['nullable', 'string', 'max:5000'], 'accept_terms' => ['nullable', 'boolean']]);
        $settings = $this->shop->settings($request->user()->institution_id);
        if ($settings->is_enabled && $settings->terms && ! $request->boolean('accept_terms')) {
            return back()->withInput()->withErrors(['cart' => __('Accept the terms and conditions.')]);
        }
        try {
            $done = $this->shop->checkout($request->user(), $data);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['cart' => $e->getMessage()]);
        }

        return $done instanceof Order ? redirect()->route('shop.orders.show', $done)->with('status', __('Order placed.'))
            : redirect()->route('access-requests.mine')->with('status', trans_choice('{1} Your request has been submitted.|[2,*] Your :count requests have been submitted.', count($done), ['count' => count($done)]));
    }

    public function myOrders(Request $request): View
    {
        return view('shop.my-orders', ['orders' => Order::where('user_id', $request->user()->id)->with('status')->orderByDesc('created_at')->get()]);
    }

    public function order(Request $request, Order $order): View
    {
        $this->mayView($request, $order);
        $order->load('status', 'items.productType', 'items.record', 'items.downloads', 'payments.status');

        return view('shop.order', ['order' => $order, 'shop' => $this->shop, 'manages' => $this->manages($request, $order),
            'statuses' => Concept::choices(Shop::ORDER_STATUSES)->get()]);
    }

    public function pay(Request $request, Order $order): RedirectResponse
    {
        abort_unless($order->user_id === $request->user()->id, 404);
        if ($order->status->notation !== 'pending') {
            return back()->withErrors(['cart' => __('This order is not waiting for payment.')]);
        }
        try {
            return redirect()->away($this->shop->payfastUrl($order));
        } catch (RuntimeException $e) {
            return back()->withErrors(['cart' => $e->getMessage()]);
        }
    }

    /** PayFast's notification: always answered 200, so nothing about its validity leaks (as Heratio does). */
    public function notify(Request $request): Response
    {
        $this->shop->notify($request->post(), (string) $request->ip());

        return response('OK', 200);
    }

    public function download(Request $request, string $token, string $file): Response
    {
        $found = $this->shop->download($request->user(), $token, $file) ?? abort(404);
        $path = app(InstantiationService::class)->path($found);
        abort_unless(is_file($path), 404);

        return FileResponse::make($found, $path, true);
    }

    public function orders(Request $request): View
    {
        $this->authorise($request);
        $status = $request->query('status');
        $all = Order::where('institution_id', $request->user()->institution_id)->with('status', 'user');

        return view('shop.orders', ['orders' => (clone $all)->when($status, fn ($q) => $q->whereHas('status', fn ($s) => $s->where('iri', Shop::ORDER_STATUSES.$status)))->orderByDesc('created_at')->get(),
            'status' => $status, 'statuses' => Concept::choices(Shop::ORDER_STATUSES)->get(),
            'stats' => ['count' => (clone $all)->count(), 'paid' => (clone $all)->whereNotNull('paid_at')->sum('total'), 'by' => (clone $all)->get()->countBy(fn ($o) => $o->status->notation)]]);
    }

    public function setStatus(Request $request, Order $order): RedirectResponse
    {
        $this->authorise($request);
        abort_unless($order->institution_id === $request->user()->institution_id, 404);
        $data = $request->validate(['status' => ['required', 'string', 'max:32']]);

        return $this->attempt(fn () => $this->shop->setStatus($request->user(), $order, $data['status']), __('Saved.'));
    }

    public function settings(Request $request): View
    {
        $this->authorise($request);
        $institution = $request->user()->institution_id;

        return view('shop.settings', ['settings' => $this->shop->settings($institution),
            'types' => ProductType::where('institution_id', $institution)->with(['prices' => fn ($q) => $q->whereNull('repository_id')])->orderBy('position')->get(),
            'uses' => Concept::choices(Vocab::BASE.'file-use/')->get()]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $this->authorise($request);
        $data = $request->validate(['is_enabled' => ['nullable', 'boolean'], 'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'], 'vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'vat_number' => ['nullable', 'string', 'max:50'], 'terms' => ['nullable', 'string', 'max:20000'], 'payfast_merchant_id' => ['nullable', 'string', 'max:50'],
            'payfast_merchant_key' => ['nullable', 'string', 'max:100'], 'payfast_passphrase' => ['nullable', 'string', 'max:100'], 'payfast_sandbox' => ['nullable', 'boolean'],
            'download_days' => ['required', 'integer', 'min:1', 'max:365'], 'download_limit' => ['required', 'integer', 'min:1', 'max:100']]);
        $settings = $this->shop->settings($request->user()->institution_id);
        // A key or passphrase left empty keeps the one already set, as Heratio's settings do.
        $fields = ['is_enabled' => $request->boolean('is_enabled'), 'currency' => $data['currency'], 'vat_rate' => $data['vat_rate'], 'vat_number' => $data['vat_number'] ?? null,
            'terms' => $data['terms'] ?? null, 'payfast_merchant_id' => $data['payfast_merchant_id'] ?? null, 'payfast_sandbox' => $request->boolean('payfast_sandbox'),
            'download_days' => $data['download_days'], 'download_limit' => $data['download_limit']]
            + array_filter(['payfast_merchant_key' => $data['payfast_merchant_key'] ?? null, 'payfast_passphrase' => $data['payfast_passphrase'] ?? null]);
        DB::transaction(fn () => AuditLog::actingAs($request->user(), fn () => $settings->update($fields)));

        return back()->with('status', __('The shop settings are saved.'));
    }

    /** A product type and its price (Heratio's pricing tab), or a new one. */
    public function saveProduct(Request $request, ?ProductType $type = null): RedirectResponse
    {
        $this->authorise($request);
        abort_if($type && $type->institution_id !== $request->user()->institution_id, 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000'], 'is_digital' => ['nullable', 'boolean'],
            'requires_shipping' => ['nullable', 'boolean'], 'delivers_use' => ['nullable', 'in:master,reference,thumbnail'], 'is_active' => ['nullable', 'boolean'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999']]);
        $institution = $request->user()->institution_id;
        DB::transaction(fn () => AuditLog::actingAs($request->user(), function () use ($type, $data, $request, $institution) {
            $fields = ['name' => $data['name'], 'description' => $data['description'] ?? null, 'is_digital' => $request->boolean('is_digital'),
                'requires_shipping' => $request->boolean('requires_shipping'), 'delivers_use' => $request->boolean('is_digital') ? ($data['delivers_use'] ?? null) : null, 'is_active' => $request->boolean('is_active')];
            $type = $type ? tap($type)->update($fields) : ProductType::create($fields + ['institution_id' => $institution, 'position' => (int) ProductType::where('institution_id', $institution)->max('position') + 1]);
            ProductPrice::updateOrCreate(['product_type_id' => $type->id, 'repository_id' => null], ['institution_id' => $institution, 'price' => $data['price'], 'is_active' => true]);
        }));

        return back()->with('status', __('Saved.'));
    }

    private function mayView(Request $request, Order $order): void
    {
        abort_unless($order->user_id === $request->user()->id || $this->manages($request, $order), 404);
    }

    private function manages(Request $request, Order $order): bool
    {
        return $order->institution_id === $request->user()->institution_id && $request->user()->hasPermission(Vocab::permission('manage-shop'));
    }

    private function authorise(Request $request): void
    {
        abort_unless($request->user()->hasPermission(Vocab::permission('manage-shop')), 403);
    }

    private function attempt(callable $do, string $done, ?string $route = null): RedirectResponse
    {
        try {
            $do();
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['cart' => $e->getMessage()]);
        }

        return ($route ? redirect()->route($route) : back())->with('status', $done);
    }
}
