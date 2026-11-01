{{-- OpenRiC Workspace - a project's field alerts (Heratio's living field alerts; roadmap phase 19i): the works it watches, and news of them. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Field alerts').': '.$project->title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @include('analysis._nav')
    <h1>{{ __('Field alerts') }}</h1>
    <p class="meta">{{ __('The works the project cites are looked up each day in Crossref and OpenAlex, by their DOIs only: retractions, updates and new works that cite them.') }}</p>
    @forelse($alerts as $a)
        <p>@unless($a->is_read)<strong>{{ __('New') }}</strong> · @endunless<strong>{{ $a->alertType->label($lang) }}</strong> · @if($a->url)<a href="{{ $a->url }}" rel="noopener noreferrer">{{ $a->title }}</a>@else{{ $a->title }}@endif
            <span class="meta">{{ $a->detected_at?->timezone(config('workspace.timezone'))->format('j M Y') }}</span><br>{{ $a->detail }}
            @unless($a->is_read)<form method="post" action="{{ route('research.analysis.alerts.step', [$project, 'read']) }}" style="display: inline">@csrf<input type="hidden" name="alert_id" value="{{ $a->id }}"><button type="submit">{{ __('Mark read') }}</button></form>@endunless</p>
    @empty<p>{{ __('None.') }}</p>@endforelse
    @if($alerts->where('is_read', false)->isNotEmpty())<form method="post" action="{{ route('research.analysis.alerts.step', [$project, 'read']) }}">@csrf<button type="submit">{{ __('Mark all read') }}</button></form>@endif
    <h2>{{ __('Watched works') }}</h2>
    @forelse($watches as $w)<p><a href="https://doi.org/{{ $w->doi }}" rel="noopener noreferrer">{{ $w->doi }}</a>@if($w->title) · {{ $w->title }}@endif <span class="meta">{{ $w->source_ref }}@if($w->last_checked_at) · {{ __('looked up :date', ['date' => $w->last_checked_at->timezone(config('workspace.timezone'))->format('j M Y')]) }}@endif</span>
        @if($level >= 2)<form method="post" action="{{ route('research.analysis.alerts.step', [$project, 'unwatch']) }}" style="display: inline">@csrf<input type="hidden" name="watch_id" value="{{ $w->id }}"><button type="submit">{{ __('Stop watching') }}</button></form>@endif</p>
    @empty<p>{{ __('None.') }}</p>@endforelse
    @if($level >= 2)
        <form method="post" action="{{ route('research.analysis.alerts.step', [$project, 'watch']) }}">@csrf<label for="fw-doi">DOI</label> <input id="fw-doi" name="doi" required size="30"> <label for="fw-title">{{ __('Title') }}</label> <input id="fw-title" name="title" size="30"> <button type="submit">{{ __('Watch') }}</button></form>
        <form method="post" action="{{ route('research.analysis.alerts.step', [$project, 'seed']) }}" style="display: inline">@csrf<button type="submit">{{ __('Watch the DOIs of the project\'s bibliographies') }}</button></form>
        <form method="post" action="{{ route('research.analysis.alerts.step', [$project, 'scan']) }}" style="display: inline">@csrf<button type="submit">{{ __('Look them up now') }}</button></form>
    @endif
@endsection
