{{-- OpenRiC Workspace - one's research memory (Heratio's research memory; roadmap phase 19i): what to carry forward, from every project, with suggestions from a project's decisions. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Research memory'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @if($project)@include('reasoning._nav')@else
        @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
        @error('research')<p class="error" role="alert">{{ $message }}</p>@enderror
    @endif
    <h1>{{ __('Research memory') }}@if($project): {{ $project->title }}@endif</h1>
    @if($suggestions)
        <h2>{{ __('Suggested from the decision log') }}</h2>
        @foreach($suggestions as $s)<p>{{ $kinds->firstWhere('notation', $s['kind'])?->label($lang) }}: <strong>{{ $s['title'] }}</strong>
            <form method="post" action="{{ route('research.reasoning.memory.step', ['accept']) }}" style="display: inline">@csrf<input type="hidden" name="project_id" value="{{ $project->id }}"><input type="hidden" name="signature" value="{{ $s['signature'] }}"><button type="submit">{{ __('Keep it') }}</button></form></p>@endforeach
    @endif
    @if(! $project && $carried->isNotEmpty())
        <h2>{{ __('Carried forward') }}</h2>
        @foreach($carried as $c)<p>{{ $c->kind->label($lang) }}: <strong>{{ $c->title }}</strong>@if($c->project) <span class="meta">{{ $c->project->title }}</span>@endif</p>@endforeach
    @endif
    <h2>{{ __('Items') }}</h2>
    @forelse($items as $i)
        <section style="border-top: 1px solid var(--line); padding: .3rem 0">
            <p><strong>{{ $i->title }}</strong> <span class="meta">{{ $i->kind->label($lang) }} · {{ $i->status->label($lang) }}@if($i->project) · {{ $i->project->title }}@endif @if($i->source_ref) · {{ $i->source_ref }}@endif</span></p>
            @if($i->body)<p style="white-space: pre-wrap">{{ $i->body }}</p>@endif
            <details><summary>{{ __('Change') }}</summary><form method="post" action="{{ route('research.reasoning.memory.save', $i) }}">@csrf @include('reasoning._memory-fields', ['m' => $i, 'x' => 'mm'.$loop->index])<button type="submit">{{ __('Save') }}</button></form>
                <form method="post" action="{{ route('research.reasoning.memory.step', ['delete', $i]) }}">@csrf<button type="submit">{{ __('Delete') }}</button></form></details>
        </section>
    @empty<p>{{ __('None.') }}</p>@endforelse
    <h2>{{ __('Remember something') }}</h2>
    <form method="post" action="{{ route('research.reasoning.memory.save') }}">@csrf @include('reasoning._memory-fields', ['m' => null, 'x' => 'mn'])<button type="submit">{{ __('Add') }}</button></form>
@endsection
