{{-- OpenRiC Workspace - a published authority record, repository, place or activity (WS-009). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', $view->label($entity))
@section('content')
    @php($nameElement = \App\Relations\EntityKinds::get($kind)['label'])
    @php([$name, $nameLanguage] = $view->valueWithLanguage($entity, $nameElement) ?? ['', 'en'])
    <h1 lang="{{ $nameLanguage }}">{{ $name }}</h1>
    @if(in_array($kind, ['agent', 'repository'], true))@include('clipboard._button', ['type' => $kind === 'agent' ? 'agents' : 'repositories', 'id' => $entity->getKey()])@endif
    <p class="meta">{{ ucfirst(\App\Relations\EntityKinds::noun($entity)) }} @include('description._ric', ['term' => \App\Relations\EntityKinds::ricClass($entity)])
        @if($entity instanceof \App\Models\Activity && $entity->activityType) · {{ $entity->activityType->anyLabel(['en']) }} @include('description._ric', ['term' => 'rico:hasActivityType'])@endif
        @if($entity instanceof \App\Models\Place && $entity->placeType) · {{ $entity->placeType->anyLabel(['en']) }}@endif
        @if($entity->getAttribute('identifier')) · {{ $entity->getAttribute('identifier') }}@endif</p>
    @php($others = $entity->names->filter(fn ($n) => $view->visible($n) && $n->nameType->notation !== 'authorised'))
    @if($others->isNotEmpty())<p>Also known as: @foreach($others as $other)<span lang="{{ $other->language }}">{{ $other->value }}</span>@if(! $loop->last); @endif @endforeach</p>@endif
    @foreach($entity->dates as $date)<p><strong>{{ $date->property->label(['en', 'und']) }}:</strong> {{ $date->display() }} @include('description._ric', ['term' => $date->property])</p>@endforeach
    @if($entity instanceof \App\Models\Place && $entity->latitude !== null)<p><strong>Coordinates:</strong> {{ $entity->latitude }}, {{ $entity->longitude }} (WGS 84)</p>@endif
    <p><a href="{{ route('discover.graph', [\App\Discovery\Links::CATALOGUE[$kind], $entity->getKey()]) }}">See it in the relationship graph</a></p>
    @isset($holdings)
        <h2>Holdings ({{ $holdings->count() }}) @include('description._ric', ['term' => 'rico:isOrWasHolderOf'])</h2>
        @if($holdings->isEmpty())<p>No published descriptions yet.</p>@else
        <ul>@foreach($holdings as $unit)<li><a href="{{ route('discover.record', $unit->id) }}">{{ $view->label($unit) }}</a> <span class="meta">{{ collect([$unit->level?->label(['en', 'und']), $unit->referenceCode()])->filter()->join(', ') }}</span></li>@endforeach</ul>
        @endif
    @endisset
    @include('discover._values', ['subject' => $entity, 'standard' => $standard, 'view' => $view])
    @include('discover._relations', ['relations' => $relations, 'view' => $view, 'heading' => 'Relations'])
    <p class="meta"><code>{{ $entity->getAttribute('iri') }}</code></p>
@endsection
