{{-- OpenRiC Workspace - one unit of description. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $record->described('title', ['en']))
@section('content')
    <nav aria-label="Hierarchy"><a href="{{ route('records.index') }}">Archival descriptions</a>
        @foreach($record->ancestors() as $ancestor) / <a href="{{ route('records.show', $ancestor) }}">{{ $ancestor->described('title', ['en']) }}</a>@endforeach</nav>
    <h1>{{ $record->described('title', ['en']) }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p>{{ $record->level->label(['en', 'und']) }}@if($record->referenceCode()), {{ $record->referenceCode() }}@endif. Held by <a href="{{ route('repositories.show', $record->repository) }}">{{ $record->repository->described('authorised-name', ['en']) }}</a>.<br>
       <strong>Persistent IRI:</strong> <code>{{ $record->iri }}</code></p>
    <p><a href="{{ route('records.tree', $record) }}">Hierarchy</a> · <a href="{{ route('records.inventory', $record) }}">Inventory</a> · <a href="{{ route('records.finding-aid', $record) }}">Finding aid</a> · <a href="{{ route('records.print', $record) }}">Print view</a>
       @if($canUpdate) · <a href="{{ route('history', ['records', $record->id]) }}">History</a>
           · <form method="post" action="{{ route('records.calculate-dates', $record) }}" style="display: inline">@csrf<button type="submit" style="margin-top: 0; padding: .1rem .5rem">Calculate dates from the units inside</button></form>@endif</p>
    <p>@if($canUpdate)<a href="{{ route('records.move-form', $record) }}">Move</a>@endif
       @if($canUpdate && $canCreateInside) · @endif
       @if($canCreateInside)<a href="{{ route('records.create', ['parent' => $record->id]) }}">Add a unit inside</a>@endif</p>
    @if($canUpdate || auth()->user()->may('translate', 'description', $record))
        @include('description._actions', ['subject' => $record, 'editRoute' => 'records.edit', 'required' => 'title'])
    @endif
    @include('description._view', ['subject' => $record, 'standard' => \App\Description\Elements::ISAD_G, 'drafts' => $drafts])
    @include('records._creators', ['relations' => $relations, 'drafts' => $drafts])
    @include('records._provenance', ['record' => $record, 'relations' => $relations])
    <h2>Description control</h2>
    <p>Description identifier: {{ $record->description_identifier ?? 'none' }}. Status: {{ $record->descriptionStatus?->label(['en', 'und']) ?? 'not set' }}. Level of detail: {{ $record->descriptionDetail?->label(['en', 'und']) ?? 'not set' }}.</p>
    <h2>Cite as</h2>
    <p>{{ collect([$record->described('title', ['en']), $record->referenceCode(), $record->repository->described('authorised-name', ['en'])])->filter()->join(', ') }}. {{ $record->iri }} (accessed {{ now()->format('j F Y') }}).</p>

    @include('description._names', ['subject' => $record, 'kind' => 'records', 'canUpdate' => $canUpdate, 'drafts' => $drafts])
    @include('description._identifiers', ['subject' => $record, 'kind' => 'records', 'canUpdate' => $canUpdate])
    @include('description._relations', ['subject' => $record, 'kind' => 'records', 'relations' => $relations, 'canUpdate' => $canUpdate])
    @include('description._relations', ['subject' => $record, 'kind' => 'records', 'relations' => $relations, 'canUpdate' => $canUpdate,
        'heading' => 'Subject access points', 'types' => ['subject'], 'section' => 'su', 'add' => 'subject|source', 'addLabel' => 'Add a subject: a concept, a place or an agent'])
    @include('description._relations', ['subject' => $record, 'kind' => 'records', 'relations' => $relations, 'canUpdate' => $canUpdate,
        'heading' => 'Place access points', 'types' => ['place'], 'section' => 'pl', 'add' => 'place|target', 'addLabel' => 'Add a place'])
    @include('description._dates', ['subject' => $record, 'kind' => 'records', 'heading' => 'Dates (3.1.3)', 'canUpdate' => $canUpdate, 'choices' => $dateChoices])

    <h2>Units inside</h2>
    @forelse($record->children as $child)
        <div>
            <a href="{{ route('records.show', $child) }}">{{ $child->described('title', ['en']) }}</a> ({{ $child->level->label(['en', 'und']) }})
            @include('description._row-actions', ['entity' => $child])
            @if(auth()->user()->may('update', 'description', $child))
            <form method="post" action="{{ route('records.reorder', $child) }}" style="display: inline">@csrf<input type="hidden" name="direction" value="up"><button type="submit" aria-label="Move {{ $child->described('title', ['en']) }} up">Up</button></form>
            <form method="post" action="{{ route('records.reorder', $child) }}" style="display: inline">@csrf<input type="hidden" name="direction" value="down"><button type="submit" aria-label="Move {{ $child->described('title', ['en']) }} down">Down</button></form>
            @endif
        </div>
    @empty
        <p>None yet.</p>
    @endforelse
@endsection
