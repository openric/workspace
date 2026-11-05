{{-- OpenRiC Workspace - one way into the collection in full (Heratio's themes, places, people and genres pages; roadmap phase 17d1). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@php($titles = ['themes' => __('Themes'), 'places' => __('Places'), 'people' => __('People and organisations'), 'genres' => __('Kinds of record')])
@section('title', $titles[$facet])
@section('content')
    <p><a href="{{ route('discover.ways-in') }}">{{ __('Ways into the collection') }}</a></p>
    <h1>{{ $titles[$facet] }}</h1>
    <ul class="ways-in">@forelse($items as $item)<li><a href="{{ route('discover.ways-in.units', [$facet, $item['id']]) }}">{{ $item['name'] }}</a> <span class="meta">{{ trans_choice(':count description|:count descriptions', $item['n'], ['count' => $item['n']]) }}</span></li>
        @empty<li>{{ __('Nothing is published yet.') }}</li>@endforelse</ul>
@endsection
