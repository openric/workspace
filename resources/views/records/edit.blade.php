{{-- OpenRiC Workspace - describe a unit. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'Describe')
@section('content')
    <p><a href="{{ route('records.show', $record) }}">Back</a></p>
    <h1>Describe {{ $record->described('title', [$language, 'en']) }}</h1>
    <p>Values in <strong>{{ $language }}</strong>. Fill in what you know; empty fields are left out. A value you change goes back to draft.</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <form method="post" action="{{ route('records.update', $record) }}">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <label for="language">Language of these values (BCP 47)</label> @include('description._ric', ['term' => 'xml:lang'])
        <input id="language" name="language" required value="{{ old('language', $language) }}">
        <label for="identifier">Reference code (3.1.1)</label> @include('description._ric', ['term' => 'rico:Identifier'])
        <input id="identifier" name="identifier" value="{{ old('identifier', $record->identifier) }}">
        <label for="level">Level of description (3.1.4)</label> <small>(each choice names its RiC-O class)</small>
        <select id="level" name="level" style="font: inherit; padding: .5rem">
            @foreach($levels as $level)<option value="{{ $level->iri }}" @selected(old('level', $record->level->iri) === $level->iri)>{{ $level->label(\App\Support\Vocab::languages()) }} ({{ $level->ricTerm() ?? 'rico:RecordResource' }})</option>@endforeach
        </select>
        @include('description._fields', ['subject' => $record, 'standard' => \App\Description\Elements::ISAD_G, 'language' => $language])
        @if($mayUpdate)
        <fieldset style="margin-top: 1.5rem; border: 1px solid var(--line); padding: 0 1rem 1rem">
            <legend><strong>Description control</strong></legend>
            <label for="description_identifier">Description identifier</label> @include('description._ric', ['term' => null])
            <input id="description_identifier" name="description_identifier" maxlength="255" value="{{ old('description_identifier', $record->description_identifier) }}">
            <label for="description_status">Status</label> @include('description._ric', ['term' => null])
            <select id="description_status" name="description_status" style="font: inherit; padding: .5rem">
                <option value="">Not set</option>
                @foreach($statuses as $status)<option value="{{ $status->iri }}" @selected(old('description_status', $record->descriptionStatus?->iri) === $status->iri)>{{ $status->label(\App\Support\Vocab::languages()) }}</option>@endforeach
            </select>
            <label for="description_detail">Level of detail</label> @include('description._ric', ['term' => null])
            <select id="description_detail" name="description_detail" style="font: inherit; padding: .5rem">
                <option value="">Not set</option>
                @foreach($details as $detail)<option value="{{ $detail->iri }}" @selected(old('description_detail', $record->descriptionDetail?->iri) === $detail->iri)>{{ $detail->label(\App\Support\Vocab::languages()) }}</option>@endforeach
            </select>
        </fieldset>
        @endif
        <button type="submit">Save</button>
    </form>
@endsection
