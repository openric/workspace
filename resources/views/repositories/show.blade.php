{{-- OpenRiC Workspace - one repository. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $repository->described('authorised-name', [app()->getLocale(), 'en']))
@section('content')
    <p><a href="{{ route('repositories.index') }}">All repositories</a></p>
    <h1>{{ $repository->described('authorised-name', [app()->getLocale(), 'en']) }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p><strong>Persistent IRI:</strong> <code>{{ $repository->iri }}</code>@if($repository->identifier)<br><strong>Identifier:</strong> {{ $repository->identifier }}@endif</p>
    @php($languages = $repository->descriptionValues->pluck('language')->unique()->sort()->values())
    <p>Edit in:
        @foreach($languages as $lang)<a href="{{ route('repositories.edit', [$repository, 'language' => $lang]) }}">{{ $lang }}</a> @endforeach
        <a href="{{ route('repositories.edit', [$repository, 'language' => 'en']) }}">another language</a></p>
    @foreach($elements as $element)
        @php($values = $repository->descriptionValues->where('element_id', $element->id))
        @continue($values->isEmpty())
        <h2>{{ $element->label(['en', 'und']) }}</h2>
        @foreach($values->sortBy('language') as $value)
            <p lang="{{ $value->language }}">{{ $value->value }}</p>
            <p><small>{{ $value->language }}, {{ $value->is_original ? 'original' : 'translation' }}, {{ $value->state->label(['en', 'und']) }}</small></p>
        @endforeach
    @endforeach
@endsection
