{{-- OpenRiC Workspace - requesting work of a vendor, or changing it (Heratio's ahg-vendor add-transaction and edit-transaction). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@php($name = \App\Relations\EntityKinds::label($vendor->agent))
@section('title', $transaction ? __('Edit :number', ['number' => $transaction->transaction_number]) : __('Request work of :name', ['name' => $name]))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($v = fn ($f) => old($f, $transaction?->{$f} instanceof \DateTimeInterface ? $transaction->{$f}->format('Y-m-d') : $transaction?->{$f}))
    <h1>{{ $transaction ? __('Edit :number', ['number' => $transaction->transaction_number]) : __('Request work of :name', ['name' => $name]) }}</h1>
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <form method="post" action="{{ $transaction ? route('vendors.transaction.update', $transaction) : route('vendors.transactions.store', $vendor) }}">
        @csrf
        @unless($transaction)
            <label for="service_type">{{ __('Service') }} @include('description._ric', ['term' => 'rico:hasActivityType'])</label>
            <select id="service_type" name="service_type" required>@foreach($vendor->services as $s)<option value="{{ $s->serviceType->notation }}" @selected(old('service_type') === $s->serviceType->notation)>{{ $s->serviceType->label($lang) }}</option>@endforeach</select>
        @endunless
        <fieldset><legend>{{ __('Dates') }}</legend>
            @foreach(['request_date' => __('Requested on'), 'expected_return_date' => __('Due back on'), 'completion_date' => __('Work done on'), 'invoice_date' => __('Invoiced on'), 'payment_date' => __('Paid on')] as $f => $label)
                <label for="{{ $f }}">{{ $label }}</label><input type="date" id="{{ $f }}" name="{{ $f }}" value="{{ $v($f) ?? ($f === 'request_date' ? now()->toDateString() : '') }}">
            @endforeach
        </fieldset>
        <fieldset><legend>{{ __('Cost and payment') }}</legend>
            <label for="currency">{{ __('Currency') }}</label><select id="currency" name="currency">@foreach($currencies as $c)<option value="{{ $c->notation }}" @selected(old('currency', $transaction?->currency?->notation ?? 'ZAR') === $c->notation)>{{ $c->notation }}</option>@endforeach</select>
            @foreach(['estimated_cost' => __('Estimated cost'), 'actual_cost' => __('Actual cost'), 'quote_reference' => __('Quote reference'), 'invoice_reference' => __('Invoice reference')] as $f => $label)
                <label for="{{ $f }}">{{ $label }}</label><input id="{{ $f }}" name="{{ $f }}" value="{{ $v($f) }}" maxlength="100">
            @endforeach
            <label for="payment_status">{{ __('Payment') }}</label><select id="payment_status" name="payment_status">@foreach($payments as $p)<option value="{{ $p->notation }}" @selected(old('payment_status', $transaction?->paymentStatus?->notation ?? 'pending') === $p->notation)>{{ $p->label($lang) }}</option>@endforeach</select>
        </fieldset>
        <fieldset><legend>{{ __('Insurance and shipping') }}</legend>
            <label for="total_insured_value">{{ __('Total insured value') }}</label><input id="total_insured_value" name="total_insured_value" value="{{ $v('total_insured_value') }}" inputmode="decimal">
            <label><input type="checkbox" name="insurance_arranged" value="1" @checked(old('insurance_arranged', $transaction?->insurance_arranged))> {{ __('Insurance arranged for this work') }}</label>
            @foreach(['insurance_reference' => __('Insurance reference'), 'shipping_method' => __('Shipping method'), 'courier_company' => __('Courier'), 'tracking_number' => __('Tracking number')] as $f => $label)
                <label for="{{ $f }}">{{ $label }}</label><input id="{{ $f }}" name="{{ $f }}" value="{{ $v($f) }}" maxlength="100">
            @endforeach
        </fieldset>
        @foreach(['dispatch_notes' => __('Dispatch notes'), 'vendor_notes' => __('The vendor\'s notes'), 'return_notes' => __('Return notes'), 'internal_notes' => __('Internal notes')] as $f => $label)
            <label for="{{ $f }}">{{ $label }}</label><textarea id="{{ $f }}" name="{{ $f }}" rows="2">{{ $v($f) }}</textarea>
        @endforeach
        <button type="submit">{{ $transaction ? __('Save') : __('Request the work') }}</button>
    </form>
@endsection
