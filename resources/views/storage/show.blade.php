{{-- OpenRiC Workspace - one container: where it is kept and was kept, what it holds, and its facts. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $container->described('container-name', [app()->getLocale(), 'en']))
@section('content')
    @include('storage._nav')
    <h1>{{ $container->described('container-name', [app()->getLocale(), 'en']) }}</h1>
    <p>{{ __('Container') }} @include('description._ric', ['term' => 'openricx:Container'])</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    @php($barcode = $container->identifiers->first(fn ($i) => $i->identifierType->notation === 'barcode')?->value)
    <p><strong>{{ __('Persistent IRI') }}:</strong> <code>{{ $container->iri }}</code>
        <br><strong>{{ __('Container type') }}:</strong> {{ $container->containerType ? $container->containerType->anyLabel(['en']).' ('.$container->containerType->vocabulary->title.')' : 'not set' }} @include('description._ric', ['term' => 'openricx:hasContainerType'])
        @if($barcode)<br><strong>{{ __('Barcode') }}:</strong> {{ $barcode }} @include('description._ric', ['term' => 'rico:Identifier'])@endif
        <br><strong>{{ __('Kept in') }}:</strong> {{ $path ?? __('nowhere yet') }} @include('description._ric', ['term' => 'rico:hasOrHadLocation'])
        <br><a href="{{ route('containers.box-list', $container) }}">{{ __('Box list to print') }}</a></p>
    @if($canUpdate)
        @include('description._actions', ['subject' => $container, 'editRoute' => 'containers.edit', 'required' => 'container-name'])
        <h2>{{ __('Move') }}</h2>
        <form method="post" action="{{ route('containers.move', $container) }}" style="display: flex; gap: .75rem; flex-wrap: wrap; align-items: end">
            @csrf
            <div><label for="move-place" style="margin-top: 0">{{ __('To') }}</label>
                <select id="move-place" name="place" style="font: inherit; padding: .5rem">
                    <option value="">{{ __('Out of storage') }}</option>
                    @foreach($places as $p)<option value="{{ $p->id }}" @selected($place?->id === $p->id)>{{ $p->path }}</option>@endforeach
                </select></div>
            <div><label for="move-day" style="margin-top: 0">{{ __('On') }}</label><input id="move-day" name="day" type="date" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}"></div>
            <div><label for="move-note" style="margin-top: 0">{{ __('Why (optional)') }}</label><input id="move-note" name="note" maxlength="2000"></div>
            <button type="submit" style="margin-top: 0">{{ __('Move') }}</button>
        </form>
        @if($places->isEmpty())<p><small>{{ __('There are no storage places yet: make them under') }} <a href="{{ route('storage.places') }}">{{ __('Physical storage') }}</a>.</small></p>@endif
    @endif

    <h2>{{ __('Where it was kept') }} @include('description._ric', ['term' => 'rico:hasOrHadLocation'])</h2>
    @forelse($locations->reverse() as [$relation, $at])
        @php($from = $relation->datedAs('beginning')?->normalized_value)
        @php($to = $relation->datedAs('end')?->normalized_value)
        <p>{{ $at ? $storage->path($at) : __('a place since deleted') }}: {{ $to ? __('from :from to :to', ['from' => $from ?? '?', 'to' => $to]) : __('from :from until now', ['from' => $from ?? '?']) }}@if($relation->description). {{ $relation->description }}@endif</p>
    @empty
        <p>{{ __('Not kept anywhere yet.') }}</p>
    @endforelse

    <h2>{{ __('Facts') }}</h2>
    <dl>
        <dt>{{ __('Status') }}</dt><dd>{{ $container->status?->label(['en']) ?? __('not set') }} @include('description._ric', ['term' => null])</dd>
        <dt>{{ __('Security') }}</dt><dd>{{ $container->securityLevel?->label(['en']) ?? __('not set') }} @include('description._ric', ['term' => null])</dd>
        <dt>{{ __('Size (width, height, depth)') }}</dt><dd>{{ collect([$container->width_cm, $container->height_cm, $container->depth_cm])->map(fn ($v) => $v === null ? '?' : rtrim(rtrim((string) $v, '0'), '.'))->join(' x ') }} cm @include('description._ric', ['term' => null])</dd>
        <dt>{{ __('Capacity') }}</dt><dd>@if($container->capacity !== null){{ __(':used of :capacity :unit used', ['used' => rtrim(rtrim((string) $container->capacity_used, '0'), '.') ?: 0, 'capacity' => rtrim(rtrim((string) $container->capacity, '0'), '.'), 'unit' => mb_strtolower((string) $container->capacityUnit?->label(\App\Support\Vocab::languages()))]) }} @else {{ __('not set') }} @endif @include('description._ric', ['term' => null])</dd>
        <dt>{{ __('Condition') }}</dt><dd>{{ $container->condition?->label(\App\Support\Vocab::languages()) ?? __('not set') }} @include('description._ric', ['term' => null])</dd>
        <dt>{{ __('Weight and items') }}</dt><dd>{{ collect([$container->weight_kg !== null ? rtrim(rtrim((string) $container->weight_kg, '0'), '.').' kg' : null, $container->item_count !== null ? trans_choice(':count item|:count items', $container->item_count, ['count' => $container->item_count]) : null])->filter()->join(', ') ?: __('not set') }} @include('description._ric', ['term' => null])</dd>
        <dt>{{ __('Environment') }}</dt><dd>{{ __($container->climate_controlled === null ? 'climate control not recorded' : ($container->climate_controlled ? 'climate controlled' : 'not climate controlled')) }}@if($container->temperature_min !== null || $container->temperature_max !== null), {{ __(':from to :to', ['from' => $container->temperature_min ?? '?', 'to' => $container->temperature_max ?? '?']) }} °C @endif @if($container->humidity_min !== null || $container->humidity_max !== null), {{ __(':from to :to% relative humidity', ['from' => $container->humidity_min ?? '?', 'to' => $container->humidity_max ?? '?']) }} @endif @include('description._ric', ['term' => null])</dd>
    </dl>

    @include('description._view', ['subject' => $container, 'standard' => \App\Description\Elements::CONTAINER, 'drafts' => $canUpdate])
    @include('description._relations', ['subject' => $container, 'kind' => 'containers', 'relations' => $relations, 'canUpdate' => $canUpdate,
        'heading' => 'What it holds, and what holds it', 'types' => ['storage'], 'section' => 'st', 'add' => 'storage|target', 'addLabel' => 'Add a unit of description, an instantiation or a container it holds'])
    @include('description._dates', ['subject' => $container, 'kind' => 'containers', 'heading' => 'Dates', 'canUpdate' => $canUpdate, 'choices' => $dateChoices])
    @include('description._names', ['subject' => $container, 'kind' => 'containers', 'canUpdate' => $canUpdate])
    @include('description._identifiers', ['subject' => $container, 'kind' => 'containers', 'canUpdate' => $canUpdate])
@endsection
