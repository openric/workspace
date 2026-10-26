{{-- OpenRiC Workspace - new unit of description. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('New unit of description'))
@section('content')
    <h1>{{ __('New unit of description') }}</h1>
    @if($parent)<p>{{ __('Inside') }} <a href="{{ route('records.show', $parent) }}">{{ $parent->described('title', ['en']) }}</a>.</p>@endif
    <form method="post" action="{{ route('records.store') }}">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        @if($parent)<input type="hidden" name="parent" value="{{ $parent->id }}">@endif
        <label for="title">{{ __('Title') }} (ISAD(G) 3.1.2)</label> @include('description._ric', ['term' => 'rico:Title'])
        <input id="title" name="title" required value="{{ old('title') }}">
        <label for="language">{{ __('Language of the title (BCP 47, e.g. en, af)') }}</label> @include('description._ric', ['term' => 'xml:lang'])
        <input id="language" name="language" required value="{{ old('language', 'en') }}">
        <label for="level">{{ __('Level of description') }} (3.1.4)</label> <small>{{ __('(each choice names its RiC-O class)') }}</small>
        <select id="level" name="level" required style="font: inherit; padding: .5rem">
            @foreach($levels as $level)<option value="{{ $level->iri }}" @selected(old('level') === $level->iri)>{{ $level->label(\App\Support\Vocab::languages()) }} ({{ $level->ricTerm() ?? 'rico:RecordResource' }})</option>@endforeach
        </select>
        <label for="identifier">{{ __('Reference code') }} (3.1.1, {{ __('optional') }})</label> @include('description._ric', ['term' => 'rico:Identifier'])
        <input id="identifier" name="identifier" value="{{ old('identifier') }}">
        @unless($parent)
            <label for="repository">{{ __('Held by') }}</label> @include('description._ric', ['term' => 'rico:hasOrHadHolder'])
            <select id="repository" name="repository" required style="font: inherit; padding: .5rem">
                @foreach($repositories as $repository)<option value="{{ $repository->id }}">{{ $repository->described('authorised-name', ['en']) }}</option>@endforeach
            </select>
            @if($repositories->isEmpty())<p class="error">{{ __('Create a repository first.') }}</p>@endif
        @endunless
        <button type="submit">{{ __('Create') }}</button>
    </form>
@endsection
