{{-- OpenRiC Workspace - one's orders (Heratio's cart orders; roadmap phase 19a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('My orders'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('My orders') }}</h1>
    @if($orders->isEmpty())<p>{{ __('None.') }}</p>@else
    <table><thead><tr><th scope="col">{{ __('Order') }}</th><th scope="col">{{ __('Placed') }}</th><th scope="col">{{ __('Total') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead>
        <tbody>@foreach($orders as $o)<tr><td><a href="{{ route('shop.orders.show', $o) }}">{{ $o->reference }}</a></td><td>{{ $o->created_at->format('Y-m-d') }}</td><td>{{ $o->currency }} {{ number_format((float) $o->total, 2) }}</td><td>{{ $o->status->label($lang) }}</td></tr>@endforeach</tbody></table>
    @endif
@endsection
