{{-- OpenRiC Workspace - a project's source triage (Heratio's source triage; roadmap phase 19g): its works and items, sorted and marked by how far they are read. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Source triage').': '.$project->title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @include('studios._nav')
    <h1>{{ __('Source triage') }}</h1>
    <p>@foreach($categories as $c)@if($category === $c->notation)<strong>{{ $c->label($lang) }}</strong>@else<a href="{{ route('research.studios.triage', [$project, 'category' => $c->notation]) }}">{{ $c->label($lang) }}</a>@endif · @endforeach <a href="{{ route('research.studios.triage', $project) }}">{{ __('All') }}</a></p>
    @forelse($board as $s)
        <form method="post" action="{{ route('research.studios.triage.save', $project) }}" style="border-top: 1px solid var(--line); padding: .3rem 0">@csrf<input type="hidden" name="type" value="{{ $s['type'] }}"><input type="hidden" name="id" value="{{ $s['id'] }}">
            <strong>{{ $s['label'] }}</strong> <span class="meta">{{ $s['from'] }}</span><br>
            <select name="triage_category" aria-label="{{ __('Category') }}" @disabled($level < 2)><option value="">{{ __('Not sorted') }}</option>@foreach($categories as $c)<option value="{{ $c->notation }}" @selected($s['triage']?->triageCategory?->notation === $c->notation)>{{ $c->label($lang) }}</option>@endforeach</select>
            <select name="read_status" aria-label="{{ __('Read') }}" @disabled($level < 2)>@foreach($reads as $r)<option value="{{ $r->notation }}" @selected(($s['triage']?->readStatus->notation ?? 'unread') === $r->notation)>{{ $r->label($lang) }}</option>@endforeach</select>
            <input name="notes" size="40" value="{{ $s['triage']?->notes }}" aria-label="{{ __('Notes') }}" @disabled($level < 2)> @if($level >= 2)<button type="submit">{{ __('Save') }}</button>@endif</form>
    @empty<p>{{ __('The project has no bibliography or collection yet.') }}</p>@endforelse
@endsection
