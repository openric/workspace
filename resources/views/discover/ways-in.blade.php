{{-- OpenRiC Workspace - ways into the collection: themes, places, people, genres and time (Heratio's Explore the collection; roadmap phase 17d1). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', __('Ways into the collection'))
@section('content')
    <h1>{{ __('Ways into the collection') }}</h1>
    <p>{{ __('Begin with what the records are about, where, who made or gathered them, what kind of record they are, or when.') }}</p>
    @php($titles = ['themes' => __('Themes'), 'places' => __('Places'), 'people' => __('People and organisations'), 'genres' => __('Kinds of record')])
    @foreach($facets as $facet => $items)
        @if($items->isNotEmpty())
            <section><h2>{{ $titles[$facet] }}</h2>
                <ul class="ways-in">@foreach($items as $item)<li><a href="{{ route('discover.ways-in.units', [$facet, $item['id']]) }}">{{ $item['name'] }}</a> <span class="meta">{{ $item['n'] }}</span></li>@endforeach</ul>
                <p><a href="{{ route('discover.ways-in.facet', $facet) }}">{{ __('All of them') }}</a></p></section>
        @endif
    @endforeach
    @if($timeline['centuries'])
        <section><h2>{{ __('Over time') }}</h2>
            <ul class="ways-in">@foreach($timeline['centuries'] as $century => $c)<li>{{ __(':from to :to', ['from' => $century, 'to' => $century + 99]) }} <span class="meta">{{ $c['n'] }}</span></li>@endforeach</ul>
            <p><a href="{{ route('discover.ways-in.timeline') }}">{{ __('The timeline') }}</a></p></section>
    @endif
    @if($facets->every(fn ($i) => $i->isEmpty()) && ! $timeline['centuries'])<p>{{ __('Nothing is published yet.') }}</p>@endif
    {{-- Phase 17d4: the languages of the collection and the research leads its curators published. --}}
    <section><h2>{{ __('More ways in') }}</h2><p><a href="{{ route('discover.languages') }}">{{ __('Languages of the collection') }}</a> · <a href="{{ route('discover.research-leads') }}">{{ __('Research leads') }}</a></p></section>
@endsection
