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
        <label for="language">Language of these values (BCP 47)</label>
        <input id="language" name="language" required value="{{ old('language', $language) }}">
        <label for="identifier">Reference code (3.1.1)</label>
        <input id="identifier" name="identifier" value="{{ old('identifier', $record->identifier) }}">
        <label for="level">Level of description (3.1.4)</label>
        <select id="level" name="level" style="font: inherit; padding: .5rem">
            @foreach($levels as $level)<option value="{{ $level->iri }}" @selected(old('level', $record->level->iri) === $level->iri)>{{ $level->label(['en', 'und']) }}</option>@endforeach
        </select>
        @include('description._fields', ['subject' => $record, 'standard' => \App\Description\Elements::ISAD_G, 'language' => $language])
        <button type="submit">Save</button>
    </form>
@endsection
