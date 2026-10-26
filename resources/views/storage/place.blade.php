{{-- OpenRiC Workspace - a storage place: the places in it, its containers, their capacity, and what came and went. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $place->described('place-name', ['en']))
@section('content')
    @include('storage._nav')
    <h1>{{ $place->described('place-name', ['en']) }}</h1>
    <p>{{ $place->placeType?->anyLabel(['en']) }} @include('description._ric', ['term' => 'rico:Place']) · {{ $path }} · <a href="{{ route('places.show', $place) }}">{{ __('Its page as a place') }}</a></p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif

    @if($children->isNotEmpty())
        <h2>{{ __('Inside it') }}</h2>
        <ul>@foreach($children as $child)<li><a href="{{ route('storage.place', $child) }}">{{ $child->described('place-name', ['en']) }}</a> ({{ mb_strtolower((string) $child->placeType?->anyLabel(['en'])) }})</li>@endforeach</ul>
    @endif

    <h2>{{ __('Containers kept here (:count)', ['count' => $here->count()]) }} @include('description._ric', ['term' => 'rico:isOrWasLocationOf'])</h2>
    @if($here->isEmpty())
        <p>{{ __('None.') }}</p>
    @else
        <form method="post" action="{{ route('storage.place.move', $place) }}">
            @csrf
            <fieldset><legend>{{ __('Containers') }}</legend>
                @foreach($here as $container)
                    <label style="font-weight: normal">@if($mayMove)<input type="checkbox" name="containers[]" value="{{ $container->id }}"> @endif<a href="{{ route('containers.show', $container) }}">{{ $container->described('container-name', ['en']) }}</a>{{ $container->containerType ? ', '.mb_strtolower($container->containerType->anyLabel(['en'])) : '' }}</label>
                @endforeach
            </fieldset>
            @if($mayMove)
                <div style="display: flex; gap: .75rem; flex-wrap: wrap; align-items: end">
                    <div><label for="destination" style="margin-top: 0">{{ __('Move those chosen to') }}</label>
                        <select id="destination" name="destination" style="font: inherit; padding: .5rem">
                            <option value="">{{ __('Out of storage') }}</option>
                            @foreach($places->reject(fn ($p) => $p->id === $place->id) as $p)<option value="{{ $p->id }}">{{ $p->path }}</option>@endforeach
                        </select></div>
                    <div><label for="note" style="margin-top: 0">{{ __('Why (optional)') }}</label><input id="note" name="note" maxlength="2000"></div>
                    <button type="submit" style="margin-top: 0">{{ __('Move') }}</button>
                </div>
            @endif
        </form>
    @endif
    @if($below->count() > $here->count())<p>{{ __(':count more in the places inside it.', ['count' => $below->count() - $here->count()]) }}</p>@endif

    @if($capacity !== [])
        <h2>{{ __('Capacity, here and below') }}</h2>
        <ul>@foreach($capacity as $unit => $sum)<li>{{ __(':used of :capacity :unit used', ['used' => rtrim(rtrim(number_format($sum['used'], 2, '.', ''), '0'), '.'), 'capacity' => rtrim(rtrim(number_format($sum['capacity'], 2, '.', ''), '0'), '.'), 'unit' => mb_strtolower($unit)]) }}</li>@endforeach</ul>
    @endif

    @if($mayMove && $unplaced->isNotEmpty())
        <h2>{{ __('Put containers here') }}</h2>
        <form method="post" action="{{ route('storage.place.move', $place) }}">
            @csrf
            <fieldset><legend>{{ __('Containers kept nowhere yet') }}</legend>
                @foreach($unplaced->take(200) as $container)
                    <label style="font-weight: normal"><input type="checkbox" name="containers[]" value="{{ $container->id }}"> {{ $container->described('container-name', ['en']) }}</label>
                @endforeach
            </fieldset>
            <label for="place-note">{{ __('Why (optional)') }}</label><input id="place-note" name="note" maxlength="2000">
            <button type="submit">{{ __('Put them here') }}</button>
        </form>
    @endif

    <h2>{{ __('What came and went') }}</h2>
    @forelse($history as [$relation, $container])
        @php($from = $relation->datedAs('beginning')?->normalized_value)
        @php($to = $relation->datedAs('end')?->normalized_value)
        <p>@if($container)<a href="{{ route('containers.show', $container) }}">{{ $container->described('container-name', ['en']) }}</a>@else {{ __('A container since deleted') }} @endif: {{ $to ? __('here from :from to :to', ['from' => $from ?? '?', 'to' => $to]) : __('here from :from until now', ['from' => $from ?? '?']) }}@if($relation->description). {{ $relation->description }}@endif</p>
    @empty
        <p>{{ __('No container has been kept here.') }}</p>
    @endforelse
@endsection
