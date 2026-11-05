{{-- OpenRiC Workspace - one traced description in the public displaced-heritage register (roadmap phase 17d3), after Heratio's displaced-heritage/show (ahg-semantic-search). Needs $unit, $entry, $lodging. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', $entry['title'])
@section('content')
    <p><a href="{{ route('discover.displaced') }}">{{ __('Back to the full register') }}</a></p>
    <h1>{{ $view->label($unit) }}</h1>
    <p class="meta">{{ __('A review aid - not a repatriation claim, and not legal advice.') }}</p>
    <h2>{{ __('Origin and current holding') }}</h2>
    <dl>
        <dt>{{ __('Recorded origin') }}</dt><dd>{{ $entry['origin_region'] }} <small>({{ $entry['origin']['label'] }}: {{ $entry['origin']['value'] }})</small></dd>
        <dt>{{ __('Current holding') }}</dt><dd>{{ $entry['holding_region'] }} <small>({{ $entry['holding']['label'] }}: {{ $entry['holding']['value'] }})</small></dd>
        <dt>{{ __('Confidence') }}</dt><dd>{{ __(['well-documented' => 'Well-documented origin', 'documented' => 'Documented origin', 'indicative' => 'Indicative origin'][$entry['confidence']]) }}</dd>
    </dl>
    <p>{{ $entry['reason'] }}</p>
    <p><a href="{{ route('discover.record', $unit->id) }}">{{ __('Open the full catalogue record') }}</a>@if($lodging) · <a href="{{ route('discover.repatriation.lodge', $unit->id) }}">{{ __('Lodge a repatriation claim about this object') }}</a>@endif</p>
    <p class="meta">{{ __($disclaimer) }} {{ __('This entry is maintained in dialogue with communities and holding institutions. Origin, ownership and lawful-transfer history are matters for qualified staff and the relevant communities to assess together, case by case.') }}</p>
@endsection
