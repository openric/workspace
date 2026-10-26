{{-- OpenRiC Workspace - put a place in another. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Move place'))
@section('content')
    @php($name = $place->described('place-name', ['en']))
    <p><a href="{{ route('places.show', $place) }}">{{ __('Back') }}</a></p>
    <h1>Where does {{ $name }} lie?</h1>
    <p>Now: {{ $place->parent ? 'in '.$place->parent->described('place-name', ['en']) : 'at the top' }}. Its persistent IRI does not change. A place it contains cannot be chosen. A former containment is a relation, with its dates.</p>
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <form method="get" action="{{ route('places.move-form', $place) }}">
        <label for="q">{{ __('Find the place it lies in, by name or identifier') }}</label>
        <input id="q" name="q" value="{{ $query }}" maxlength="200">
        <button type="submit">{{ __('Find') }}</button>
    </form>
    <form method="post" action="{{ route('places.move', $place) }}">
        @csrf
        <fieldset style="border: 0; padding: 0"><legend>{{ $name }} lies in</legend>
            @foreach($candidates as $candidate)
                <label><input type="radio" name="parent" value="{{ $candidate->id }}" required @checked($loop->first)> {{ $candidate->described('place-name', ['en']) }}{{ $candidate->parent ? ', in '.$candidate->parent->described('place-name', ['en']) : '' }}</label>
            @endforeach
            <label><input type="radio" name="parent" value="top" required @checked($candidates->isEmpty())> {{ __('No other place: put it at the top') }}</label>
        </fieldset>
        <button type="submit">{{ __('Move') }}</button>
    </form>
@endsection
