{{-- OpenRiC Workspace - one's saved searches (Heratio's saved searches; roadmap phase 19c): run them, take a snapshot, see what is new, and be told of new results. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Saved searches'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Saved searches') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @error('research')<p class="error" role="alert">{{ $message }}</p>@enderror
    <p class="meta">{{ __('Save a search from the search page.') }}</p>
    @forelse($searches as $s)
        <section style="border-top: 1px solid var(--line); padding: .3rem 0">
            <p><strong>{{ $s->name }}</strong> · <code>{{ $s->search_query }}</code> · {{ trans_choice(':count result when saved|:count results when saved', $s->total_results_at_save, ['count' => $s->total_results_at_save]) }}
                @if($s->alert_enabled) · {{ __('alerts :frequency', ['frequency' => mb_strtolower($s->alertFrequency?->label($lang) ?? '')]) }}@if($s->new_results_count) · <strong>{{ trans_choice(':count new|:count new', $s->new_results_count, ['count' => $s->new_results_count]) }}</strong>@endif @endif
                @if($s->description)<br><small>{{ $s->description }}</small>@endif</p>
            <p><a href="{{ route('research.searches.step', [$s, 'run']) }}">{{ __('Run the search') }}</a> · <a href="{{ route('research.searches.step', [$s, 'diff']) }}">{{ __('What is new') }}</a>
                <form method="post" action="{{ route('research.searches.step', [$s, 'snapshot']) }}" style="display: inline">@csrf<button type="submit">{{ __('Take a snapshot') }}</button></form>
                <form method="post" action="{{ route('research.searches.step', [$s, 'delete']) }}" style="display: inline">@csrf<button type="submit">{{ __('Delete') }}</button></form>
                @include('research._project-select', ['thing' => $s, 'kind' => 'search'])</p>
            <details><summary>{{ __('Change') }}</summary><form method="post" action="{{ route('research.searches.update', $s) }}">@csrf @include('research._search-fields', ['s' => $s, 'p' => 's'.$loop->index])<button type="submit">{{ __('Save') }}</button></form></details>
        </section>
    @empty<p>{{ __('None.') }}</p>@endforelse
@endsection
