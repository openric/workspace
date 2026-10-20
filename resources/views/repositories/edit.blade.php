{{-- OpenRiC Workspace - describe a repository. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'Describe repository')
@section('content')
    <p><a href="{{ route('repositories.show', $repository) }}">Back to the repository</a></p>
    <h1>Describe the repository</h1>
    <form method="post" action="{{ route('repositories.update', $repository) }}">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <label for="language">Language of these values (BCP 47)</label>
        <input id="language" name="language" required value="{{ old('language', $language) }}">
        <label for="identifier">Identifier (ISDIAH 5.1.1)</label>
        <input id="identifier" name="identifier" value="{{ old('identifier', $repository->identifier) }}">
        @foreach($elements as $element)
            @php($current = $repository->descriptionValues->first(fn ($v) => $v->element_id === $element->id && $v->language === $language))
            @php($en = $element->labels->firstWhere('language', 'en'))
            <label for="v-{{ $element->notation }}">{{ $element->label(['en', 'und']) }}</label>
            <small>{{ $en?->scope_note }}</small>
            <textarea id="v-{{ $element->notation }}" name="values[{{ $element->notation }}]" rows="{{ $element->notation === 'authorised-name' || $element->notation === 'other-name' ? 1 : 4 }}" style="width: 100%; font: inherit">{{ old('values.'.$element->notation, $current?->value) }}</textarea>
        @endforeach
        <h2>Control (ISDIAH 5.6)</h2>
        <label for="description_identifier">Description identifier (5.6.1)</label>
        <input id="description_identifier" name="description_identifier" value="{{ old('description_identifier', $repository->description_identifier) }}">
        <label for="description_status">Status (5.6.4)</label>
        <select id="description_status" name="description_status" style="font: inherit; padding: .5rem">
            <option value="">Not set</option>
            @foreach($statuses as $status)<option value="{{ $status->iri }}" @selected(old('description_status', $repository->descriptionStatus?->iri) === $status->iri)>{{ $status->label(['en', 'und']) }}</option>@endforeach
        </select>
        <label for="description_detail">Level of detail (5.6.5)</label>
        <select id="description_detail" name="description_detail" style="font: inherit; padding: .5rem">
            <option value="">Not set</option>
            @foreach($details as $detail)<option value="{{ $detail->iri }}" @selected(old('description_detail', $repository->descriptionDetail?->iri) === $detail->iri)>{{ $detail->label(['en', 'und']) }}</option>@endforeach
        </select>
        <button type="submit">Save</button>
    </form>
@endsection
