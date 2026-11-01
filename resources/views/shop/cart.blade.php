{{-- OpenRiC Workspace - the cart (roadmap phase 19a, ADR 0017), after Heratio's cart browse (ahg-cart). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends(auth()->check() ? 'layouts.app' : 'layouts.public')
@section('title', __('Cart'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Cart') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @error('cart')<p class="error" role="alert">{{ $message }}</p>@enderror
    @if($items->isEmpty())
        <p>{{ __('Your cart is empty.') }}</p>
    @else
        @php($lines = $totals ? $totals['lines']->keyBy(fn ($l) => $l['item']->id) : collect())
        <table><thead><tr><th scope="col">{{ __('Record') }}</th>@if($totals)<th scope="col">{{ __('Product') }}</th><th scope="col">{{ __('Quantity') }}</th><th scope="col">{{ __('Price') }}</th>@endif<th scope="col">{{ __('Remove') }}</th></tr></thead>
            <tbody>@foreach($items as $item)<tr>
                <td><a href="{{ route('discover.record', $item->record_resource_id) }}">{{ \App\Relations\EntityKinds::label($item->record, $lang) }}</a>@if($code = $item->record->referenceCode()) <span class="meta">{{ $code }}</span>@endif</td>
                @if($totals)
                    <td colspan="2"><form method="post" action="{{ route('shop.cart.change', $item) }}">@csrf
                        <select name="product_type_id" aria-label="{{ __('Product') }}"><option value="">-</option>@foreach($products as $p)<option value="{{ $p->id }}" @selected($item->product_type_id === $p->id)>{{ $p->name }}</option>@endforeach</select>
                        <input type="number" name="quantity" min="1" max="100" value="{{ $item->quantity }}" aria-label="{{ __('Quantity') }}" style="width: 4rem"> <button type="submit">{{ __('Save') }}</button></form></td>
                    <td>{{ $totals['currency'] }} {{ number_format($lines[$item->id]['line_total'] ?? 0, 2) }}</td>
                @endif
                <td><form method="post" action="{{ route('shop.cart.remove', $item) }}">@csrf<button type="submit">{{ __('Remove') }}</button></form></td>
            </tr>@endforeach</tbody></table>
        @if($totals)
            <p>{{ __('Total') }}: <strong>{{ $totals['currency'] }} {{ number_format($totals['total'], 2) }}</strong> <span class="meta">{{ __('including VAT of :currency :amount (:rate%)', ['currency' => $totals['currency'], 'amount' => number_format($totals['vat_amount'], 2), 'rate' => rtrim(rtrim(number_format($totals['vat_rate'], 2), '0'), '.')]) }}</span></p>
        @endif
        <p>@auth<a href="{{ route('shop.checkout') }}">{{ $totals ? __('Check out') : __('Ask for these') }}</a>@else{{ __('Sign in to check out; your cart will be kept.') }} <a href="{{ route('sign-in') }}">{{ __('Sign in') }}</a>@endauth</p>
        <form method="post" action="{{ route('shop.cart.clear') }}">@csrf<button type="submit">{{ __('Empty the cart') }}</button></form>
    @endif
    @auth<p><a href="{{ route('shop.orders.mine') }}">{{ __('My orders') }}</a></p>@endauth
@endsection
