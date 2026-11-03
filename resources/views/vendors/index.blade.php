{{-- OpenRiC Workspace - the vendor dashboard (Heratio's ahg-vendor index): work by status, what is overdue, the latest open work and twelve months. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Vendors'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Vendors') }}</h1>
    @include('vendors._nav')
    <p>{{ __('Conservators, binders, digitisation bureaux, framers and carriers the institution sends records to, and the work sent: approved, sent, done and brought back, record by record, with their condition before and after.') }}</p>
    <p>{{ trans_choice(':count vendor|:count vendors', $dashboard['vendors'], ['count' => $dashboard['vendors']]) }}</p>
    <h2>{{ __('Work by status') }}</h2>
    <table><thead><tr><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Transactions') }}</th></tr></thead>
        <tbody>@foreach($statuses as $s)<tr><td><a href="{{ route('vendors.transactions', ['status' => $s->notation]) }}">{{ $s->label($lang) }}</a></td><td>{{ $dashboard['byStatus'][$s->id] ?? 0 }}</td></tr>@endforeach</tbody></table>
    <h2>{{ __('Overdue') }}</h2>
    @include('vendors._transactions', ['transactions' => $dashboard['overdue'], 'none' => __('Nothing is overdue.')])
    <h2>{{ __('Open work') }}</h2>
    @include('vendors._transactions', ['transactions' => $dashboard['open'], 'none' => __('No open work.')])
    <h2>{{ __('The last twelve months') }}</h2>
    <table><thead><tr><th scope="col">{{ __('Month') }}</th><th scope="col">{{ __('Transactions') }}</th><th scope="col">{{ __('Cost') }}</th></tr></thead>
        <tbody>@forelse($dashboard['months'] as $month => $m)<tr><td>{{ $month }}</td><td>{{ $m['count'] }}</td><td>{{ number_format($m['cost'], 2) }}</td></tr>@empty<tr><td colspan="3">{{ __('None yet.') }}</td></tr>@endforelse</tbody></table>
@endsection
