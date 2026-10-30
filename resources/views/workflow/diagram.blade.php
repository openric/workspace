{{-- OpenRiC Workspace - a workflow's steps in order, and where a task stands among them (Heratio's diagram and task diagram), drawn as SVG on the server. Needs $workflow, $task (or null), $done (step keys approved). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Diagram of :name', ['name' => $workflow->name]))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($steps = $workflow->steps->where('is_active', true)->values())
    @php($w = 200)
    @php($gap = 40)
    @include('workflow._nav')
    <h1>{{ __('Diagram of :name', ['name' => $workflow->name]) }}</h1>
    @if($task)<p><a href="{{ route('workflow.tasks.show', $task) }}">{{ __('Back to the task') }}</a></p>@endif
    <svg role="img" aria-labelledby="diagram-title" viewBox="0 0 {{ max(1, $steps->count()) * ($w + $gap) }} 110" style="width: 100%; max-width: {{ max(1, $steps->count()) * ($w + $gap) }}px; height: auto">
        <title id="diagram-title">{{ $steps->map(fn ($s) => $s->position.'. '.$s->name)->join(', ') }}</title>
        @foreach($steps as $i => $s)
            @php($x = $i * ($w + $gap))
            @php($here = $task?->step_id === $s->id)
            @php($passed = in_array($s->id, $done ?? [], true) && ! $here)
            <rect x="{{ $x + 2 }}" y="20" width="{{ $w }}" height="70" rx="8" fill="{{ $here ? 'var(--accent)' : ($passed ? 'var(--surface)' : 'none') }}" stroke="currentColor" stroke-width="{{ $here ? 3 : 1 }}"/>
            <text x="{{ $x + 2 + $w / 2 }}" y="48" text-anchor="middle" font-size="14" fill="{{ $here ? 'var(--bg)' : 'currentColor' }}">{{ \Illuminate\Support\Str::limit($s->position.'. '.$s->name, 26) }}</text>
            <text x="{{ $x + 2 + $w / 2 }}" y="70" text-anchor="middle" font-size="11" fill="{{ $here ? 'var(--bg)' : 'currentColor' }}">{{ \Illuminate\Support\Str::limit($s->stepType->label($lang).($passed ? ' - '.__('done') : ''), 30) }}</text>
            @if(! $loop->last)<path d="M {{ $x + $w + 6 }} 55 L {{ $x + $w + $gap - 4 }} 55" stroke="currentColor" stroke-width="1.5" marker-end="url(#arrow)"/>@endif
        @endforeach
        <defs><marker id="arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="6" markerHeight="6" orient="auto"><path d="M0,0 L10,5 L0,10 z" fill="currentColor"/></marker></defs>
    </svg>
    <ol>@foreach($steps as $s)<li>{{ $s->name }} ({{ collect([$s->stepType->label($lang), $s->action->label($lang), $s->requiredRole?->name($lang)])->filter()->join(', ') }})@if($task?->step_id === $s->id) - <strong>{{ __('this task') }}</strong>@endif</li>@endforeach</ol>
@endsection
