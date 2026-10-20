{{-- OpenRiC Workspace - export every unit of description as CSV. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'Export archival descriptions')
@section('content')
    <p><a href="{{ route('records.index') }}">Archival descriptions</a></p>
    <h1>Export archival descriptions</h1>
    <p>Every unit of description you may read, as CSV in AtoM's ISAD(G) columns, each hierarchy from its top unit down. For a finding aid in EAD, or one unit in Dublin Core or MODS, use Export on the unit's page.</p>
    <form method="get" action="{{ route('records.export-all') }}">
        <input type="hidden" name="download" value="1">
        <label for="repository">Repository</label>
        <select id="repository" name="repository">
            <option value="">Every repository</option>
            @foreach($repositories as $repository)<option value="{{ $repository->id }}">{{ $repository->described('authorised-name', ['en']) }}</option>@endforeach
        </select>
        <label for="language">Language</label>
        <input id="language" name="language" placeholder="Each unit's original language" aria-describedby="language-help">
        <small id="language-help">A BCP 47 tag such as en or af; a value not written in it is given in the language it was first written in.</small>
        <p><label><input type="checkbox" name="rows" value="every-language"> One row for each language a unit is described in</label></p>
        @if($mayDrafts)
            <p><label><input type="checkbox" name="drafts" value="1"> Include drafts</label> <small>Only if you may see the drafts of every unit; otherwise only published values and names.</small></p>
        @endif
        <button type="submit">Download CSV</button>
    </form>
@endsection
