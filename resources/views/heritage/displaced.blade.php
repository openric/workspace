{{-- OpenRiC Workspace - the displaced-heritage register for staff (roadmap phase 17d3), after Heratio's displaced-heritage (ahg-semantic-search): each traced description, a step from registering a repatriation claim. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Displaced heritage register'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Potentially displaced heritage - review register') }}</h1>
    <p class="meta">{{ __(\App\Heritage\Displaced::DISCLAIMER) }}</p>
    @include('heritage._displaced-method')
    <p>{{ trans_choice(':count record with provenance scanned|:count records with provenance scanned', $report['scanned'], ['count' => $report['scanned']]) }} · {{ trans_choice(':count traced|:count traced', $report['flagged_count'], ['count' => $report['flagged_count']]) }}</p>
    @if($report['by_origin'] !== [])<p>{{ __('Browse by place of origin:') }} @if($origin === '')<strong>{{ __('Show all origins') }}</strong>@else<a href="{{ route('heritage.displaced') }}">{{ __('Show all origins') }}</a>@endif
        @foreach($report['by_origin'] as $o) · @if(strcasecmp($origin, $o['region']) === 0)<strong>{{ $o['region'] }}</strong>@else<a href="{{ route('heritage.displaced', ['origin' => $o['region']]) }}">{{ $o['region'] }}</a>@endif ({{ $o['count'] }})@endforeach</p>@endif
    @if($records === [])<p>{{ __('No origin-vs-holding mismatches were flagged. Records whose origin or holding location could not be confidently placed are intentionally left unflagged.') }}</p>@else
    <table><thead><tr><th scope="col">{{ __('Record') }}</th><th scope="col">{{ __('Recorded origin') }}</th><th scope="col">{{ __('Current holding') }}</th><th scope="col">{{ __('Confidence') }}</th><th scope="col">{{ __('Actions') }}</th></tr></thead>
        <tbody>@foreach($records as $r)<tr><td><a href="{{ route('records.show', $r['id']) }}">{{ $r['title'] }}</a></td>
            <td>{{ $r['origin_region'] }}<br><small>{{ $r['origin']['label'] }}: {{ $r['origin']['value'] }}</small></td><td>{{ $r['holding_region'] }}<br><small>{{ $r['holding']['label'] }}: {{ $r['holding']['value'] }}</small></td>
            <td>{{ __(['well-documented' => 'Well-documented origin', 'documented' => 'Documented origin', 'indicative' => 'Indicative origin'][$r['confidence']]) }}</td>
            <td><a href="{{ route('repatriation.create', ['record' => $r['id']]) }}">{{ __('Register a repatriation claim') }}</a></td></tr>@endforeach</tbody></table>
    @endif
@endsection
