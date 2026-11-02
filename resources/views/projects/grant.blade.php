{{-- OpenRiC Workspace - a grant application, written section by section beside what the project already holds (Heratio's grant engine editor, without its AI drafting; roadmap phase 19h). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $draft->title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($project = $draft->project)
    @include('projects._part-head')
    <h1>{{ $draft->title }}</h1>
    <p class="meta">{{ $draft->template->label($lang) }} · {{ $draft->status->label($lang) }} · {{ __(':filled of :total sections written (:pct%)', $completeness) }}</p>
    <form method="post" action="{{ route('research.grants.save', $draft) }}">@csrf
        @if($level >= 2)<label for="gd-title">{{ __('Title') }}</label> <input id="gd-title" name="title" required size="60" value="{{ $draft->title }}">
            <label for="gd-status">{{ __('Status') }}</label> <select id="gd-status" name="status">@foreach($statuses as $c)<option value="{{ $c->notation }}" @selected($draft->status->notation === $c->notation)>{{ $c->label($lang) }}</option>@endforeach</select>@endif
        @foreach($draft->sections as $s)
            <h2><label for="gs-{{ $s->section_key }}">{{ $s->label }}</label></h2>
            @if($s->hint)<p class="meta">{{ $s->hint }}</p>@endif
            @if(isset($material[$s->section_key]))<details><summary>{{ __('What the project already holds') }}</summary><pre style="white-space: pre-wrap">{{ $material[$s->section_key] }}</pre></details>@endif
            @if($level >= 2)<textarea id="gs-{{ $s->section_key }}" name="sections[{{ $s->section_key }}]" rows="6" cols="90">{{ $s->body }}</textarea>@else<p style="white-space: pre-wrap">{{ $s->body }}</p>@endif
        @endforeach
        @if($level >= 2)<p><button type="submit">{{ __('Save') }}</button></p>@endif
    </form>
    @php($unused = array_diff_key($material, $draft->sections->keyBy('section_key')->all()))
    @if($unused)<h2>{{ __('More the project holds') }}</h2>@foreach($unused as $key => $text)<h3>{{ ['summary' => __('Summary'), 'background' => __('Background'), 'aims' => __('Aims'), 'methodology' => __('Method protocols'), 'workplan' => __('Milestones'), 'team' => __('Team'), 'outputs' => __('Outputs'), 'ethics' => __('Ethics approvals'), 'data-management' => __('Data management plans')][$key] ?? $key }}</h3><pre style="white-space: pre-wrap">{{ $text }}</pre>@endforeach @endif
@endsection
