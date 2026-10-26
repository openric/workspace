{{-- OpenRiC Workspace - new vocabulary. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('New vocabulary'))
@section('content')
    <p><a href="{{ route('vocabularies.index') }}">{{ __('Taxonomies') }}</a></p>
    <h1>{{ __('New vocabulary') }}</h1>
    <form method="post" action="{{ route('vocabularies.store') }}">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <label for="kind">{{ __('What its concepts are') }}</label>
        <select id="kind" name="kind" required style="font: inherit; padding: .5rem">
            @foreach($kinds as $k)<option value="{{ $k->notation }}" @selected(old('kind', $kind) === $k->notation)>{{ $k->label(\App\Support\Vocab::languages()) }}</option>@endforeach
        </select>
        @include('vocabularies._fields', ['vocabulary' => null])
        <button type="submit">{{ __('Create vocabulary') }}</button>
    </form>
@endsection
