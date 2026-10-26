{{-- OpenRiC Workspace - describe a concept in one language. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Edit concept'))
@section('content')
    <p><a href="{{ route('concepts.show', $concept) }}">{{ __('Back to the concept') }}</a></p>
    <h1>Edit {{ $concept->anyLabel([$language, 'en']) }}</h1>
    <p>{{ __('Labels and notes in') }} <strong>{{ $language }}</strong>.
        @php($languages = $concept->labels->pluck('language')->sort())
        @if($languages->count() > 1)Also in: @foreach($languages->reject(fn ($l) => $l === $language) as $other)<a href="{{ route('concepts.edit', [$concept, 'language' => $other]) }}">{{ $other }}</a>@if(! $loop->last), @endif @endforeach. @endif</p>
    <form method="get" action="{{ route('concepts.edit', $concept) }}" style="display: flex; gap: .5rem; align-items: end">
        <div><label for="other-language" style="margin-top: 0">{{ __('Add a language (BCP 47)') }}</label><input id="other-language" name="language" required pattern="[a-z]{2,3}(-[A-Za-z0-9]{2,8})*" style="max-width: 8rem"></div>
        <button type="submit" style="margin-top: 0">{{ __('Translate') }}</button>
    </form>
    <form method="post" action="{{ route('concepts.update', $concept) }}">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        @include('concepts._fields', ['concept' => $concept, 'language' => $language, 'broader' => null])
        <button type="submit">{{ __('Save') }}</button>
    </form>
@endsection
