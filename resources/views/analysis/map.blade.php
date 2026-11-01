{{-- OpenRiC Workspace - a project's map (Heratio's map builder; roadmap phase 19i): its points, as GeoJSON too. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Map').': '.$project->title)
@section('content')
    @include('analysis._nav')
    <h1>{{ __('Map') }}</h1>
    <p class="meta"><a href="{{ route('research.analysis.map', [$project, 'format' => 'geojson']) }}">GeoJSON</a></p>
    @forelse($points as $p)
        <p><strong>{{ $p->label }}</strong>@if($p->place_name) · {{ $p->place_name }}@endif · <a href="https://www.openstreetmap.org/?mlat={{ $p->latitude }}&amp;mlon={{ $p->longitude }}#map=14/{{ $p->latitude }}/{{ $p->longitude }}" rel="noopener noreferrer">{{ number_format($p->latitude, 5) }}, {{ number_format($p->longitude, 5) }}</a>
            @if($p->date_valid_from) · {{ $p->date_valid_from->format('j M Y') }}@if($p->date_valid_to) - {{ $p->date_valid_to->format('j M Y') }}@endif @endif @if($p->description)<br>{{ $p->description }}@endif
            @if($level >= 2)<form method="post" action="{{ route('research.analysis.plot', [$project, 'points', $p->id]) }}" style="display: inline">@csrf<input type="hidden" name="remove" value="1"><button type="submit">{{ __('Remove') }}</button></form>@endif</p>
    @empty<p>{{ __('None.') }}</p>@endforelse
    @if($level >= 2)
        <h2>{{ __('Add a point') }}</h2>
        <form method="post" action="{{ route('research.analysis.plot', [$project, 'points']) }}">@csrf
            <label for="mp-label">{{ __('Label') }}</label> <input id="mp-label" name="label" required size="30"> <label for="mp-place">{{ __('Place') }}</label> <input id="mp-place" name="place_name" size="25"><br>
            <label for="mp-lat">{{ __('Latitude') }}</label> <input id="mp-lat" name="latitude" type="number" step="0.0000001" min="-90" max="90" required>
            <label for="mp-long">{{ __('Longitude') }}</label> <input id="mp-long" name="longitude" type="number" step="0.0000001" min="-180" max="180" required><br>
            <label for="mp-from">{{ __('From') }}</label> <input id="mp-from" name="date_valid_from" type="date"> <label for="mp-to">{{ __('To') }}</label> <input id="mp-to" name="date_valid_to" type="date">
            <label for="mp-desc">{{ __('Description') }}</label> <input id="mp-desc" name="description" size="40"> <button type="submit">{{ __('Add') }}</button></form>
    @endif
@endsection
