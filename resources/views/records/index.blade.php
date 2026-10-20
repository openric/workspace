{{-- OpenRiC Workspace - archival descriptions, top level. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'Archival descriptions')
@section('content')
    <h1>Archival descriptions</h1>
    <p><a href="{{ route('records.create') }}">New top-level unit (fonds or collection)</a></p>
    @forelse($groups as $units)
        <h2>{{ $units->first()->repository->described('authorised-name', ['en']) }}</h2>
        @foreach($units as $unit)
            <p><a href="{{ route('records.show', $unit) }}">{{ $unit->described('title', ['en']) }}</a>
               ({{ $unit->level->label(['en', 'und']) }}@if($unit->identifier), {{ $unit->identifier }}@endif)</p>
        @endforeach
    @empty
        <p>No archival descriptions yet.</p>
    @endforelse
@endsection
