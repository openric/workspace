{{-- OpenRiC Workspace - describe a place. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Describe place'))
@section('content')
    <p><a href="{{ route('places.show', $place) }}">{{ __('Back to the place') }}</a></p>
    <h1>{{ __('Describe :name', ['name' => $place->described('place-name', [$language, 'en'])]) }}</h1>
    <p>{{ __('Values in') }} <strong>{{ $language }}</strong>{{ __('. Fill in what you know; empty fields are left out. A value you change goes back to draft.') }}</p>
    <form method="post" action="{{ route('places.update', $place) }}">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <label for="language">{{ __('Language of these values (BCP 47)') }}</label> @include('description._ric', ['term' => 'xml:lang'])
        <input id="language" name="language" required value="{{ old('language', $language) }}">
        @include('description._fields', ['subject' => $place, 'standard' => \App\Description\Elements::PLACE, 'language' => $language])
        <fieldset style="margin-top: 1.5rem; border: 1px solid var(--line); padding: 0 1rem 1rem">
            <legend><strong>{{ __('Type and location') }}</strong></legend>
            <label for="place_type">{{ __('Place type') }}</label> @include('description._ric', ['term' => 'rico:hasOrHadPlaceType'])
            <select id="place_type" name="place_type" style="font: inherit; padding: .5rem">
                <option value="">{{ __('Not set') }}</option>
                @foreach($types as $type)<option value="{{ $type->id }}" @selected(old('place_type', $place->place_type_id) === $type->id)>{{ $type->anyLabel(['en']) }} ({{ $type->vocabulary->title }})</option>@endforeach
            </select>
            @if($types->isEmpty())<small>{{ __('The institution has no place types yet; add them under') }} <a href="{{ route('vocabularies.index') }}">{{ __('Taxonomies') }}</a>.</small>@endif
            <label for="latitude">{{ __('Latitude (decimal degrees, WGS 84, -90 to 90)') }}</label> @include('description._ric', ['term' => 'rico:latitude'])
            <input id="latitude" name="latitude" inputmode="decimal" value="{{ old('latitude', $place->latitude) }}">
            <label for="longitude">{{ __('Longitude (decimal degrees, -180 to 180)') }}</label> @include('description._ric', ['term' => 'rico:longitude'])
            <input id="longitude" name="longitude" inputmode="decimal" value="{{ old('longitude', $place->longitude) }}">
            <label for="altitude">{{ __('Altitude (metres above sea level, optional)') }}</label> @include('description._ric', ['term' => 'rico:altitude'])
            <input id="altitude" name="altitude" inputmode="decimal" value="{{ old('altitude', $place->altitude) }}">
        </fieldset>
        <button type="submit">{{ __('Save') }}</button>
    </form>
@endsection
