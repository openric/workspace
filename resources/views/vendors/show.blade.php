{{-- OpenRiC Workspace - a vendor: its profile, services, figures and work (Heratio's ahg-vendor view). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@php($name = \App\Relations\EntityKinds::label($vendor->agent))
@section('title', $name)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ $name }} @include('description._ric', ['term' => \App\Relations\EntityKinds::ricClass($vendor->agent)])</h1>
    @include('vendors._nav')
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p><a href="{{ route('agents.show', $vendor->agent) }}">{{ __('The authority record') }}</a>: {{ __('names, contact points and relations') }}
        @if($canUpdate) · <a href="{{ route('vendors.edit', $vendor) }}">{{ __('Edit the vendor profile') }}</a>@endif
        @if(auth()->user()->may('create', 'vendor') && $vendor->status->notation === 'active') · <a href="{{ route('vendors.request', $vendor) }}">{{ __('Request work') }}</a>@endif</p>
    <dl>
        <dt>{{ __('Vendor code') }}</dt><dd>{{ $vendor->vendor_code }}</dd>
        <dt>{{ __('Type') }}</dt><dd>{{ $vendor->vendorType->label($lang) }}</dd>
        <dt>{{ __('Status') }}</dt><dd>{{ $vendor->status->label($lang) }}@if($vendor->approved_at) · {{ __('approved :date', ['date' => $vendor->approved_at->format('Y-m-d')]) }}@endif</dd>
        @if($vendor->registration_number)<dt>{{ __('Registration number') }}</dt><dd>{{ $vendor->registration_number }}</dd>@endif
        @if($vendor->vat_number)<dt>{{ __('VAT number') }}</dt><dd>{{ $vendor->vat_number }}</dd>@endif
        <dt>{{ __('Insurance') }}</dt><dd>@if($vendor->has_insurance){{ collect([$vendor->insurance_provider, $vendor->insurance_policy_number, $vendor->insurance_coverage_amount ? number_format((float) $vendor->insurance_coverage_amount, 2) : null])->filter()->join(', ') }}@if($vendor->insurance_expiry_date) · {{ __('until :date', ['date' => $vendor->insurance_expiry_date->format('Y-m-d')]) }}@endif @unless($insured) <strong>{{ __('lapsed') }}</strong>@endunless @else{{ __('None') }}@endif</dd>
        @foreach(['quality_rating' => __('Quality'), 'reliability_rating' => __('Reliability'), 'price_rating' => __('Price')] as $f => $label)
            @if($vendor->{$f})<dt>{{ $label }}</dt><dd>{{ trans_choice(':count star|:count stars', $vendor->{$f}, ['count' => $vendor->{$f}]) }}</dd>@endif
        @endforeach
        @if($vendor->is_preferred || $vendor->is_bbbee_compliant)<dt>{{ __('Also') }}</dt><dd>{{ collect([$vendor->is_preferred ? __('Preferred vendor') : null, $vendor->is_bbbee_compliant ? __('B-BBEE compliant') : null])->filter()->join(', ') }}</dd>@endif
        @if($vendor->bank_account_number && $canUpdate)<dt>{{ __('Bank details') }}</dt><dd>{{ $vendor->bank_name }} · {{ __('account ending :digits', ['digits' => substr($vendor->bank_account_number, -4)]) }}</dd>@endif
        @if($vendor->notes)<dt>{{ __('Notes') }}</dt><dd>{{ $vendor->notes }}</dd>@endif
    </dl>
    <h2>{{ __('Contact points') }}</h2>
    @forelse($vendor->agent->contactPoints as $cp)<p>{{ collect([$cp->label, $cp->contact_person, $cp->telephone, $cp->email])->filter()->join(' · ') }}</p>@empty<p>{{ __('None: add them on the authority record.') }}</p>@endforelse
    <h2>{{ __('Services') }}</h2>
    <table><thead><tr><th scope="col">{{ __('Service') }} @include('description._ric', ['term' => 'rico:hasActivityType'])</th><th scope="col">{{ __('Hourly rate') }}</th><th scope="col">{{ __('Fixed rate') }}</th><th scope="col">{{ __('Notes') }}</th></tr></thead>
        <tbody>@forelse($vendor->services as $s)<tr><td>{{ $s->serviceType->label($lang) }}@if($s->is_preferred) · {{ __('preferred') }}@endif</td><td>{{ $s->hourly_rate ? $s->currency?->notation.' '.$s->hourly_rate : '' }}</td><td>{{ $s->fixed_rate ? $s->currency?->notation.' '.$s->fixed_rate : '' }}</td><td>{{ $s->notes }}</td></tr>
        @empty<tr><td colspan="4">{{ __('No services yet.') }}</td></tr>@endforelse</tbody></table>
    <h2>{{ __('Figures') }}</h2>
    <p>{{ __(':total transactions, :active open and :completed returned; :on_time back on time; :items records handled; cost :cost; on average :days days away.', ['total' => $figures['total'], 'active' => $figures['active'],
        'completed' => $figures['completed'], 'on_time' => $figures['on_time'], 'items' => $figures['items'], 'cost' => number_format($figures['total_cost'], 2), 'days' => $figures['turnaround'] ?? '-']) }}</p>
    <h2>{{ __('Work') }} @include('description._ric', ['term' => 'rico:isOrWasPerformedBy'])</h2>
    @include('vendors._transactions', ['transactions' => $transactions->each(fn ($t) => $t->setRelation('vendor', $vendor)), 'none' => __('No work yet.')])
@endsection
