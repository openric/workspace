{{-- OpenRiC Workspace - describe an activity. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Describe activity'))
@section('content')
    <p><a href="{{ route('activities.show', $activity) }}">{{ __('Back to the activity') }}</a></p>
    <h1>{{ __('Describe :name', ['name' => $activity->described('activity-name', [$language, 'en'])]) }}</h1>
    <p>{{ __('Values in') }} <strong>{{ $language }}</strong>{{ __('. Fill in what you know; empty fields are left out. A value you change goes back to draft.') }}</p>
    <form method="post" action="{{ route('activities.update', $activity) }}">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <label for="language">{{ __('Language of these values (BCP 47)') }}</label> @include('description._ric', ['term' => 'xml:lang'])
        <input id="language" name="language" required value="{{ old('language', $language) }}">
        @include('activities._type-field', ['types' => $types, 'current' => $activity->activity_type_id])
        @include('description._fields', ['subject' => $activity, 'standard' => \App\Description\Elements::ACTIVITY, 'language' => $language])
        <h2>{{ __('Control') }} (ISDF 5.4)</h2>
        <label for="description_identifier">{{ __('Description identifier') }} (5.4.1)</label> @include('description._ric', ['term' => null])
        <input id="description_identifier" name="description_identifier" value="{{ old('description_identifier', $activity->description_identifier) }}">
        <label for="description_status">{{ __('Status') }} (5.4.4)</label> @include('description._ric', ['term' => null])
        <select id="description_status" name="description_status" style="font: inherit; padding: .5rem">
            <option value="">{{ __('Not set') }}</option>
            @foreach($statuses as $status)<option value="{{ $status->iri }}" @selected(old('description_status', $activity->descriptionStatus?->iri) === $status->iri)>{{ $status->label(\App\Support\Vocab::languages()) }}</option>@endforeach
        </select>
        <label for="description_detail">{{ __('Level of detail') }} (5.4.5)</label> @include('description._ric', ['term' => null])
        <select id="description_detail" name="description_detail" style="font: inherit; padding: .5rem">
            <option value="">{{ __('Not set') }}</option>
            @foreach($details as $detail)<option value="{{ $detail->iri }}" @selected(old('description_detail', $activity->descriptionDetail?->iri) === $detail->iri)>{{ $detail->label(\App\Support\Vocab::languages()) }}</option>@endforeach
        </select>
        <button type="submit">{{ __('Save') }}</button>
    </form>
@endsection
