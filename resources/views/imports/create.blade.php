{{-- OpenRiC Workspace - upload a file of units of description, authority records or repositories for a dry run. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Import'))
@section('content')
    <p><a href="{{ route('records.index') }}">{{ __('Archival descriptions') }}</a> · <a href="{{ route('imports.index') }}">{{ __('Earlier imports') }}</a></p>
    <h1>{{ $parent ? 'Import archival descriptions into '.$parent->described('title', ['en']) : 'Import' }}</h1>
    <p>{{ __('Uploading makes a dry run: the import is made and rolled back, and its report says what each entry would become and every field that cannot be taken as given. Nothing is written until you read the report and import.') }}</p>
    <p>{{ __('Moving from AtoM: import the repositories first, then the authority records, then the archival descriptions, each with the same source, so the descriptions find their holders and creators.') }}</p>
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <form method="post" action="{{ route('imports.store') }}" enctype="multipart/form-data">
        @csrf
        @if($parent)<input type="hidden" name="parent" value="{{ $parent->id }}">@endif
        <label for="file">{{ __('File (UTF-8, up to 20 MB)') }}</label>
        <input id="file" type="file" name="file" required accept=".csv,.xml,text/csv,application/xml,text/xml">
        <fieldset><legend>{{ __('Format') }}</legend>
            @foreach($formats as $format => $name)
                @continue($parent && \App\Exchange\Import\ImportService::permission($format) !== 'description')
                <label><input type="radio" name="format" value="{{ $format }}" @checked(old('format', array_key_first($formats)) === $format)> {{ $name }}</label>
            @endforeach
        </fieldset>
        <label for="source">{{ __('Source') }}</label>
        <input id="source" name="source" value="{{ old('source') }}" maxlength="255" aria-describedby="source-help">
        <small id="source-help">{{ __('Where the file comes from, such as "AtoM, Riverside, 2026". Importing again from the same source updates what it made instead of making it again. Left empty, the file name is used.') }}</small>
        <label for="repository">{{ __('Repository that holds the top-level units (archival descriptions only)') }}</label>
        <select id="repository" name="repository" aria-describedby="repository-help">
            <option value="">{{ __('None') }}</option>
            @foreach($repositories as $repository)<option value="{{ $repository->id }}" @selected(old('repository', $parent?->repository_id) === $repository->id)>{{ $repository->described('authorised-name', ['en']) }}</option>@endforeach
        </select>
        <small id="repository-help">{{ __('A top-level unit whose repository column names one repository here is held by that one; any other by this one.') }}</small>
        <label for="default_level">{{ __('Units with no level of description (archival descriptions only)') }}</label>
        <select id="default_level" name="default_level" aria-describedby="level-help">
            <option value="">{{ __('Do not import them, and list them') }}</option>
            @foreach($levels as $level)<option value="{{ $level->notation }}" @selected(old('default_level') === $level->notation)>Import them as {{ mb_strtolower($level->label(['en'])) }}</option>@endforeach
        </select>
        <small id="level-help">{{ __('AtoM lets a unit have no level; the Workspace needs one, because the level decides whether a unit is a record set, a record or a record part.') }}</small>
        <label for="language">{{ __('Language of the file (BCP 47)') }}</label>
        <input id="language" name="language" value="{{ old('language', 'en') }}" required maxlength="35" aria-describedby="language-help">
        <small id="language-help">{{ __('A CSV row\'s culture column, and an EAD3 element\'s lang, take precedence.') }}</small>
        <label for="agent_type">{{ __('Agents whose kind the file does not give') }}</label>
        <select id="agent_type" name="agent_type" aria-describedby="agent-help">
            <option value="">{{ __('Leave them out and list them') }}</option>
            @foreach($agentTypes as $type)<option value="{{ $type->notation }}" @selected(old('agent_type') === $type->notation)>Create them as a {{ mb_strtolower($type->label(['en'])) }}</option>@endforeach
        </select>
        <small id="agent-help">{{ __('For creators and names with no authority record yet, and for authority records with no type of entity. EAD says whether a name is a person, family or corporate body; AtoM\'s description CSV does not, and AtoM lets an authority record have no type.') }}</small>
        <p><label><input type="checkbox" name="create_terms" value="1" @checked(old('create_terms', true))> {{ __('Create subjects, places, occupations and documentary form types not yet in the vocabularies') }}</label></p>
        <p><label><input type="checkbox" name="match_identifier" value="1" @checked(old('match_identifier'))> {{ __('Also match what is already here: units by their identifier under the same parent, authority records and repositories by their one authorised name') }}</label></p>
        <button type="submit">{{ __('Upload and make a dry run') }}</button>
    </form>
@endsection
