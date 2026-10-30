{{-- OpenRiC Workspace - activities: search and filter. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Activities'))
@section('content')
    <h1>{{ __('Activities') }} @include('description._ric', ['term' => 'rico:Activity'])</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p>{{ __('What people and organisations did: the creation of a series, a marriage, an appointment, a transfer. Each is linked to who performed it or took part, and what it resulted in.') }}</p>
    <p>@if(auth()->user()->may('create', 'authority'))<a href="{{ route('activities.create') }}">{{ __('New activity') }}</a> · <a href="{{ route('imports.create') }}">{{ __('Import') }}</a> · @endif
        <a href="{{ route('exports.contexts', 'eac-f') }}">{{ __('Export all as EAC-F (ZIP)') }}</a></p>
    <form method="get" action="{{ route('activities.index') }}" style="display: flex; gap: .75rem; flex-wrap: wrap; align-items: end">
        <div><label for="q" style="margin-top: 0">{{ __('Name or identifier') }}</label><input id="q" name="q" value="{{ $query }}" maxlength="200"></div>
        <div><label for="type" style="margin-top: 0">{{ __('Activity type') }}</label>
            <select id="type" name="type" style="font: inherit; padding: .5rem">
                <option value="">{{ __('Any') }}</option>
                @foreach($types as $t)<option value="{{ $t->id }}" @selected($type === $t->id)>{{ $t->anyLabel(['en']) }}</option>@endforeach
            </select></div>
        <button type="submit" style="margin-top: 0">{{ __('Search') }}</button>
    </form>
    @forelse($activities as $activity)
        <p><a href="{{ route('activities.show', $activity) }}">{{ $activity->described('activity-name', [app()->getLocale(), 'en']) }}</a>{{ collect([$activity->activityType?->anyLabel(['en']), $activity->dates->first()?->display(), $activity->identifier])->filter()->map(fn ($s) => ', '.$s)->join('') }}
            @include('description._row-actions', ['entity' => $activity])</p>
    @empty
        <p>{{ __($query !== '' || $type !== '' ? 'No activity matches.' : 'No activities yet.') }}</p>
    @endforelse
    @if($activities->hasPages())
        <nav aria-label="{{ __('Pages') }}"><p>
            @if($activities->previousPageUrl())<a href="{{ $activities->previousPageUrl() }}" rel="prev">{{ __('Previous') }}</a>@endif
            {{ __('Page :page of :pages', ['page' => $activities->currentPage(), 'pages' => $activities->lastPage()]) }}
            @if($activities->nextPageUrl())<a href="{{ $activities->nextPageUrl() }}" rel="next">{{ __('Next') }}</a>@endif
        </p></nav>
    @endif
@endsection
