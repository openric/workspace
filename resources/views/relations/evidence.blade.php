{{-- OpenRiC Workspace - find a unit of description that proves a relation. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Add evidence'))
@section('content')
    @php($name = \App\Relations\EntityKinds::label($subject))
    <p><a href="{{ \App\Relations\EntityKinds::route($subject) }}">{{ $name }}</a></p>
    <h1>{{ __('Add evidence of a relation') }}</h1>
    <p>{{ __('A unit of description that proves the relation, or describes it (rico:evidences).') }}</p>
    <form method="get" action="{{ route('relations.evidence', [$kind, $subject->getKey(), $relation]) }}">
        <label for="q">{{ __('Find a unit of description by title or reference code') }}</label>
        <input id="q" name="q" value="{{ $query }}" maxlength="200">
        <button type="submit">{{ __('Find') }}</button>
    </form>
    @if($query !== '')
        <h2>{{ __('Results') }}</h2>
        @forelse($candidates as $record)
            <form method="post" action="{{ route('relations.evidence.store', [$kind, $subject->getKey(), $relation]) }}">
                @csrf
                <input type="hidden" name="record" value="{{ $record->getKey() }}">
                {{ $record->described('title', ['en']) }}@if($record->identifier) ({{ $record->identifier }})@endif
                <button type="submit">{{ __('Add as evidence') }}</button>
            </form>
        @empty
            <p>{{ __('No results found.') }}</p>
        @endforelse
    @endif
@endsection
