{{-- OpenRiC Workspace - a project's question builder (Heratio's question builder; roadmap phase 19g): the research design brief, its versions and their diagnosis. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Question builder').': '.$project->title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($labels = \App\Research\Studios::BRIEF_LABELS)
    @include('studios._nav')
    <h1>{{ __('Question builder') }}</h1>
    @if($shown)
        <p>{{ __('Version :n of :all', ['n' => $shown->version_no, 'all' => $versions->count()]) }} · {{ $brief->status->label($lang) }} · <span class="meta">{{ $shown->createdBy?->name }} · {{ $shown->created_at->timezone(config('workspace.timezone'))->format('j M Y H:i') }}@if($shown->change_reason) · {{ $shown->change_reason }}@endif</span></p>
        <h2>{{ __('Diagnosis') }}</h2>
        @foreach($diagnosis as $d)<p @class(['error' => in_array($d['level'], ['danger', 'warning'], true)])><strong>{{ $d['label'] }}</strong>: {{ $d['message'] }}</p>@endforeach
        @if($versions->count() > 1)<p class="meta">{{ __('Versions') }}: @foreach($versions as $v)<a href="{{ route('research.studios.question', [$project, 'version' => $v->version_no]) }}">{{ $v->version_no }}</a>@if(! $loop->last), @endif @endforeach</p>@endif
    @endif
    <form method="post" action="{{ route('research.studios.question.save', $project) }}">@csrf
        @foreach($labels as $f => $l)<p><label for="qb-{{ $f }}">{{ __($l) }}</label><br><textarea id="qb-{{ $f }}" name="{{ $f }}" rows="2" cols="90" @readonly($level < 2)>{{ old($f, $shown?->{$f}) }}</textarea></p>@endforeach
        @if($level >= 2)<label for="qb-why">{{ __('Why it changed') }}</label> <input id="qb-why" name="change_reason" size="50">
            <label for="qb-status">{{ __('Status') }}</label> <select id="qb-status" name="status">@foreach($statuses as $s)<option value="{{ $s->notation }}" @selected(($brief?->status->notation ?? 'draft') === $s->notation)>{{ $s->label($lang) }}</option>@endforeach</select>
            <button type="submit">{{ __('Save as a new version') }}</button>@endif</form>
@endsection
