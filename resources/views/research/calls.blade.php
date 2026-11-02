{{-- OpenRiC Workspace - the funding calls a researcher watches (Heratio's grant engine calls; roadmap phase 19h). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Funding calls'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($fields = function ($c, $x) use ($statuses, $projects, $lang) { return view('research._call-fields', compact('c', 'x', 'statuses', 'projects', 'lang')); })
    <p><a href="{{ route('research.projects.index') }}">{{ __('Research projects') }}</a></p>
    <h1>{{ __('Funding calls') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @error('research')<p class="error" role="alert">{{ $message }}</p>@enderror
    @forelse($calls as $c)
        <p>@if($c->url)<a href="{{ $c->url }}" rel="noopener noreferrer">{{ $c->title }}</a>@else<strong>{{ $c->title }}</strong>@endif · {{ $c->funder }} · {{ $c->status->label($lang) }}
            @if($c->deadline) · {{ __('Closes :date', ['date' => $c->deadline->format('j M Y')]) }}@if($c->deadline->isPast()) ({{ __('closed') }})@endif @endif
            @if($c->project) · <a href="{{ route('research.funding', $c->project) }}">{{ $c->project->title }}</a>@endif</p>
        <details><summary>{{ __('Change') }}</summary><form method="post" action="{{ route('research.calls.save', $c) }}">@csrf {!! $fields($c, 'c'.$loop->index) !!}<button type="submit">{{ __('Save') }}</button></form>
            <form method="post" action="{{ route('research.calls.remove', $c) }}">@csrf<button type="submit">{{ __('Remove') }}</button></form></details>
    @empty
        <p>{{ __('You watch no funding call.') }}</p>
    @endforelse
    <h2>{{ __('Watch a call') }}</h2>
    <form method="post" action="{{ route('research.calls.save') }}">@csrf {!! $fields(null, 'cn') !!}<button type="submit">{{ __('Add') }}</button></form>
@endsection
