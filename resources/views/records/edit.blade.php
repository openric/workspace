{{-- OpenRiC Workspace - describe a unit. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'Describe')
@section('content')
    <p><a href="{{ route('records.show', $record) }}">Back</a></p>
    <h1>Describe the unit</h1>
    <form method="post" action="{{ route('records.update', $record) }}">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <label for="language">Language of these values (BCP 47)</label>
        <input id="language" name="language" required value="{{ old('language', $language) }}">
        <label for="identifier">Reference code (3.1.1)</label>
        <input id="identifier" name="identifier" value="{{ old('identifier', $record->identifier) }}">
        <label for="level">Level of description (3.1.4)</label>
        <select id="level" name="level" style="font: inherit; padding: .5rem">
            @foreach($levels as $level)<option value="{{ $level->iri }}" @selected(old('level', $record->level->iri) === $level->iri)>{{ $level->label(['en', 'und']) }}</option>@endforeach
        </select>
        @foreach($elements as $element)
            @php($current = $record->descriptionValues->first(fn ($v) => $v->element_id === $element->id && $v->language === $language))
            <label for="v-{{ $element->notation }}">{{ $element->label(['en', 'und']) }}</label>
            <small>{{ $element->labels->firstWhere('language', 'en')?->scope_note }}</small>
            <textarea id="v-{{ $element->notation }}" name="values[{{ $element->notation }}]" rows="{{ in_array($element->notation, ['title', 'dates-expressed', 'extent'], true) ? 1 : 4 }}" style="width: 100%; font: inherit">{{ old('values.'.$element->notation, $current?->value) }}</textarea>
        @endforeach
        <button type="submit">Save</button>
    </form>
@endsection
