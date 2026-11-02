{{-- OpenRiC Workspace - rules and mandates: search and filter (roadmap phase 23b). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Rules and mandates'))
@section('content')
    <h1>{{ __('Rules and mandates') }} @include('description._ric', ['term' => 'rico:Rule'])</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p>{{ __('The laws, regulations, policies and mandates that give agents their authority or govern records and activities. Each is linked to whom it gives authority and what it regulates, with the dates they applied.') }}</p>
    @if(auth()->user()->may('create', 'authority'))<p><a href="{{ route('rules.create') }}">{{ __('New rule or mandate') }}</a></p>@endif
    <form method="get" action="{{ route('rules.index') }}" style="display: flex; gap: .75rem; flex-wrap: wrap; align-items: end">
        <div><label for="q" style="margin-top: 0">{{ __('Name or identifier') }}</label><input id="q" name="q" value="{{ $query }}" maxlength="200"></div>
        <div><label for="type" style="margin-top: 0">{{ __('Rule type') }}</label>
            <select id="type" name="type" style="font: inherit; padding: .5rem">
                <option value="">{{ __('Any') }}</option>
                @foreach($types as $t)<option value="{{ $t->id }}" @selected($type === $t->id)>{{ $t->anyLabel(['en']) }}</option>@endforeach
            </select></div>
        <button type="submit" style="margin-top: 0">{{ __('Search') }}</button>
    </form>
    @forelse($rules as $rule)
        <p><a href="{{ route('rules.show', $rule) }}">{{ $rule->described('rule-name', [app()->getLocale(), 'en']) }}</a>{{ collect([$rule->ruleType?->anyLabel(['en']), $rule->dates->first()?->display(), $rule->identifier])->filter()->map(fn ($s) => ', '.$s)->join('') }}
            @include('description._row-actions', ['entity' => $rule])</p>
    @empty
        <p>{{ __($query !== '' || $type !== '' ? 'No rule matches.' : 'No rules yet.') }}</p>
    @endforelse
    @if($rules->hasPages())
        <nav aria-label="{{ __('Pages') }}"><p>
            @if($rules->previousPageUrl())<a href="{{ $rules->previousPageUrl() }}" rel="prev">{{ __('Previous') }}</a>@endif
            {{ __('Page :page of :pages', ['page' => $rules->currentPage(), 'pages' => $rules->lastPage()]) }}
            @if($rules->nextPageUrl())<a href="{{ $rules->nextPageUrl() }}" rel="next">{{ __('Next') }}</a>@endif
        </p></nav>
    @endif
@endsection
