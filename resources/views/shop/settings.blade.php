{{-- OpenRiC Workspace - the shop's settings (Heratio's e-commerce settings: general, payment and pricing; roadmap phase 19a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Shop settings'))
@section('content')
    <h1>{{ __('Shop settings') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <form method="post" action="{{ route('shop.settings.save') }}">@csrf
        <h2>{{ __('General') }}</h2>
        <p><label><input type="hidden" name="is_enabled" value="0"><input type="checkbox" name="is_enabled" value="1" @checked($settings->is_enabled)> {{ __('The shop is open: records are ordered and paid for. When it is closed, the cart asks for each record as a request to publish.') }}</label></p>
        <p><label for="s-currency">{{ __('Currency') }}</label> <input id="s-currency" name="currency" required maxlength="3" value="{{ $settings->currency }}" style="width: 4rem">
            <label for="s-vat">{{ __('VAT rate (%), included in the prices') }}</label> <input id="s-vat" name="vat_rate" type="number" step="0.01" min="0" max="100" value="{{ $settings->vat_rate }}" style="width: 6rem">
            <label for="s-vatno">{{ __('VAT number') }}</label> <input id="s-vatno" name="vat_number" value="{{ $settings->vat_number }}"></p>
        <p><label for="s-days">{{ __('A paid download stays open for (days)') }}</label> <input id="s-days" name="download_days" type="number" min="1" max="365" value="{{ $settings->download_days }}" style="width: 5rem">
            <label for="s-limit">{{ __('and may be downloaded (times)') }}</label> <input id="s-limit" name="download_limit" type="number" min="1" max="100" value="{{ $settings->download_limit }}" style="width: 5rem"></p>
        <p><label for="s-terms">{{ __('Terms and conditions') }}</label><br><textarea id="s-terms" name="terms" rows="5" cols="80">{{ $settings->terms }}</textarea></p>
        <h2>{{ __('PayFast') }}</h2>
        <p><label for="s-merchant">{{ __('Merchant ID') }}</label> <input id="s-merchant" name="payfast_merchant_id" value="{{ $settings->payfast_merchant_id }}">
            <label for="s-key">{{ __('Merchant key') }}</label> <input id="s-key" name="payfast_merchant_key" type="password" autocomplete="new-password" placeholder="{{ $settings->payfast_merchant_key ? __('set; empty keeps it') : '' }}">
            <label for="s-pass">{{ __('Passphrase') }}</label> <input id="s-pass" name="payfast_passphrase" type="password" autocomplete="new-password" placeholder="{{ $settings->payfast_passphrase ? __('set; empty keeps it') : '' }}"></p>
        <p><label><input type="hidden" name="payfast_sandbox" value="0"><input type="checkbox" name="payfast_sandbox" value="1" @checked($settings->payfast_sandbox)> {{ __('Sandbox: test payments only') }}</label></p>
        <p class="meta">{{ __('A live shop refuses every payment until a passphrase is set. PayFast notifies :url.', ['url' => route('shop.payfast.notify')]) }}</p>
        <button type="submit">{{ __('Save') }}</button>
    </form>

    <h2>{{ __('Products and prices') }}</h2>
    @foreach($types as $t)
        <details><summary>{{ $t->name }} · {{ $settings->currency }} {{ number_format((float) ($t->prices->first()?->price ?? 0), 2) }}@unless($t->is_active) ({{ __('inactive') }})@endunless</summary>
            <form method="post" action="{{ route('shop.settings.products.update', $t) }}">@csrf @include('shop._product-fields', ['t' => $t, 'p' => 'pt'.$loop->index])<button type="submit">{{ __('Save') }}</button></form>
        </details>
    @endforeach
    <details><summary>{{ __('Add a product') }}</summary>
        <form method="post" action="{{ route('shop.settings.products.store') }}">@csrf @include('shop._product-fields', ['t' => null, 'p' => 'pt-new'])<button type="submit">{{ __('Add') }}</button></form></details>
@endsection
