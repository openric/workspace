{{-- OpenRiC Workspace - move a unit. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Move'))
@section('content')
    <p><a href="{{ route('records.show', $record) }}">{{ __('Back') }}</a></p>
    <h1>Move "{{ $record->described('title', ['en']) }}"</h1>
    <p>{{ __('Its persistent IRI does not change. A unit cannot move under itself or anything inside it, so those are not offered.') }}</p>
    <form method="post" action="{{ route('records.move', $record) }}">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <label for="parent">{{ __('New place') }}</label>
        <select id="parent" name="parent" style="font: inherit; padding: .5rem">
            <option value="">{{ __('Top level') }}</option>
            @foreach($candidates as $candidate)<option value="{{ $candidate->id }}" @selected($record->parent_id === $candidate->id)>{{ $candidate->described('title', ['en']) }}</option>@endforeach
        </select>
        <button type="submit">{{ __('Move') }}</button>
    </form>
@endsection
