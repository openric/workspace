{{-- OpenRiC Workspace - the public catalogue's search of the union catalogue (Heratio's union catalogue; roadmap phase 16b). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', $query !== '' ? __('Union catalogue: :query', ['query' => $query]) : __('Union catalogue'))
@section('content')
    <h1>{{ __('Union catalogue') }}</h1>
    <p>{{ __('The records :institution and the other members of its union publish, in one place. Each says which member holds it.', ['institution' => $institution->name]) }}</p>
    <form method="get" action="{{ route('discover.union') }}" role="search"><label for="uq">{{ __('Search') }}</label><input id="uq" name="q" value="{{ $query }}" required><button type="submit">{{ __('Search') }}</button>
        <a href="{{ route('discover.home', ['q' => $query]) }}">{{ __('Search this catalogue') }}</a></form>
    @if($found)@include('federation._union', ['found' => $found])@endif
@endsection
