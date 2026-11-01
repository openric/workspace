{{-- OpenRiC Workspace - a project's time machine (Heratio's time machine; roadmap phase 19i): how its research developed, and how it stood on a day. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Time machine').': '.$project->title)
@section('content')
    @include('studios._nav')
    <h1>{{ __('Time machine') }}</h1>
    <form method="get" action="{{ route('research.studios.time-machine', $project) }}"><label for="tm-day">{{ __('As it stood on') }}</label> <input id="tm-day" name="as_of" type="date" value="{{ request('as_of') }}"> <button type="submit">{{ __('Show') }}</button></form>
    @if($state)
        <h2>{{ __('On :date', ['date' => $state['as_of']->format('j F Y')]) }}</h2>
        <p>@if($state['brief']){{ __('Research brief, version :n', ['n' => $state['brief']->version_no]) }}: {{ $state['brief']->primary_question }}@else{{ __('No research brief yet.') }}@endif</p>
        <p>{{ trans_choice(':count claim|:count claims', $state['claims']->count(), ['count' => $state['claims']->count()]) }} · {{ trans_choice(':count decision|:count decisions', $state['decisions']->count(), ['count' => $state['decisions']->count()]) }}
            · {{ trans_choice(':count argument step|:count argument steps', $state['argument']->count(), ['count' => $state['argument']->count()]) }} · {{ trans_choice(':count method protocol|:count method protocols', $state['methods']->count(), ['count' => $state['methods']->count()]) }}</p>
        @if($state['claims']->isNotEmpty())<ul>@foreach($state['claims'] as $c)<li>{{ \Illuminate\Support\Str::limit($c->object_value, 140) }}</li>@endforeach</ul>@endif
    @endif
    @forelse($months as $month => $events)
        <h2>{{ \Carbon\CarbonImmutable::createFromFormat('Y-m', $month)->format('F Y') }}</h2>
        @foreach($events as $e)<p><span class="meta">{{ $e['when']->timezone(config('workspace.timezone'))->format('j M') }}</span> {{ $e['label'] }}@if($e['detail'])<br><small>{{ \Illuminate\Support\Str::limit($e['detail'], 200) }}</small>@endif</p>@endforeach
    @empty<p>{{ __('Nothing has happened yet.') }}</p>@endforelse
@endsection
