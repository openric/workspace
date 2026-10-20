{{-- OpenRiC Workspace - the institution's own vocabularies, by kind. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'Vocabularies')
@section('content')
    <h1>Vocabularies</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p>The institution's own vocabularies, for access points and types. Every concept has a persistent IRI and is deprecated, never deleted.</p>
    @php($canCreate = auth()->user()->may('create', 'vocabulary'))
    @foreach($kinds as $kind)
        <section>
            <h2>{{ $kind->label(['en', 'und']) }}</h2>
            <p><small>{{ $kind->labels->firstWhere('language', 'en')?->definition }}</small></p>
            @forelse($vocabularies[$kind->id] ?? [] as $vocabulary)
                <p><a href="{{ route('vocabularies.show', $vocabulary) }}">{{ $vocabulary->title }}</a> ({{ $vocabulary->current_count }} {{ $vocabulary->current_count === 1 ? 'concept' : 'concepts' }}@if($vocabulary->concepts_count > $vocabulary->current_count), {{ $vocabulary->concepts_count - $vocabulary->current_count }} deprecated @endif)</p>
            @empty
                <p>None yet.</p>
            @endforelse
            @if($canCreate)<p><a href="{{ route('vocabularies.create', ['kind' => $kind->notation]) }}">New vocabulary of {{ mb_strtolower($kind->label(['en', 'und'])) }}</a></p>@endif
        </section>
    @endforeach
@endsection
