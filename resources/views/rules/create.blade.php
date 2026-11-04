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
        @include('rules._status-field', ['current' => old('status', 'in-force')])
        <label for="identifier">{{ __('Its number, such as an act\'s number and year') }}</label> @include('description._ric', ['term' => 'rico:identifier'])
        <input id="identifier" name="identifier" maxlength="255" value="{{ old('identifier') }}" placeholder="43 of 1996">
        <label for="commencement">{{ __('In force from') }}</label> @include('description._ric', ['term' => 'rico:beginningDate'])
        <input id="commencement" name="commencement" maxlength="64" value="{{ old('commencement') }}" aria-describedby="date-help" placeholder="1997-01-01">
        <label for="repeal">{{ __('Repealed or lapsed on') }}</label> @include('description._ric', ['term' => 'rico:endDate'])
        <input id="repeal" name="repeal" maxlength="64" value="{{ old('repeal') }}" aria-describedby="date-help">
        <small id="date-help">{{ __('A year, a year and month, or a day (EDTF: 1996, 1996-10, 1996-10-02).') }}</small>
        <label for="history">{{ __('History') }}</label> @include('description._ric', ['term' => 'rico:history'])
        <textarea id="history" name="history" rows="3" aria-describedby="history-help">{{ old('history') }}</textarea>
        <small id="history-help">{{ __('How it came to be made, and how it was amended, superseded or repealed.') }}</small>
        <p class="meta">{{ __('Once it is made, add on its page who issued and who enforces it, the record that carries its text, whom it gives authority to, what it regulates, and the rules it amends or supersedes.') }}</p>
        <button type="submit">{{ __('Create rule') }}</button>
    </form>
@endsection
