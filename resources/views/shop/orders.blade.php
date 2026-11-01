{{-- OpenRiC Workspace - the shop's orders (Heratio's admin orders; roadmap phase 19a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Orders'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Orders') }}</h1>
    <p>{{ trans_choice(':count order|:count orders', $stats['count'], ['count' => $stats['count']]) }} · {{ __('paid in all: :amount', ['amount' => number_format((float) $stats['paid'], 2)]) }}</p>
    <p><a href="{{ route('shop.orders') }}">{{ __('All') }}</a>@foreach($statuses as $s) · @if($status === $s->notation)<strong>{{ $s->label($lang) }}</strong>@else<a href="{{ route('shop.orders', ['status' => $s->notation]) }}">{{ $s->label($lang) }}</a>@endif ({{ $stats['by'][$s->notation] ?? 0 }})@endforeach</p>
    @if($orders->isEmpty())<p>{{ __('None.') }}</p>@else
    <table><thead><tr><th scope="col">{{ __('Order') }}</th><th scope="col">{{ __('Customer') }}</th><th scope="col">{{ __('Placed') }}</th><th scope="col">{{ __('Total') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead>
        <tbody>@foreach($orders as $o)<tr><td><a href="{{ route('shop.orders.show', $o) }}">{{ $o->reference }}</a></td><td>{{ $o->customer_name }} ({{ $o->user->name }})</td><td>{{ $o->created_at->format('Y-m-d') }}</td><td>{{ $o->currency }} {{ number_format((float) $o->total, 2) }}</td><td>{{ $o->status->label($lang) }}</td></tr>@endforeach</tbody></table>
    @endif
@endsection
