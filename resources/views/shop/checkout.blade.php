{{-- OpenRiC Workspace - checking out (roadmap phase 19a, ADR 0017), after Heratio's checkout (ahg-cart): an order when the shop is open, else a request to publish for each record. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Check out'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ $totals ? __('Check out') : __('Ask for these records') }}</h1>
    @error('cart')<p class="error" role="alert">{{ $message }}</p>@enderror
    <ul>@foreach($items as $item)<li>{{ \App\Relations\EntityKinds::label($item->record, $lang) }}@if($totals && $item->productType) · {{ $item->productType->name }} × {{ $item->quantity }}@endif</li>@endforeach</ul>
    <form method="post" action="{{ route('shop.checkout.store') }}">@csrf
        @if($totals)
            <p>{{ __('Total') }}: <strong>{{ $totals['currency'] }} {{ number_format($totals['total'], 2) }}</strong> ({{ __('including VAT of :currency :amount', ['currency' => $totals['currency'], 'amount' => number_format($totals['vat_amount'], 2)]) }})</p>
            <p><label for="co-name">{{ __('Name') }}</label> <input id="co-name" name="customer_name" required value="{{ old('customer_name', auth()->user()->name) }}" size="40">
                <label for="co-email">{{ __('Email') }}</label> <input id="co-email" name="customer_email" type="email" required value="{{ old('customer_email', auth()->user()->email) }}" size="30">
                <label for="co-phone">{{ __('Telephone') }}</label> <input id="co-phone" name="customer_phone" value="{{ old('customer_phone') }}"></p>
            <p><label for="co-billing">{{ __('Billing address') }}</label><br><textarea id="co-billing" name="billing_address" rows="3" cols="60">{{ old('billing_address') }}</textarea></p>
            @if($totals['lines']->contains(fn ($l) => $l['product']?->requires_shipping))
                <p><label for="co-shipping">{{ __('Address to send the prints to') }}</label><br><textarea id="co-shipping" name="shipping_address" required rows="3" cols="60">{{ old('shipping_address') }}</textarea></p>
            @endif
            <p><label for="co-notes">{{ __('Notes') }}</label><br><textarea id="co-notes" name="notes" rows="2" cols="60">{{ old('notes') }}</textarea></p>
            @if($settings->terms)
                <details><summary>{{ __('Terms and conditions') }}</summary><div style="white-space: pre-line">{{ $settings->terms }}</div></details>
                <p><label><input type="checkbox" name="accept_terms" value="1" required> {{ __('I accept the terms and conditions') }}</label></p>
            @endif
            <button type="submit">{{ __('Place the order') }}</button>
        @else
            <p class="meta">{{ __('Each record is asked for as a request to publish; staff answer each one.') }}</p>
            <p><label for="co-use">{{ __('How you plan to use them') }}</label><br><textarea id="co-use" name="planned_use" required rows="3" cols="60">{{ old('planned_use') }}</textarea></p>
            <p><label for="co-motivation">{{ __('Motivation (optional)') }}</label><br><textarea id="co-motivation" name="motivation" rows="2" cols="60">{{ old('motivation') }}</textarea></p>
            <button type="submit">{{ __('Send the requests') }}</button>
        @endif
    </form>
@endsection
