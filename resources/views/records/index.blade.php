{{-- OpenRiC Workspace - browse archival descriptions. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'Archival descriptions')
@section('content')
    <h1>Archival descriptions</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p>@if(auth()->user()->mayAnywhere('create', 'description'))<a href="{{ route('records.create') }}">New top-level unit (fonds or collection)</a> · @endif<a href="{{ route('records.export-all') }}">Export as CSV</a></p>
    <form method="get" action="{{ route('records.index') }}" style="display: flex; gap: .75rem; flex-wrap: wrap; align-items: end">
        <div><label for="q" style="margin-top: 0">Title, other name or reference code</label><input id="q" name="q" value="{{ $query }}" maxlength="200"></div>
        <div><label for="repository" style="margin-top: 0">Repository</label>
            <select id="repository" name="repository" style="font: inherit; padding: .5rem"><option value="">Any</option>
                @foreach($repositories as $r)<option value="{{ $r->id }}" @selected($filters['repository'] === $r->id)>{{ $r->described('authorised-name', ['en']) }}</option>@endforeach
            </select></div>
        <div><label for="level" style="margin-top: 0">Level</label>
            <select id="level" name="level" style="font: inherit; padding: .5rem"><option value="">Any</option>
                @foreach($levels as $l)<option value="{{ $l->id }}" @selected($filters['level'] === $l->id)>{{ $l->label(['en', 'und']) }}</option>@endforeach
            </select></div>
        <div><label for="publication" style="margin-top: 0">Publication</label>
            <select id="publication" name="publication" style="font: inherit; padding: .5rem">
                <option value="">Any</option>
                <option value="published" @selected($filters['publication'] === 'published')>Title published</option>
                <option value="unpublished" @selected($filters['publication'] === 'unpublished')>Title not published</option>
            </select></div>
        <div><label for="sort" style="margin-top: 0">Sort by</label>
            <select id="sort" name="sort" style="font: inherit; padding: .5rem">
                <option value="title" @selected($filters['sort'] === 'title')>Title</option>
                <option value="identifier" @selected($filters['sort'] === 'identifier')>Reference code</option>
                <option value="updated" @selected($filters['sort'] === 'updated')>Last changed</option>
            </select></div>
        <div><input type="hidden" name="top" value="0"><label style="margin-top: 0"><input type="checkbox" name="top" value="1" style="width: auto" @checked($filters['top'])> Top level only</label></div>
        <button type="submit" style="margin-top: 0">Show</button>
    </form>
    @forelse($units as $unit)
        <p><a href="{{ route('records.show', $unit) }}">{{ $unit->described('title', [app()->getLocale(), 'en']) }}</a>
            ({{ collect([$unit->level->label(['en', 'und']), $unit->referenceCode(), $unit->parent ? 'in '.$unit->parent->described('title', ['en']) : null, $unit->repository->described('authorised-name', ['en'])])->filter()->join(', ') }})
            @include('description._row-actions', ['entity' => $unit])</p>
    @empty
        <p>{{ $query !== '' ? 'No unit of description matches.' : 'No archival descriptions yet.' }}</p>
    @endforelse
    @if($units->hasPages())
        <nav aria-label="Pages"><p>
            @if($units->previousPageUrl())<a href="{{ $units->previousPageUrl() }}" rel="prev">Previous</a>@endif
            Page {{ $units->currentPage() }} of {{ $units->lastPage() }}
            @if($units->nextPageUrl())<a href="{{ $units->nextPageUrl() }}" rel="next">Next</a>@endif
        </p></nav>
    @endif
@endsection
