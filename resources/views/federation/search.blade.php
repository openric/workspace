{{-- OpenRiC Workspace - the public catalogue's search of the institution's federation peers (Heratio's federation search; roadmap phase 16a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', $query !== '' ? __('Search other catalogues: :query', ['query' => $query]) : __('Search other catalogues'))
@section('content')
    <h1>{{ __('Search other catalogues') }}</h1>
    <p>{{ __('The catalogues :institution federates with, searched at once. Each result says where it comes from, and whether that catalogue\'s answer was signed by its key.', ['institution' => $institution->name]) }}</p>
    <form method="get" action="{{ route('discover.federated') }}" role="search">
        <label for="fq">{{ __('Search') }}</label><input id="fq" name="q" value="{{ $query }}" required>
        <button type="submit">{{ __('Search') }}</button> <a href="{{ route('discover.home', ['q' => $query]) }}">{{ __('Search this catalogue') }}</a></form>
    @if($found)@include('federation._results', ['found' => $found])@endif
@endsection
