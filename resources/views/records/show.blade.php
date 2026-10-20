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
    <p><a href="{{ route('records.move-form', $record) }}">Move</a> · <a href="{{ route('records.create', ['parent' => $record->id]) }}">Add a unit inside</a></p>
    @include('description._actions', ['subject' => $record, 'editRoute' => 'records.edit', 'required' => 'title'])
    @include('description._view', ['subject' => $record, 'standard' => \App\Description\Elements::ISAD_G])

    <h2>Structured dates (3.1.3)</h2>
    @foreach($record->dates as $date)
        <form method="post" action="{{ route('records.dates.destroy', [$record, $date]) }}">
            @csrf
            {{ $date->dateType->label(['en', 'und']) }}: {{ $date->begin_date?->format('Y-m-d') ?? '?' }} to {{ $date->end_date?->format('Y-m-d') ?? '?' }}
            <button type="submit">Remove</button>
        </form>
    @endforeach
    <details>
        <summary>Add a date</summary>
        <form method="post" action="{{ route('records.dates.store', $record) }}">
            @csrf
            <label for="date_type">Type</label>
            <select id="date_type" name="date_type" style="font: inherit; padding: .5rem">
                @foreach($dateTypes as $type)<option value="{{ $type->iri }}">{{ $type->label(['en', 'und']) }}</option>@endforeach
            </select>
            <label for="begin_date">From (YYYY-MM-DD)</label>
            <input id="begin_date" name="begin_date" type="date">
            <label for="end_date">To (YYYY-MM-DD)</label>
            <input id="end_date" name="end_date" type="date">
            <button type="submit">Add date</button>
        </form>
    </details>

    <h2>Units inside</h2>
    @forelse($record->children as $child)
        <div>
            <a href="{{ route('records.show', $child) }}">{{ $child->described('title', ['en']) }}</a> ({{ $child->level->label(['en', 'und']) }})
            <form method="post" action="{{ route('records.reorder', $child) }}" style="display: inline">@csrf<input type="hidden" name="direction" value="up"><button type="submit" aria-label="Move {{ $child->described('title', ['en']) }} up">Up</button></form>
            <form method="post" action="{{ route('records.reorder', $child) }}" style="display: inline">@csrf<input type="hidden" name="direction" value="down"><button type="submit" aria-label="Move {{ $child->described('title', ['en']) }} down">Down</button></form>
        </div>
    @empty
        <p>None yet.</p>
    @endforelse
@endsection
