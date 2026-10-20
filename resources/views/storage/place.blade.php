{{-- OpenRiC Workspace - a storage place: the places in it, its containers, their capacity, and what came and went. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $place->described('place-name', ['en']))
@section('content')
    @include('storage._nav')
    <h1>{{ $place->described('place-name', ['en']) }}</h1>
    <p>{{ $place->placeType?->anyLabel(['en']) }} @include('description._ric', ['term' => 'rico:Place']) · {{ $path }} · <a href="{{ route('places.show', $place) }}">Its page as a place</a></p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif

    @if($children->isNotEmpty())
        <h2>Inside it</h2>
        <ul>@foreach($children as $child)<li><a href="{{ route('storage.place', $child) }}">{{ $child->described('place-name', ['en']) }}</a> ({{ mb_strtolower((string) $child->placeType?->anyLabel(['en'])) }})</li>@endforeach</ul>
    @endif

    <h2>Containers kept here ({{ $here->count() }}) @include('description._ric', ['term' => 'rico:isOrWasLocationOf'])</h2>
    @if($here->isEmpty())
        <p>None.</p>
    @else
        <form method="post" action="{{ route('storage.place.move', $place) }}">
            @csrf
            <fieldset><legend>Containers</legend>
                @foreach($here as $container)
                    <label style="font-weight: normal">@if($mayMove)<input type="checkbox" name="containers[]" value="{{ $container->id }}"> @endif<a href="{{ route('containers.show', $container) }}">{{ $container->described('container-name', ['en']) }}</a>{{ $container->containerType ? ', '.mb_strtolower($container->containerType->anyLabel(['en'])) : '' }}</label>
                @endforeach
            </fieldset>
            @if($mayMove)
                <div style="display: flex; gap: .75rem; flex-wrap: wrap; align-items: end">
                    <div><label for="destination" style="margin-top: 0">Move those chosen to</label>
                        <select id="destination" name="destination" style="font: inherit; padding: .5rem">
                            <option value="">Out of storage</option>
                            @foreach($places->reject(fn ($p) => $p->id === $place->id) as $p)<option value="{{ $p->id }}">{{ $p->path }}</option>@endforeach
                        </select></div>
                    <div><label for="note" style="margin-top: 0">Why (optional)</label><input id="note" name="note" maxlength="2000"></div>
                    <button type="submit" style="margin-top: 0">Move</button>
                </div>
            @endif
        </form>
    @endif
    @if($below->count() > $here->count())<p>{{ $below->count() - $here->count() }} more in the places inside it.</p>@endif

    @if($capacity !== [])
        <h2>Capacity, here and below</h2>
        <ul>@foreach($capacity as $unit => $sum)<li>{{ rtrim(rtrim(number_format($sum['used'], 2, '.', ''), '0'), '.') }} of {{ rtrim(rtrim(number_format($sum['capacity'], 2, '.', ''), '0'), '.') }} {{ mb_strtolower($unit) }} used</li>@endforeach</ul>
    @endif

    @if($mayMove && $unplaced->isNotEmpty())
        <h2>Put containers here</h2>
        <form method="post" action="{{ route('storage.place.move', $place) }}">
            @csrf
            <fieldset><legend>Containers kept nowhere yet</legend>
                @foreach($unplaced->take(200) as $container)
                    <label style="font-weight: normal"><input type="checkbox" name="containers[]" value="{{ $container->id }}"> {{ $container->described('container-name', ['en']) }}</label>
                @endforeach
            </fieldset>
            <label for="place-note">Why (optional)</label><input id="place-note" name="note" maxlength="2000">
            <button type="submit">Put them here</button>
        </form>
    @endif

    <h2>What came and went</h2>
    @forelse($history as [$relation, $container])
        @php($from = $relation->datedAs('beginning')?->normalized_value)
        @php($to = $relation->datedAs('end')?->normalized_value)
        <p>@if($container)<a href="{{ route('containers.show', $container) }}">{{ $container->described('container-name', ['en']) }}</a>@else A container since deleted @endif: here from {{ $from ?? '?' }} {{ $to ? 'to '.$to : 'until now' }}@if($relation->description). {{ $relation->description }}@endif</p>
    @empty
        <p>No container has been kept here.</p>
    @endforelse
@endsection
