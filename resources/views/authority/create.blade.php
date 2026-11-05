{{-- OpenRiC Workspace - a new authority record or place for a name, filled from outside authorities, each field with where it came from (Heratio's create-new with its prefill; roadmap phase 17b2). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('A new record for :name', ['name' => $mention->value()]))
@section('content')
    @php
        $place = $mention->isPlace();
        $labels = $place ? ['name' => __('Name'), 'latitude' => __('Latitude'), 'longitude' => __('Longitude')]
            : ['authorized_form_of_name' => __('Authorised form of name (5.1.2)'), 'dates_of_existence' => __('Dates of existence (5.2.1)'), 'history' => __('History (5.2.2)'), 'places' => __('Places (5.2.3)')];
        $origins = ['mention' => __('from the name as found'), 'proposal' => __('as it was proposed'), 'mention-context' => __('from the passage it was found in')];
    @endphp
    <p><a href="{{ route('authority.review', $mention) }}">{{ __('Back to :name', ['name' => $mention->value()]) }}</a></p>
    <h1>{{ $place ? __('A new place for :name', ['name' => $mention->value()]) : __('A new authority record for :name', ['name' => $mention->value()]) }}</h1>
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ __('The fields are filled from the outside authorities switched on for the institution, the first in its order winning each field. Check every one; each says where it came from, and that is kept with the new record.') }}</p>
    @if($sources->isEmpty())<p class="meta">{{ __('No outside authority is switched on; they are set under Admin, Resolving names.') }}</p>@endif
    <form method="post" action="{{ route('authority.create', $mention) }}">@csrf
        @foreach($labels as $key => $label)
            @php($p = $prefill['provenance'][$key] ?? null)
            <label for="cf-{{ $key }}">{{ $label }}</label>
            @if($key === 'history')<textarea id="cf-{{ $key }}" name="fields[{{ $key }}]" rows="5">{{ old('fields.'.$key, $prefill['fields'][$key] ?? '') }}</textarea>
            @else<input id="cf-{{ $key }}" name="fields[{{ $key }}]" value="{{ old('fields.'.$key, $prefill['fields'][$key] ?? '') }}">@endif
            @if($p)<input type="hidden" name="provenance[{{ $key }}]" value="{{ json_encode($p) }}">
                <small class="meta">@if($p['uri']){{ strtoupper($p['source']) }}, <a href="{{ $p['uri'] }}">{{ $p['uri'] }}</a>, {{ $p['licence'] }}@else{{ $origins[$p['origin']] ?? $p['origin'] }}@endif</small>@endif
        @endforeach
        <button type="submit">{{ __('Make it and link the name to it') }}</button>
    </form>
    @foreach($prefill['lookup_results'] as $source => $hits)
        <h2>{{ strtoupper($source) }}</h2>
        <ul>@foreach($hits as $h)<li><a href="{{ $h['external_uri'] }}">{{ $h['authorized_name'] }}</a>@if($h['dates_of_existence']) ({{ $h['dates_of_existence'] }})@endif @if($h['history_snippet'])<span class="meta">{{ $h['history_snippet'] }}</span>@endif</li>@endforeach</ul>
    @endforeach
@endsection
