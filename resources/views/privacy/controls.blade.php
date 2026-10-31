{{-- OpenRiC Workspace - the jurisdictions and the compliance control catalogue (roadmap phase 18a, ADR 0016), after Heratio's jurisdiction list and control catalogue. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Jurisdictions and compliance controls'))
@section('content')
    <p><a href="{{ route('privacy.index') }}">{{ __('Privacy') }}</a></p>
    <h1>{{ __('Jurisdictions and compliance controls') }}</h1>
    <h2>{{ __('Jurisdictions') }}</h2>
    <table><thead><tr><th scope="col">{{ __('Law') }}</th><th scope="col">{{ __('Regulator') }}</th><th scope="col">{{ __('Days to answer a request') }}</th><th scope="col">{{ __('Hours to report a compromise') }}</th></tr></thead>
        <tbody>@foreach($jurisdictions as $j)<tr><td>{{ $j->name }}: {{ $j->full_name }} ({{ $j->country }})</td><td>@if($j->regulator_url)<a href="{{ $j->regulator_url }}" rel="noopener">{{ $j->regulator }}</a>@else{{ $j->regulator }}@endif</td>
            <td>{{ $j->request_days }}</td><td>{{ $j->breach_notice_hours ?? __('as soon as reasonably possible') }}</td></tr>@endforeach</tbody></table>
    <h2>{{ __('Compliance controls') }}</h2>
    @foreach($controls as $c)
        <section style="border-top: 1px solid var(--line); padding: .3rem 0"><h3>{{ $c->code }}: {{ $c->name }}</h3>
            <p>{{ $c->objective }}</p>@if($c->recommended_config)<p class="meta">{{ __('Recommended') }}: {{ $c->recommended_config }}</p>@endif
            @if($c->standards)<p class="meta">{{ __('Standards') }}: {{ $c->standards }}</p>@endif
            @if($c->mappings)<ul>@foreach($c->mappings as $m)<li>{{ $m['regime'] }}@if($m['jurisdiction'] ?? null) ({{ $m['jurisdiction'] }})@endif: {{ $m['obligation'] ?? '' }}</li>@endforeach</ul>@endif</section>
    @endforeach
@endsection
