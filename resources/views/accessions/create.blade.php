{{-- OpenRiC Workspace - register an accession. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Add new accession record'))
@section('content')
    <p><a href="{{ route('accessions.index') }}">{{ __('Back') }}</a></p>
    <h1>{{ __('Add new accession record') }} @include('description._ric', ['term' => 'rico:Activity'])</h1>
    <form method="post" action="{{ route('accessions.store') }}">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <label for="number">{{ __('Accession number') }} (CAAIS 1.2)</label> @include('description._ric', ['term' => 'rico:Identifier'])
        <input id="number" name="number" required maxlength="255" value="{{ old('number') }}" aria-describedby="number-help">
        <small id="number-help">{{ __('Required, and unique in the institution, such as 2026-014.') }}</small>
        <label for="title">{{ __('Title') }} (CAAIS 1.3)</label> @include('description._ric', ['term' => 'rico:Name'])
        <input id="title" name="title" maxlength="2000" value="{{ old('title') }}">
        <label for="language">{{ __('Language of the title (BCP 47, e.g. en, af)') }}</label> @include('description._ric', ['term' => 'xml:lang'])
        <input id="language" name="language" required value="{{ old('language', 'en') }}">
        <button type="submit">{{ __('Create accession') }}</button>
    </form>
@endsection
