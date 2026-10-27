{{-- OpenRiC Workspace - authority records: search, filter and sort. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Authority records'))
@section('content')
    <h1>{{ __('Authority records') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p>
        @if(auth()->user()->may('create', 'authority'))<a href="{{ route('agents.create') }}">{{ __('New authority record') }}</a> · <a href="{{ route('imports.create') }}">{{ __('Import') }}</a> · @endif
        <a href="{{ route('agents.dashboard') }}">{{ __('Dashboard and work queue') }}</a>
        @if(auth()->user()->may('update', 'authority')) · <a href="{{ route('agents.duplicates') }}">{{ __('Possible duplicates') }}</a>@endif
    </p>
    <form method="get" action="{{ route('agents.index') }}" style="display: flex; gap: .75rem; flex-wrap: wrap; align-items: end">
        <div><label for="q" style="margin-top: 0">{{ __('Name or identifier') }}</label><input id="q" name="q" value="{{ $query }}" maxlength="200"></div>
        <div><label for="type" style="margin-top: 0">{{ __('Type of entity') }}</label>
            <select id="type" name="type" style="font: inherit; padding: .5rem">
                <option value="">{{ __('Any') }}</option>
                @foreach($types as $t)<option value="{{ $t->notation }}" @selected($type === $t->notation)>{{ $t->label(\App\Support\Vocab::languages()) }}</option>@endforeach
            </select></div>
        <div><label for="status" style="margin-top: 0">{{ __('Status') }}</label>
            <select id="status" name="status" style="font: inherit; padding: .5rem">
                <option value="">{{ __('Any') }}</option>
                @foreach($statuses as $s)<option value="{{ $s->id }}" @selected($status === $s->id)>{{ $s->label(\App\Support\Vocab::languages()) }}</option>@endforeach
            </select></div>
        <div><label for="sort" style="margin-top: 0">{{ __('Sort by') }}</label>
            <select id="sort" name="sort" style="font: inherit; padding: .5rem">
                <option value="name" @selected($sort === 'name')>{{ __('Name') }}</option>
                <option value="updated" @selected($sort === 'updated')>{{ __('Last changed') }}</option>
            </select></div>
        <button type="submit" style="margin-top: 0">{{ __('Show') }}</button>
    </form>
    @php($lang = [app()->getLocale(), 'en'])
    @if($agents->isEmpty())
        <p>{{ __($query !== '' || $type !== '' || $status !== '' ? 'No authority record matches.' : 'No authority records yet.') }}</p>
    @else
        <p class="meta">{{ __(':count found.', ['count' => $agents->total()]) }}</p>
        <ul>
            @foreach($agents as $agent)
                <li><a href="{{ route('agents.show', $agent) }}"><strong>{{ $agent->described('agent-authorised-name', $lang) }}</strong></a>
                    @include('description._row-actions', ['entity' => $agent])
                    <span class="meta">{{ collect([$agent->agentType->label(\App\Support\Vocab::languages()), $agent->described('agent-dates-of-existence', $lang) ?: $agent->dates->map->display()->filter()->join('; '), $agent->identifier,
                        $agent->descriptionStatus?->label(\App\Support\Vocab::languages()), $agent->isEmbargoed() ? __('Embargoed until :date', ['date' => $agent->embargo_until->format('Y-m-d')]) : null])->filter()->join(' · ') }}</span>
                    @if($places = $agent->described('agent-places', $lang))<span class="meta">{{ \Illuminate\Support\Str::limit(strip_tags($places), 120) }}</span>@endif
                    @if($history = $agent->described('agent-history', $lang))<span class="summary">{{ \Illuminate\Support\Str::limit(strip_tags($history), 260) }}</span>@endif</li>
            @endforeach
        </ul>
    @endif
    @if($agents->hasPages())
        <nav aria-label="{{ __('Pages') }}"><p>
            @if($agents->previousPageUrl())<a href="{{ $agents->previousPageUrl() }}" rel="prev">{{ __('Previous') }}</a>@endif
            {{ __('Page :page of :pages', ['page' => $agents->currentPage(), 'pages' => $agents->lastPage()]) }}
            @if($agents->nextPageUrl())<a href="{{ $agents->nextPageUrl() }}" rel="next">{{ __('Next') }}</a>@endif
        </p></nav>
    @endif
@endsection
