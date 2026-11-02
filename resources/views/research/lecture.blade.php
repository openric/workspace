{{-- OpenRiC Workspace - a lecture, its sections and resources (Heratio's lecture page; roadmap phase 19j). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $lecture->title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <p><a href="{{ route('research.teaching') }}">{{ __('Lectures and training') }}</a></p>
    <h1>{{ $lecture->title }}</h1>
    @include('research._head')
    @if($lecture->subtitle)<p><em>{{ $lecture->subtitle }}</em></p>@endif
    <p class="meta">{{ $lecture->type->label($lang) }} · {{ $lecture->status->label($lang) }}@if($lecture->speaker_name) · {{ $lecture->speaker_name }}@if($lecture->speaker_affiliation), {{ $lecture->speaker_affiliation }}@endif @endif
        @if($lecture->scheduled_at) · {{ $lecture->scheduled_at->timezone(config('workspace.timezone'))->format('j M Y H:i') }}@endif @if($lecture->location) · {{ $lecture->location }}@endif
        @if($lecture->duration_minutes) · {{ __(':n minutes', ['n' => $lecture->duration_minutes]) }}@endif</p>
    @if($lecture->summary)<p style="white-space: pre-wrap">{{ $lecture->summary }}</p>@endif
    <p>@if($lecture->recording_url)<a href="{{ $lecture->recording_url }}" rel="noopener noreferrer">{{ __('Recording') }}</a> @endif @if($lecture->slides_url)<a href="{{ $lecture->slides_url }}" rel="noopener noreferrer">{{ __('Slides') }}</a>@endif
        @if($lecture->coverRecord) · <a href="{{ route('records.show', $lecture->coverRecord) }}">{{ \App\Relations\EntityKinds::label($lecture->coverRecord) }}</a>@endif</p>
    @foreach($lecture->sections as $s)
        <section>
            @if($s->heading)<h2>{{ $s->heading }}</h2>@endif
            @if($s->media_url)<p><a href="{{ $s->media_url }}" rel="noopener noreferrer">{{ $s->mediaType?->label($lang) ?? __('Media') }}</a></p>@endif
            {!! \App\Research\Teaching::html($s->body) !!}
            @if($mayEdit)<details><summary>{{ __('Change') }}</summary><form method="post" action="{{ route('research.lectures.step', [$lecture, 'section', $s->id]) }}">@csrf @include('research._section-fields', ['s' => $s, 'x' => 's'.$loop->index])<button type="submit">{{ __('Save') }}</button></form>
                <form method="post" action="{{ route('research.lectures.step', [$lecture, 'remove-section', $s->id]) }}">@csrf<button type="submit">{{ __('Remove') }}</button></form></details>@endif
        </section>
    @endforeach
    @if($lecture->resources->isNotEmpty())<h2>{{ __('Resources') }}</h2><ul>@foreach($lecture->resources as $r)<li>{{ $r->resourceType->label($lang) }}: @if($r->url)<a href="{{ $r->url }}" rel="noopener noreferrer">{{ $r->label }}</a>@else{{ $r->label }}@endif
        @if($mayEdit)<form method="post" action="{{ route('research.lectures.step', [$lecture, 'remove-resource', $r->id]) }}" style="display: inline">@csrf<button type="submit">{{ __('Remove') }}</button></form>@endif</li>@endforeach</ul>@endif
    @if($mayEdit)
        <details><summary>{{ __('Add a section') }}</summary><form method="post" action="{{ route('research.lectures.step', [$lecture, 'section']) }}">@csrf @include('research._section-fields', ['s' => null, 'x' => 'sn'])<button type="submit">{{ __('Add') }}</button></form></details>
        <details><summary>{{ __('Add a resource') }}</summary><form method="post" action="{{ route('research.lectures.step', [$lecture, 'resource']) }}">@csrf
            <label for="r-label">{{ __('Label') }}</label> <input id="r-label" name="label" required size="30"> <label for="r-url">{{ __('Web address') }}</label> <input id="r-url" name="url" type="url" size="40">
            <label for="r-type">{{ __('Kind') }}</label> <select id="r-type" name="resource_type">@foreach($resourceTypes as $c)<option value="{{ $c->notation }}">{{ $c->label($lang) }}</option>@endforeach</select> <button type="submit">{{ __('Add') }}</button></form></details>
        <details><summary>{{ __('Change the lecture') }}</summary><form method="post" action="{{ route('research.lectures.save', $lecture) }}">@csrf
            <label for="le-title">{{ __('Title') }}</label> <input id="le-title" name="title" required size="40" value="{{ $lecture->title }}"> <label for="le-sub">{{ __('Subtitle') }}</label> <input id="le-sub" name="subtitle" size="30" value="{{ $lecture->subtitle }}"><br>
            <label for="le-type">{{ __('Kind') }}</label> @include('projects._select', ['id' => 'le-type', 'name' => 'type', 'choices' => $types, 'current' => $lecture->type->notation, 'default' => 'standalone'])
            <label for="le-status">{{ __('Status') }}</label> @include('projects._select', ['id' => 'le-status', 'name' => 'status', 'choices' => $statuses, 'current' => $lecture->status->notation, 'default' => 'draft'])<br>
            <label for="le-speaker">{{ __('Speaker') }}</label> <input id="le-speaker" name="speaker_name" size="25" value="{{ $lecture->speaker_name }}"> <label for="le-aff">{{ __('Affiliation') }}</label> <input id="le-aff" name="speaker_affiliation" size="25" value="{{ $lecture->speaker_affiliation }}"><br>
            <label for="le-when">{{ __('When') }}</label> <input id="le-when" name="scheduled_at" type="datetime-local" value="{{ $lecture->scheduled_at?->timezone(config('workspace.timezone'))->format('Y-m-d\TH:i') }}">
            <label for="le-where">{{ __('Where') }}</label> <input id="le-where" name="location" size="20" value="{{ $lecture->location }}"> <label for="le-mins">{{ __('Minutes') }}</label> <input id="le-mins" name="duration_minutes" type="number" min="1" style="width: 5rem" value="{{ $lecture->duration_minutes }}"><br>
            <label for="le-rec">{{ __('Recording') }}</label> <input id="le-rec" name="recording_url" type="url" size="35" value="{{ $lecture->recording_url }}"> <label for="le-slides">{{ __('Slides') }}</label> <input id="le-slides" name="slides_url" type="url" size="35" value="{{ $lecture->slides_url }}"><br>
            <label for="le-cur">{{ __('Curriculum reference') }}</label> <input id="le-cur" name="curriculum_ref" size="20" value="{{ $lecture->curriculum_ref }}"> <label for="le-cover">{{ __('A record of the catalogue, by its persistent IRI') }}</label> <input id="le-cover" name="cover_iri" size="40" value="{{ $lecture->coverRecord?->iri }}"><br>
            <label for="le-sum">{{ __('Summary') }}</label><br><textarea id="le-sum" name="summary" rows="3" cols="80">{{ $lecture->summary }}</textarea><br><button type="submit">{{ __('Save') }}</button></form>
            <form method="post" action="{{ route('research.lectures.step', [$lecture, 'delete']) }}">@csrf<button type="submit">{{ __('Delete the lecture') }}</button></form></details>
    @endif
@endsection
