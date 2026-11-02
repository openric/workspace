{{-- OpenRiC Workspace - a data management plan, answered section by section (Heratio's DMP builder; roadmap phase 19h). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $plan->title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @include('projects._part-head')
    <h1>{{ $plan->title }}</h1>
    <p class="meta">{{ $plan->template->label($lang) }} · {{ $plan->status->label($lang) }} · {{ __(':filled of :total sections answered (:pct%)', $completeness) }}
        · <a href="{{ route('research.projects.plans.show', [$project, $plan, 'format' => 'madmp']) }}">{{ __('maDMP (JSON)') }}</a></p>
    <form method="post" action="{{ route('research.projects.plans.save', [$project, $plan]) }}">@csrf
        @if($level >= 2)
            <label for="pl-title">{{ __('Title') }}</label> <input id="pl-title" name="title" required size="50" value="{{ $plan->title }}">
            <label for="pl-status">{{ __('Status') }}</label> <select id="pl-status" name="status">@foreach($statuses as $c)<option value="{{ $c->notation }}" @selected($plan->status->notation === $c->notation)>{{ $c->label($lang) }}</option>@endforeach</select><br>
            <label for="pl-funder">{{ __('Funder') }}</label> <input id="pl-funder" name="funder" size="30" value="{{ $plan->funder }}">
            <label for="pl-lang">{{ __('Language') }}</label> <input id="pl-lang" name="language" size="6" value="{{ $plan->language }}">
            <label for="pl-cname">{{ __('Contact') }}</label> <input id="pl-cname" name="contact_name" size="25" value="{{ $plan->contact_name }}">
            <label for="pl-cmail">{{ __('Contact e-mail address') }}</label> <input id="pl-cmail" name="contact_email" type="email" size="25" value="{{ $plan->contact_email }}">
        @endif
        @foreach($plan->sections as $s)
            <h2><label for="ps-{{ $s->section_key }}">{{ $s->label }}</label></h2>
            @if($s->hint)<p class="meta">{{ $s->hint }}</p>@endif
            @if($level >= 2)<textarea id="ps-{{ $s->section_key }}" name="sections[{{ $s->section_key }}]" rows="5" cols="90">{{ $s->body }}</textarea>@else<p style="white-space: pre-wrap">{{ $s->body }}</p>@endif
        @endforeach
        @if($level >= 2)<p><button type="submit">{{ __('Save') }}</button></p>@endif
    </form>
@endsection
