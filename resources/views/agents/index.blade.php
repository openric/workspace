{{-- OpenRiC Workspace - authority records: search, filter and sort. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'Authority records')
@section('content')
    <h1>Authority records</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p>
        @if(auth()->user()->may('create', 'authority'))<a href="{{ route('agents.create') }}">New authority record</a> · <a href="{{ route('imports.create') }}">Import</a> · @endif
        <a href="{{ route('agents.dashboard') }}">Dashboard and work queue</a>
        @if(auth()->user()->may('update', 'authority')) · <a href="{{ route('agents.duplicates') }}">Possible duplicates</a>@endif
    </p>
    <form method="get" action="{{ route('agents.index') }}" style="display: flex; gap: .75rem; flex-wrap: wrap; align-items: end">
        <div><label for="q" style="margin-top: 0">Name or identifier</label><input id="q" name="q" value="{{ $query }}" maxlength="200"></div>
        <div><label for="type" style="margin-top: 0">Type of entity</label>
            <select id="type" name="type" style="font: inherit; padding: .5rem">
                <option value="">Any</option>
                @foreach($types as $t)<option value="{{ $t->notation }}" @selected($type === $t->notation)>{{ $t->label(['en', 'und']) }}</option>@endforeach
            </select></div>
        <div><label for="status" style="margin-top: 0">Status</label>
            <select id="status" name="status" style="font: inherit; padding: .5rem">
                <option value="">Any</option>
                @foreach($statuses as $s)<option value="{{ $s->id }}" @selected($status === $s->id)>{{ $s->label(['en', 'und']) }}</option>@endforeach
            </select></div>
        <div><label for="sort" style="margin-top: 0">Sort by</label>
            <select id="sort" name="sort" style="font: inherit; padding: .5rem">
                <option value="name" @selected($sort === 'name')>Name</option>
                <option value="updated" @selected($sort === 'updated')>Last changed</option>
            </select></div>
        <button type="submit" style="margin-top: 0">Show</button>
    </form>
    @forelse($agents as $agent)
        <p><a href="{{ route('agents.show', $agent) }}">{{ $agent->described('agent-authorised-name', [app()->getLocale(), 'en']) }}</a>{{ collect([$agent->agentType->label(['en', 'und']), $agent->identifier, $agent->descriptionStatus?->label(['en', 'und']), $agent->isEmbargoed() ? 'embargoed until '.$agent->embargo_until->format('Y-m-d') : null])->filter()->map(fn ($s) => ', '.$s)->join('') }}
            @include('description._row-actions', ['entity' => $agent])</p>
    @empty
        <p>{{ $query !== '' || $type !== '' || $status !== '' ? 'No authority record matches.' : 'No authority records yet.' }}</p>
    @endforelse
    @if($agents->hasPages())
        <nav aria-label="Pages"><p>
            @if($agents->previousPageUrl())<a href="{{ $agents->previousPageUrl() }}" rel="prev">Previous</a>@endif
            Page {{ $agents->currentPage() }} of {{ $agents->lastPage() }}
            @if($agents->nextPageUrl())<a href="{{ $agents->nextPageUrl() }}" rel="next">Next</a>@endif
        </p></nav>
    @endif
@endsection
