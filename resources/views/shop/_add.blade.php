{{-- OpenRiC Workspace - put a record into the cart, with a product when the shop is open (Heratio's add to cart; roadmap phase 19a). Needs $unit. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($shopSettings = app(\App\Research\Shop::class)->settings($unit->institution_id))
<form method="post" action="{{ route('shop.cart.add', $unit->id) }}" class="no-print" style="display: inline">
    @csrf
    @if($shopSettings->is_enabled)
        <select name="product_type_id" aria-label="{{ __('Product') }}" style="font-size: .85rem">@foreach(app(\App\Research\Shop::class)->products($unit->institution_id, $unit->repository_id) as $p)<option value="{{ $p->id }}">{{ $p->name }} ({{ $shopSettings->currency }} {{ number_format((float) $p->price, 2) }})</option>@endforeach</select>
    @endif
    <button type="submit" style="margin: .25rem 0; padding: .3rem .8rem; font-size: .85rem">{{ $shopSettings->is_enabled ? __('Add to cart') : __('Request a copy') }}</button>
</form>
