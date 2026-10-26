{{-- OpenRiC Workspace - new activity. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('New activity'))
@section('content')
    <p><a href="{{ route('activities.index') }}">{{ __('Back') }}</a></p>
    <h1>{{ __('New activity') }} @include('description._ric', ['term' => 'rico:Activity'])</h1>
    <form method="post" action="{{ route('activities.store') }}">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <label for="name">{{ __('Authorised form of name') }} (ISDF 5.1.2)</label> @include('description._ric', ['term' => 'rico:Name'])
        <input id="name" name="name" required maxlength="2000" value="{{ old('name') }}" aria-describedby="name-help">
        <small id="name-help">{{ __('Such as "Marriage of Johanna Engelbrecht and Pieter de Wet" or "Keeping of the council minutes".') }}</small>
        <label for="language">{{ __('Language of the name (BCP 47, e.g. en, af)') }}</label> @include('description._ric', ['term' => 'xml:lang'])
        <input id="language" name="language" required value="{{ old('language', 'en') }}">
        @include('activities._type-field', ['types' => $types, 'current' => null])
        <button type="submit">{{ __('Create activity') }}</button>
    </form>
@endsection
