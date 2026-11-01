{{-- OpenRiC Workspace - a project's timeline (Heratio's timeline builder; roadmap phase 19i). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Timeline').': '.$project->title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @include('analysis._nav')
    <h1>{{ __('Timeline') }}</h1>
    <p class="meta"><a href="{{ route('research.analysis.timeline', [$project, 'format' => 'json']) }}">{{ __('As JSON') }}</a></p>
    <ol style="list-style: none; padding: 0">
    @forelse($events as $e)
        <li style="border-left: 4px solid {{ $e->color ?? 'var(--line)' }}; padding-left: .6rem; margin-bottom: .4rem"><strong>{{ $e->date_start->format('j M Y') }}@if($e->date_end) - {{ $e->date_end->format('j M Y') }}@endif</strong> · {{ $e->label }} <span class="meta">{{ $e->dateType->label($lang) }}</span>@if($e->source_table === 'record_resources') · <a href="{{ route('records.show', $e->source_id) }}">{{ __('the record') }}</a>@endif
            @if($e->description)<br>{{ $e->description }}@endif
            @if($level >= 2)<details><summary>{{ __('Change') }}</summary><form method="post" action="{{ route('research.analysis.plot', [$project, 'events', $e->id]) }}">@csrf @include('analysis._event-fields', ['e' => $e, 'x' => 'te'.$loop->index])<button type="submit">{{ __('Save') }}</button></form>
                <form method="post" action="{{ route('research.analysis.plot', [$project, 'events', $e->id]) }}">@csrf<input type="hidden" name="remove" value="1"><button type="submit">{{ __('Remove') }}</button></form></details>@endif</li>
    @empty<li>{{ __('None.') }}</li>@endforelse
    </ol>
    @if($level >= 2)
        <h2>{{ __('Add an event') }}</h2>
        <form method="post" action="{{ route('research.analysis.plot', [$project, 'events']) }}">@csrf @include('analysis._event-fields', ['e' => null, 'x' => 'ten'])<button type="submit">{{ __('Add') }}</button></form>
        @if($collections->isNotEmpty())<form method="post" action="{{ route('research.analysis.plot', [$project, 'from-collection']) }}">@csrf<label for="tc">{{ __('Add the dated records of the collection') }}</label> <select id="tc" name="collection_id">@foreach($collections as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select> <button type="submit">{{ __('Add') }}</button></form>@endif
    @endif
@endsection
