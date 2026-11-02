{{-- OpenRiC Workspace - a research project's data management plans (Heratio's DMP builder list; roadmap phase 19h). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Data management plans').' - '.$project->title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @include('projects._part-head')
    <h1>{{ __('Data management plans') }}</h1>
    @forelse($project->dmps as $d)
        @php($c = \App\Research\Funding::completeness($d->sections))
        <p><a href="{{ route('research.projects.plans.show', [$project, $d]) }}">{{ $d->title }}</a> · {{ $d->template->label($lang) }} · {{ $d->status->label($lang) }} · {{ __(':filled of :total sections answered', $c) }}
            · <a href="{{ route('research.projects.plans.show', [$project, $d, 'format' => 'madmp']) }}">{{ __('maDMP (JSON)') }}</a>
            @if($level >= 3 || $d->created_by_id === auth()->id())<form method="post" action="{{ route('research.funding.part.remove', ['plans', $d->id]) }}" style="display: inline">@csrf<button type="submit">{{ __('Remove') }}</button></form>@endif</p>
    @empty
        <p>{{ __('The project has no data management plan yet.') }}</p>
    @endforelse
    @if($level >= 2)
        <h2>{{ __('Start a plan') }}</h2>
        <form method="post" action="{{ route('research.projects.plans.store', $project) }}">@csrf
            <label for="p-title">{{ __('Title') }}</label> <input id="p-title" name="title" required size="40" value="{{ old('title', __('Data management plan')) }}">
            <label for="p-template">{{ __('Template') }}</label> <select id="p-template" name="template">@foreach($templates as $c)<option value="{{ $c->notation }}">{{ $c->label($lang) }}</option>@endforeach</select><br>
            <label for="p-funder">{{ __('Funder') }}</label> <input id="p-funder" name="funder" size="30">
            <label for="p-lang">{{ __('Language') }}</label> <input id="p-lang" name="language" size="6" value="{{ app()->getLocale() }}">
            <label for="p-cname">{{ __('Contact') }}</label> <input id="p-cname" name="contact_name" size="25">
            <label for="p-cmail">{{ __('Contact e-mail address') }}</label> <input id="p-cmail" name="contact_email" type="email" size="25"><br>
            <button type="submit">{{ __('Start') }}</button></form>
    @endif
@endsection
