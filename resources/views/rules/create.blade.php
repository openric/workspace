{{-- OpenRiC Workspace - new rule or mandate (roadmap phase 23b). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('New rule or mandate'))
@section('content')
    <p><a href="{{ route('rules.index') }}">{{ __('Back') }}</a></p>
    <h1>{{ __('New rule or mandate') }} @include('description._ric', ['term' => 'rico:Rule'])</h1>
    <form method="post" action="{{ route('rules.store') }}">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <label for="name">{{ __('Authorised form of name') }}</label> @include('description._ric', ['term' => 'rico:Name'])
        <input id="name" name="name" required maxlength="2000" value="{{ old('name') }}" aria-describedby="name-help">
        <small id="name-help">{{ __('Such as "Local Government Ordinance 17 of 1939" or "Mandate of the Town Clerk".') }}</small>
        <label for="language">{{ __('Language of the name (BCP 47, e.g. en, af)') }}</label> @include('description._ric', ['term' => 'xml:lang'])
        <input id="language" name="language" required value="{{ old('language', 'en') }}">
        @include('rules._type-field', ['types' => $types, 'current' => null])
        <button type="submit">{{ __('Create rule') }}</button>
    </form>
@endsection
