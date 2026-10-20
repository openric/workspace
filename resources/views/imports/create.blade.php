{{-- OpenRiC Workspace - upload a file of units of description for a dry run. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'Import archival descriptions')
@section('content')
    <p><a href="{{ route('records.index') }}">Archival descriptions</a> · <a href="{{ route('imports.index') }}">Earlier imports</a></p>
    <h1>Import archival descriptions{{ $parent ? ' into '.$parent->described('title', ['en']) : '' }}</h1>
    <p>Uploading makes a dry run: the import is made and rolled back, and its report says what each unit would become and every field that cannot be taken as given. Nothing is written until you read the report and import.</p>
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <form method="post" action="{{ route('imports.store') }}" enctype="multipart/form-data">
        @csrf
        @if($parent)<input type="hidden" name="parent" value="{{ $parent->id }}">@endif
        <label for="file">File (UTF-8, up to 20 MB)</label>
        <input id="file" type="file" name="file" required accept=".csv,.xml,text/csv,application/xml,text/xml">
        <fieldset><legend>Format</legend>
            @foreach($formats as $format => $name)
                <label><input type="radio" name="format" value="{{ $format }}" @checked(old('format', 'csv') === $format)> {{ $name }}</label>
            @endforeach
        </fieldset>
        <label for="source">Source</label>
        <input id="source" name="source" value="{{ old('source') }}" maxlength="255" aria-describedby="source-help">
        <small id="source-help">Where the file comes from, such as "AtoM, Riverside, 2026". Importing again from the same source updates the units it made instead of making them again. Left empty, the file name is used.</small>
        <label for="repository">Repository that holds the top-level units</label>
        <select id="repository" name="repository" required>
            @foreach($repositories as $repository)<option value="{{ $repository->id }}" @selected(old('repository', $parent?->repository_id) === $repository->id)>{{ $repository->described('authorised-name', ['en']) }}</option>@endforeach
        </select>
        <label for="language">Language of the file (BCP 47)</label>
        <input id="language" name="language" value="{{ old('language', 'en') }}" required maxlength="35" aria-describedby="language-help">
        <small id="language-help">A CSV row's culture column, and an EAD3 element's lang, take precedence.</small>
        <label for="agent_type">Creators and names with no authority record yet</label>
        <select id="agent_type" name="agent_type" aria-describedby="agent-help">
            <option value="">Leave them out and list them</option>
            @foreach($agentTypes as $type)<option value="{{ $type->notation }}" @selected(old('agent_type') === $type->notation)>Create them as a {{ mb_strtolower($type->label(['en'])) }}</option>@endforeach
        </select>
        <small id="agent-help">EAD says whether a name is a person, family or corporate body, and those are created as such; AtoM's CSV does not say.</small>
        <p><label><input type="checkbox" name="create_terms" value="1" @checked(old('create_terms', true))> Create subjects and places not yet in the vocabularies</label></p>
        <p><label><input type="checkbox" name="match_identifier" value="1" @checked(old('match_identifier'))> Also match units already here by their identifier under the same parent</label></p>
        <button type="submit">Upload and make a dry run</button>
    </form>
@endsection
