{{-- OpenRiC Workspace - the public displaced-heritage register (roadmap phase 17d3), after Heratio's displaced-heritage/index (ahg-semantic-search). Needs $report, $records, $origin, $lodging. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', __('Displaced heritage'))
@section('content')
    <h1>{{ __('Displaced heritage') }}</h1>
    <p>{{ __('Tracing objects whose recorded origin lies far from where they are held today.') }}</p>
    <p>{{ __('This register draws together catalogue records whose documented place or community of origin differs from their current holding location. It is offered in a spirit of transparency and care: to make displacement visible, to honour the communities and places of origin, and - where a digital reconstruction or surrogate exists - to allow each object to be encountered again in its own context.') }}</p>
    <p class="meta">{{ __(\App\Heritage\Displaced::DISCLAIMER) }}</p>
    @include('heritage._displaced-method')
    @if($report['by_origin'] !== [])<p>{{ __('Browse by place of origin:') }} @if($origin === '')<strong>{{ __('Show all origins') }}</strong>@else<a href="{{ route('discover.displaced') }}">{{ __('Show all origins') }}</a>@endif
        @foreach($report['by_origin'] as $o) · @if(strcasecmp($origin, $o['region']) === 0)<strong>{{ $o['region'] }}</strong>@else<a href="{{ route('discover.displaced', ['origin' => $o['region']]) }}">{{ $o['region'] }}</a>@endif ({{ $o['count'] }})@endforeach</p>@endif
    @if($records === [])<h2>{{ $origin === '' ? __('No displaced items have been traced yet') : __('No traced items for this place of origin') }}</h2>@else
    <ul>@foreach($records as $r)<li><a href="{{ route('discover.displaced.show', $r['id']) }}">{{ $r['title'] }}</a> · {{ __('Recorded origin') }}: {{ $r['origin_region'] }} · {{ __('Current holding') }}: {{ $r['holding_region'] }}</li>@endforeach</ul>
    @endif
@endsection
