{{-- OpenRiC Workspace - one container: where it is kept and was kept, what it holds, and its facts. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $container->described('container-name', [app()->getLocale(), 'en']))
@section('content')
    @include('storage._nav')
    <h1>{{ $container->described('container-name', [app()->getLocale(), 'en']) }}</h1>
    <p>Container @include('description._ric', ['term' => 'openricx:Container'])</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    @php($barcode = $container->identifiers->first(fn ($i) => $i->identifierType->notation === 'barcode')?->value)
    <p><strong>Persistent IRI:</strong> <code>{{ $container->iri }}</code>
        <br><strong>Container type:</strong> {{ $container->containerType ? $container->containerType->anyLabel(['en']).' ('.$container->containerType->vocabulary->title.')' : 'not set' }} @include('description._ric', ['term' => 'openricx:hasContainerType'])
        @if($barcode)<br><strong>Barcode:</strong> {{ $barcode }} @include('description._ric', ['term' => 'rico:Identifier'])@endif
        <br><strong>Kept in:</strong> {{ $path ?? 'nowhere yet' }} @include('description._ric', ['term' => 'rico:hasOrHadLocation'])
        <br><a href="{{ route('containers.box-list', $container) }}">Box list to print</a></p>
    @if($canUpdate)
        @include('description._actions', ['subject' => $container, 'editRoute' => 'containers.edit', 'required' => 'container-name'])
        <h2>Move</h2>
        <form method="post" action="{{ route('containers.move', $container) }}" style="display: flex; gap: .75rem; flex-wrap: wrap; align-items: end">
            @csrf
            <div><label for="move-place" style="margin-top: 0">To</label>
                <select id="move-place" name="place" style="font: inherit; padding: .5rem">
                    <option value="">Out of storage</option>
                    @foreach($places as $p)<option value="{{ $p->id }}" @selected($place?->id === $p->id)>{{ $p->path }}</option>@endforeach
                </select></div>
            <div><label for="move-day" style="margin-top: 0">On</label><input id="move-day" name="day" type="date" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}"></div>
            <div><label for="move-note" style="margin-top: 0">Why (optional)</label><input id="move-note" name="note" maxlength="2000"></div>
            <button type="submit" style="margin-top: 0">Move</button>
        </form>
        @if($places->isEmpty())<p><small>There are no storage places yet: make them under <a href="{{ route('storage.places') }}">Storage places</a>.</small></p>@endif
    @endif

    <h2>Where it was kept @include('description._ric', ['term' => 'rico:hasOrHadLocation'])</h2>
    @forelse($locations->reverse() as [$relation, $at])
        @php($from = $relation->datedAs('beginning')?->normalized_value)
        @php($to = $relation->datedAs('end')?->normalized_value)
        <p>{{ $at ? $storage->path($at) : 'a place since deleted' }}: from {{ $from ?? '?' }} {{ $to ? 'to '.$to : 'until now' }}@if($relation->description). {{ $relation->description }}@endif</p>
    @empty
        <p>Not kept anywhere yet.</p>
    @endforelse

    <h2>Facts</h2>
    <dl>
        <dt>Status</dt><dd>{{ $container->status?->label(['en']) ?? 'not set' }} @include('description._ric', ['term' => null])</dd>
        <dt>Security</dt><dd>{{ $container->securityLevel?->label(['en']) ?? 'not set' }} @include('description._ric', ['term' => null])</dd>
        <dt>Size (width, height, depth)</dt><dd>{{ collect([$container->width_cm, $container->height_cm, $container->depth_cm])->map(fn ($v) => $v === null ? '?' : rtrim(rtrim((string) $v, '0'), '.'))->join(' x ') }} cm @include('description._ric', ['term' => null])</dd>
        <dt>Capacity</dt><dd>@if($container->capacity !== null){{ rtrim(rtrim((string) $container->capacity_used, '0'), '.') ?: 0 }} of {{ rtrim(rtrim((string) $container->capacity, '0'), '.') }} {{ mb_strtolower((string) $container->capacityUnit?->label(['en'])) }} used @else not set @endif @include('description._ric', ['term' => null])</dd>
        <dt>Environment</dt><dd>{{ $container->climate_controlled === null ? 'climate control not recorded' : ($container->climate_controlled ? 'climate controlled' : 'not climate controlled') }}@if($container->temperature_min !== null || $container->temperature_max !== null), {{ $container->temperature_min ?? '?' }} to {{ $container->temperature_max ?? '?' }} °C @endif @if($container->humidity_min !== null || $container->humidity_max !== null), {{ $container->humidity_min ?? '?' }} to {{ $container->humidity_max ?? '?' }}% relative humidity @endif @include('description._ric', ['term' => null])</dd>
    </dl>

    @include('description._view', ['subject' => $container, 'standard' => \App\Description\Elements::CONTAINER, 'drafts' => $canUpdate])
    @include('description._relations', ['subject' => $container, 'kind' => 'containers', 'relations' => $relations, 'canUpdate' => $canUpdate,
        'heading' => 'What it holds, and what holds it', 'types' => ['storage'], 'section' => 'st', 'add' => 'storage|target', 'addLabel' => 'Add a unit of description, an instantiation or a container it holds'])
    @include('description._dates', ['subject' => $container, 'kind' => 'containers', 'heading' => 'Dates', 'canUpdate' => $canUpdate, 'choices' => $dateChoices])
    @include('description._names', ['subject' => $container, 'kind' => 'containers', 'canUpdate' => $canUpdate])
    @include('description._identifiers', ['subject' => $container, 'kind' => 'containers', 'canUpdate' => $canUpdate])
@endsection
