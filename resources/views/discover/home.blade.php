{{-- OpenRiC Workspace - the public catalogue: what it holds, and search (WS-009). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', $query !== '' ? 'Search: '.$query : 'Catalogue')
@section('content')
    @if($query === '')
        {{-- The hero, as openric.org's: the institution's own introduction, what is published, and search. --}}
        <section class="catalogue-hero">
            <h1>The catalogue of {{ $institution->name }}</h1>
            @if($institution->catalogue_introduction)<div class="catalogue-lede">@include('discover._text', ['text' => $institution->catalogue_introduction])</div>@endif
            <p class="catalogue-counts">{{ $counts['records'] }} archival descriptions, {{ $counts['agents'] }} people, families and organisations, {{ $counts['activities'] }} activities and {{ $counts['places'] }} places are published here, described in Records in Contexts.</p>
            <form method="get" action="{{ route('discover.home') }}" role="search" class="catalogue-search">
                <label for="q">Search names, titles and reference codes</label>
                <div><input id="q" name="q" required><button type="submit">Search</button></div>
            </form>
        </section>
        @if($featured->isNotEmpty())
            <h2>Explore the collections</h2>
            @include('discover._carousel', ['featured' => $featured, 'view' => $view])
        @endif
        @if($tops->isNotEmpty())
            <h2>All collections</h2>
            <ul>@foreach($tops as $top)<li><a href="{{ route('discover.record', $top->id) }}">{{ $view->label($top) }}</a> <span class="meta">{{ collect([$top->level->label(['en', 'und']), $top->referenceCode()])->filter()->join(', ') }}</span></li>@endforeach</ul>
        @endif
        @if($repositories->isNotEmpty())
            <h2>Repositories</h2>
            <ul>@foreach($repositories as $repository)<li><a href="{{ route('discover.repository', $repository->id) }}">{{ $view->label($repository) }}</a></li>@endforeach</ul>
        @endif
        @if($counts->sum() === 0)<p>Nothing is published yet.</p>@endif
        @if($institution->catalogue_credits)
            <section class="catalogue-credits" aria-labelledby="credits">
                <h2 id="credits">Credits</h2>
                @include('discover._text', ['text' => $institution->catalogue_credits])
            </section>
        @endif
    @else
        <h1>Search: {{ $query }}</h1>
        <p>@foreach($kinds as $key => $name)@if($kind === $key)<strong>{{ $name }}</strong>@else<a href="{{ route('discover.home', ['q' => $query, 'kind' => $key]) }}">{{ $name }}</a>@endif · @endforeach
            @if($kind)<a href="{{ route('discover.home', ['q' => $query]) }}">Everything</a>@else<strong>Everything</strong>@endif</p>
        @php($found = collect($results)->sum(fn ($c) => $c->count()))
        <p role="status">{{ $found === 0 ? 'Nothing found.' : $found.' found.' }}</p>
        @foreach($results as $key => $entities)
            @continue($entities->isEmpty())
            <h2>{{ $kinds[$key] }} ({{ $entities->count() }})</h2>
            <ul>
                @foreach($entities->take($kind ? 200 : 20) as $entity)
                    <li><a href="{{ \App\Discovery\Links::page($entity) }}">{{ $view->label($entity) }}</a>
                        <span class="meta">{{ $entity instanceof \App\Models\RecordResource ? collect([$entity->referenceCode()])->filter()->join('') : \App\Relations\EntityKinds::noun($entity) }}</span></li>
                @endforeach
            </ul>
            @if(! $kind && $entities->count() > 20)<p><a href="{{ route('discover.home', ['q' => $query, 'kind' => $key]) }}">All {{ $entities->count() }}</a></p>@endif
        @endforeach
    @endif
@endsection
