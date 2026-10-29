{{-- OpenRiC Workspace - add a donor agreement (Heratio's agreement add). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('New agreement'))
@section('content')
    <p><a href="{{ route('agreements.index') }}">{{ __('Back') }}</a></p>
    <h1>{{ __('New agreement') }} @include('description._ric', ['term' => 'rico:Record'])</h1>
    <form method="post" action="{{ route('agreements.store') }}">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <label for="number">{{ __('Agreement number') }}</label> @include('description._ric', ['term' => 'rico:Identifier'])
        <input id="number" name="number" maxlength="255" value="{{ old('number') }}" aria-describedby="number-help">
        <small id="number-help">{{ __('Unique in the institution, such as DOG-2026-001. Leave it empty for the next number, with the type\'s prefix.') }}</small>
        <label for="type">{{ __('Agreement type') }}</label> @include('description._ric', ['term' => 'rico:hasDocumentaryFormType'])
        <select id="type" name="type" style="font: inherit; padding: .5rem">@foreach($types as $t)<option value="{{ $t->notation }}" @selected(old('type') === $t->notation)>{{ $t->label(\App\Support\Vocab::languages()) }}</option>@endforeach</select>
        <label for="title">{{ __('Title') }}</label> @include('description._ric', ['term' => 'rico:Title'])
        <input id="title" name="title" maxlength="2000" value="{{ old('title') }}">
        <label for="language">{{ __('Language of the title (BCP 47, e.g. en, af)') }}</label> @include('description._ric', ['term' => 'xml:lang'])
        <input id="language" name="language" required value="{{ old('language', 'en') }}">
        <button type="submit">{{ __('Create agreement') }}</button>
    </form>
@endsection
