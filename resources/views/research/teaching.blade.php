{{-- OpenRiC Workspace - lectures and training courses (Heratio's lecture and training pages; roadmap phase 19j). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Lectures and training'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Lectures and training') }}</h1>
    @include('research._head')
    <h2>{{ __('Lectures') }}</h2>
    <ul>@forelse($lectures as $l)<li><a href="{{ route('research.lectures.show', $l) }}">{{ $l->title }}</a> · {{ $l->type->label($lang) }} · {{ $l->status->label($lang) }}@if($l->scheduled_at) · {{ $l->scheduled_at->timezone(config('workspace.timezone'))->format('j M Y H:i') }}@endif @if($l->user) · {{ $l->user->name }}@endif</li>@empty<li>{{ __('None.') }}</li>@endforelse</ul>
    <details><summary>{{ __('Add a lecture') }}</summary><form method="post" action="{{ route('research.lectures.save') }}">@csrf
        <label for="l-title">{{ __('Title') }}</label> <input id="l-title" name="title" required size="40">
        <label for="l-type">{{ __('Kind') }}</label> <select id="l-type" name="type">@foreach($types as $c)<option value="{{ $c->notation }}" @selected($c->notation === 'standalone')>{{ $c->label($lang) }}</option>@endforeach</select>
        @if($mayRun)<input type="hidden" name="institutional" value="0"><label><input type="checkbox" name="institutional" value="1"> {{ __('The institution\'s') }}</label>@endif
        <button type="submit">{{ __('Add') }}</button></form></details>
    <h2>{{ __('Training courses') }}</h2>
    <ul>@forelse($courses as $c)<li><a href="{{ route('research.courses.show', $c) }}">{{ $c->title }}</a> · {{ $c->status->label($lang) }} · {{ trans_choice(':count module|:count modules', $c->modules_count, ['count' => $c->modules_count]) }}
        @if($e = $enrolments[$c->id] ?? null) · {{ $e->status->label($lang) }}@if($e->certificate) · <a href="{{ route('research.certificates.show', $e->certificate->certificate_no) }}">{{ $e->certificate->certificate_no }}</a>@endif @endif</li>@empty<li>{{ __('None.') }}</li>@endforelse</ul>
    <details><summary>{{ __('Add a course') }}</summary><form method="post" action="{{ route('research.courses.save') }}">@csrf
        <label for="c-title">{{ __('Title') }}</label> <input id="c-title" name="title" required size="40">
        <label for="c-pass">{{ __('Pass mark (%)') }}</label> <input id="c-pass" name="pass_mark" type="number" min="0" max="100" value="80" style="width: 5rem">
        @if($mayRun)<input type="hidden" name="institutional" value="0"><label><input type="checkbox" name="institutional" value="1"> {{ __('The institution\'s') }}</label>@endif
        <button type="submit">{{ __('Add') }}</button></form></details>
@endsection
