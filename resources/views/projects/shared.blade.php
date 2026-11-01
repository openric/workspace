{{-- OpenRiC Workspace - a research project's summary opened by its link (roadmap phase 19f). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', $project->title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ $project->title }}</h1>
    <p>{{ $project->projectType->label($lang) }} · {{ $project->status->label($lang) }}@if($project->start_date) · {{ $project->start_date->format('j M Y') }}@if($project->expected_end_date) - {{ $project->expected_end_date->format('j M Y') }}@endif @endif</p>
    @if($project->description)<p style="white-space: pre-wrap">{{ $project->description }}</p>@endif
    @if($project->teamMembers->isNotEmpty())<h2>{{ __('Team') }}</h2>
        <ul>@foreach($project->teamMembers as $m)<li>{{ $m->person_name }} · {{ $m->role->label($lang) }}@if($m->affiliation), {{ $m->affiliation }}@endif @if($m->orcid) · <a href="https://orcid.org/{{ $m->orcid }}" rel="noopener noreferrer">ORCID {{ $m->orcid }}</a>@endif</li>@endforeach</ul>@endif
    @if($project->milestones->isNotEmpty())<h2>{{ __('Milestones') }}</h2>
        <p>{{ __(':progress% done', ['progress' => $summary['milestones']['progress']]) }}</p>
        <ul>@foreach($project->milestones as $m)<li>{{ $m->title }} · {{ $m->status->label($lang) }}@if($m->due_date) · {{ __('due :date', ['date' => $m->due_date->format('j M Y')]) }}@endif</li>@endforeach</ul>@endif
    @if($project->outputs->isNotEmpty())<h2>{{ __('Outputs') }}</h2>
        <ul>@foreach($project->outputs as $o)@php($url = \App\Research\Projects::outputUrl($o))<li>@if($url)<a href="{{ $url }}" rel="noopener noreferrer">{{ $o->title }}</a>@else{{ $o->title }}@endif · {{ $o->outputType->label($lang) }} · {{ $o->status->label($lang) }}@if($o->venue), {{ $o->venue }}@endif</li>@endforeach</ul>@endif
@endsection
