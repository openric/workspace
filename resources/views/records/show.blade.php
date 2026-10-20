{{-- OpenRiC Workspace - one unit of description. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $record->described('title', ['en']))
@section('content')
    <nav aria-label="Hierarchy"><a href="{{ route('records.index') }}">Archival descriptions</a>
        @foreach($record->ancestors() as $ancestor) / <a href="{{ route('records.show', $ancestor) }}">{{ $ancestor->described('title', ['en']) }}</a>@endforeach</nav>
    <h1>{{ $record->described('title', ['en']) }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p>{{ $record->level->label(['en', 'und']) }}@if($record->identifier), {{ $record->identifier }}@endif. Held by {{ $record->repository->described('authorised-name', ['en']) }}.<br>
       <strong>Persistent IRI:</strong> <code>{{ $record->iri }}</code></p>
    <p>@if($canUpdate)<a href="{{ route('records.move-form', $record) }}">Move</a>@endif
       @if($canUpdate && $canCreateInside) · @endif
       @if($canCreateInside)<a href="{{ route('records.create', ['parent' => $record->id]) }}">Add a unit inside</a>@endif</p>
    @if($canUpdate || auth()->user()->may('translate', 'description', $record))
        @include('description._actions', ['subject' => $record, 'editRoute' => 'records.edit', 'required' => 'title'])
    @endif
    @include('description._view', ['subject' => $record, 'standard' => \App\Description\Elements::ISAD_G, 'drafts' => $drafts])

    @include('description._names', ['subject' => $record, 'kind' => 'records', 'canUpdate' => $canUpdate, 'drafts' => $drafts])
    @include('description._identifiers', ['subject' => $record, 'kind' => 'records', 'canUpdate' => $canUpdate])
    @include('description._relations', ['subject' => $record, 'kind' => 'records', 'relations' => $relations, 'canUpdate' => $canUpdate])
    @include('description._dates', ['subject' => $record, 'kind' => 'records', 'heading' => 'Dates (3.1.3)', 'canUpdate' => $canUpdate, 'choices' => $dateChoices])

    <h2>Units inside</h2>
    @forelse($record->children as $child)
        <div>
            <a href="{{ route('records.show', $child) }}">{{ $child->described('title', ['en']) }}</a> ({{ $child->level->label(['en', 'und']) }})
            @if(auth()->user()->may('update', 'description', $child))
            <form method="post" action="{{ route('records.reorder', $child) }}" style="display: inline">@csrf<input type="hidden" name="direction" value="up"><button type="submit" aria-label="Move {{ $child->described('title', ['en']) }} up">Up</button></form>
            <form method="post" action="{{ route('records.reorder', $child) }}" style="display: inline">@csrf<input type="hidden" name="direction" value="down"><button type="submit" aria-label="Move {{ $child->described('title', ['en']) }} down">Down</button></form>
            @endif
        </div>
    @empty
        <p>None yet.</p>
    @endforelse
@endsection
