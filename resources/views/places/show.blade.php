{{-- OpenRiC Workspace - one place. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $place->described('place-name', [app()->getLocale(), 'en']))
@section('content')
    <p><a href="{{ route('places.index') }}">All places</a>@foreach($place->ancestors() as $above) › <a href="{{ route('places.show', $above) }}">{{ $above->described('place-name', [app()->getLocale(), 'en']) }}</a>@endforeach</p>
    <h1>{{ $place->described('place-name', [app()->getLocale(), 'en']) }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p><strong>Persistent IRI:</strong> <code>{{ $place->iri }}</code>
        <br><strong>Place type:</strong> {{ $place->placeType?->anyLabel(['en']) ?? 'not set' }}
        <br><strong>Coordinates:</strong>
        @if($place->latitude !== null){{ $place->latitude }}, {{ $place->longitude }}@if($place->altitude !== null), {{ $place->altitude }} m @endif (WGS 84)
            · <a href="https://www.openstreetmap.org/?mlat={{ $place->latitude }}&amp;mlon={{ $place->longitude }}#map=12/{{ $place->latitude }}/{{ $place->longitude }}" rel="noopener noreferrer">Show on OpenStreetMap</a>
        @else not set @endif</p>
    @php($canUpdate = auth()->user()->may('update', 'vocabulary', $place))
    @if($canUpdate)
        @include('description._actions', ['subject' => $place, 'editRoute' => 'places.edit', 'required' => 'place-name'])
        <p><a href="{{ route('places.move-form', $place) }}">Change the place it lies in</a></p>
    @endif
    @include('description._view', ['subject' => $place, 'standard' => \App\Description\Elements::PLACE, 'drafts' => $canUpdate])

    <h2>Places in it</h2>
    @forelse($place->children->sortBy(fn ($c) => mb_strtolower($c->described('place-name', [app()->getLocale(), 'en']))) as $child)
        <p><a href="{{ route('places.show', $child) }}">{{ $child->described('place-name', [app()->getLocale(), 'en']) }}</a>@if($child->placeType), {{ $child->placeType->anyLabel(['en']) }}@endif</p>
    @empty
        <p>None.</p>
    @endforelse
    @if(auth()->user()->may('create', 'vocabulary'))<p><a href="{{ route('places.create', ['parent' => $place->id]) }}">Add a place in it</a></p>@endif

    @include('description._names', ['subject' => $place, 'kind' => 'places', 'canUpdate' => $canUpdate])
    @include('description._identifiers', ['subject' => $place, 'kind' => 'places', 'canUpdate' => $canUpdate])
    @include('description._relations', ['subject' => $place, 'kind' => 'places', 'relations' => $relations, 'canUpdate' => $canUpdate,
        'heading' => 'Associated with', 'types' => ['place'], 'section' => 'pl', 'add' => 'place|source', 'addLabel' => 'Associate it with a unit of description or an agent'])
    @include('description._relations', ['subject' => $place, 'kind' => 'places', 'relations' => $relations, 'canUpdate' => $canUpdate,
        'heading' => 'Units of description about it', 'types' => ['subject'], 'section' => 'su', 'add' => 'subject|target', 'addLabel' => 'Add a unit of description about it'])
    @include('description._relations', ['subject' => $place, 'kind' => 'places', 'relations' => $relations, 'canUpdate' => $canUpdate,
        'heading' => 'Containment over time', 'types' => ['place-containment'], 'section' => 'co', 'add' => null, 'addLabel' => 'Add a containment, with its dates'])
    @include('description._dates', ['subject' => $place, 'kind' => 'places', 'heading' => 'Dates', 'canUpdate' => $canUpdate, 'choices' => $dateChoices])

@endsection
