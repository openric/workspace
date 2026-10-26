{{-- OpenRiC Workspace - describe an instantiation. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Describe instantiation'))
@section('content')
    <p><a href="{{ route('instantiations.show', $instantiation) }}">{{ __('Back to the instantiation') }}</a></p>
    <h1>Describe {{ $instantiation->described('instantiation-title', [$language, 'en']) }}</h1>
    <p>{{ __('Values in') }} <strong>{{ $language }}</strong>{{ __('. Fill in what you know; empty fields are left out. A value you change goes back to draft. The files themselves never change: another version is another instantiation.') }}</p>
    <form method="post" action="{{ route('instantiations.update', $instantiation) }}">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <label for="language">{{ __('Language of these values (BCP 47)') }}</label> @include('description._ric', ['term' => 'xml:lang'])
        <input id="language" name="language" required value="{{ old('language', $language) }}">
        <label for="carrier_type">{{ __('Carrier type') }}</label> <small>{{ __('(each choice is a rico:CarrierType)') }}</small>
        <select id="carrier_type" name="carrier_type">
            <option value="">{{ __('Not set') }}</option>
            @foreach($carrierTypes as $type)<option value="{{ $type->notation }}" @selected(old('carrier_type', $instantiation->carrierType?->notation) === $type->notation)>{{ $type->label(['en']) }}</option>@endforeach
        </select>
        <label for="availability">{{ __('Availability') }}</label> @include('description._ric', ['term' => 'rico:conditionsOfAccess'])
        <select id="availability" name="availability">
            @foreach($availabilities as $state)<option value="{{ $state->notation }}" @selected(old('availability', $instantiation->availability->notation) === $state->notation)>{{ $state->label(['en']) }}: {{ $state->labels->firstWhere('language', 'en')?->definition }}</option>@endforeach
        </select>
        @include('description._fields', ['subject' => $instantiation, 'standard' => \App\Description\Elements::INSTANTIATION, 'language' => $language])
        <button type="submit">{{ __('Save') }}</button>
    </form>
@endsection
