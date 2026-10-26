{{-- OpenRiC Workspace - browse archival descriptions. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Archival descriptions'))
@section('content')
    <h1>{{ __('Archival descriptions') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p>@if(auth()->user()->mayAnywhere('create', 'description'))<a href="{{ route('records.create') }}">{{ __('New top-level unit (fonds or collection)') }}</a> · @endif<a href="{{ route('records.export-all') }}">{{ __('Export as CSV') }}</a>@if(auth()->user()->mayAnywhere('create', 'description')) · <a href="{{ route('imports.create') }}">{{ __('Import') }}</a> · <a href="{{ route('imports.index') }}">{{ __('Import') }}</a>@endif</p>
    <form method="get" action="{{ route('records.index') }}" style="display: flex; gap: .75rem; flex-wrap: wrap; align-items: end">
        <div><label for="q" style="margin-top: 0">{{ __('Title, other name or reference code') }}</label><input id="q" name="q" value="{{ $query }}" maxlength="200"></div>
        <div><label for="repository" style="margin-top: 0">{{ __('Repository') }}</label>
            <select id="repository" name="repository" style="font: inherit; padding: .5rem"><option value="">{{ __('Any') }}</option>
                @foreach($repositories as $r)<option value="{{ $r->id }}" @selected($filters['repository'] === $r->id)>{{ $r->described('authorised-name', ['en']) }}</option>@endforeach
            </select></div>
        <div><label for="level" style="margin-top: 0">{{ __('Level') }}</label>
            <select id="level" name="level" style="font: inherit; padding: .5rem"><option value="">{{ __('Any') }}</option>
                @foreach($levels as $l)<option value="{{ $l->id }}" @selected($filters['level'] === $l->id)>{{ $l->label(\App\Support\Vocab::languages()) }}</option>@endforeach
            </select></div>
        <div><label for="publication" style="margin-top: 0">{{ __('Publication') }}</label>
            <select id="publication" name="publication" style="font: inherit; padding: .5rem">
                <option value="">{{ __('Any') }}</option>
                <option value="published" @selected($filters['publication'] === 'published')>{{ __('Title published') }}</option>
                <option value="unpublished" @selected($filters['publication'] === 'unpublished')>{{ __('Title not published') }}</option>
            </select></div>
        <div><label for="sort" style="margin-top: 0">{{ __('Sort by') }}</label>
            <select id="sort" name="sort" style="font: inherit; padding: .5rem">
                <option value="title" @selected($filters['sort'] === 'title')>{{ __('Title') }}</option>
                <option value="identifier" @selected($filters['sort'] === 'identifier')>{{ __('Reference code') }}</option>
                <option value="updated" @selected($filters['sort'] === 'updated')>{{ __('Last changed') }}</option>
            </select></div>
        <div><input type="hidden" name="top" value="0"><label style="margin-top: 0"><input type="checkbox" name="top" value="1" style="width: auto" @checked($filters['top'])> {{ __('Top-level only') }}</label></div>
        <button type="submit" style="margin-top: 0">{{ __('Show') }}</button>
    </form>
    @forelse($units as $unit)
        <p><a href="{{ route('records.show', $unit) }}">{{ $unit->described('title', [app()->getLocale(), 'en']) }}</a>
            ({{ collect([$unit->level->label(\App\Support\Vocab::languages()), $unit->referenceCode(), $unit->parent ? 'in '.$unit->parent->described('title', ['en']) : null, $unit->repository->described('authorised-name', ['en'])])->filter()->join(', ') }})
            @include('description._row-actions', ['entity' => $unit])</p>
    @empty
        <p>{{ __($query !== '' ? 'No unit of description matches.' : 'No archival descriptions yet.') }}</p>
    @endforelse
    @if($units->hasPages())
        <nav aria-label="{{ __('Pages') }}"><p>
            @if($units->previousPageUrl())<a href="{{ $units->previousPageUrl() }}" rel="prev">{{ __('Previous') }}</a>@endif
            {{ __('Page :page of :pages', ['page' => $units->currentPage(), 'pages' => $units->lastPage()]) }}
            @if($units->nextPageUrl())<a href="{{ $units->nextPageUrl() }}" rel="next">{{ __('Next') }}</a>@endif
        </p></nav>
    @endif
@endsection
