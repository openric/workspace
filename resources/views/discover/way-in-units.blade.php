{{-- OpenRiC Workspace - the published units one way in leads to (roadmap phase 17d1). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', $item['name'])
@section('content')
    <p><a href="{{ route('discover.ways-in') }}">{{ __('Ways into the collection') }}</a> /
        @if($facet)<a href="{{ route('discover.ways-in.facet', $facet) }}">{{ ['themes' => __('Themes'), 'places' => __('Places'), 'people' => __('People and organisations'), 'genres' => __('Kinds of record')][$facet] }}</a>@else<a href="{{ route('discover.ways-in.timeline') }}">{{ __('The collection over time') }}</a>@endif</p>
    <h1>{{ $item['name'] }}</h1>
    @if($item['url'])<p><a href="{{ $item['url'] }}">{{ __('About :name', ['name' => $item['name']]) }}</a></p>@endif
    <p class="meta">{{ trans_choice(':count description|:count descriptions', $units->total(), ['count' => $units->total()]) }}</p>
    <ul>@foreach($units as $u)<li><a href="{{ route('discover.record', $u->subject_id) }}">{{ $u->title }}</a><br><span class="meta">{{ \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/u', ' ', (string) $u->body)), 200) }}</span></li>@endforeach</ul>
    {{ $units->links() }}
@endsection
