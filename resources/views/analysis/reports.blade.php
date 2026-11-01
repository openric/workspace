{{-- OpenRiC Workspace - research reports (Heratio's reports; roadmap phase 19i): one's own, those of one's projects, and those one is asked to review. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Research reports'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Research reports') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @error('research')<p class="error" role="alert">{{ $message }}</p>@enderror
    @if($reviews->isNotEmpty())<h2>{{ __('Asked to review') }}</h2>
        @foreach($reviews as $r)<p><a href="{{ route('research.reports.show', $r->report) }}">{{ $r->report->title }}</a> · {{ $r->status->label($lang) }}</p>@endforeach @endif
    @forelse($reports as $r)<p><a href="{{ route('research.reports.show', $r) }}">{{ $r->title }}</a> · {{ $r->templateType->label($lang) }} · {{ $r->status->label($lang) }}@if($r->project) · <span class="meta">{{ $r->project->title }}</span>@endif</p>
    @empty<p>{{ __('None.') }}</p>@endforelse
    <h2>{{ __('New report') }}</h2>
    <form method="post" action="{{ route('research.reports.store') }}">@csrf
        <label for="rp-title">{{ __('Title') }}</label> <input id="rp-title" name="title" required size="40">
        <label for="rp-tpl">{{ __('Kind') }}</label> <select id="rp-tpl" name="template_type">@foreach($templates->unique('template_type_id') as $t)<option value="{{ $t->templateType->notation }}" title="{{ $t->description }}">{{ $t->templateType->label($lang) }}</option>@endforeach</select><br>
        <label for="rp-proj">{{ __('Project') }}</label> <select id="rp-proj" name="project_id"><option value="">{{ __('None') }}</option>@foreach($projects as $p)<option value="{{ $p->id }}">{{ $p->title }}</option>@endforeach</select>
        <label for="rp-desc">{{ __('Description') }}</label> <input id="rp-desc" name="description" size="40"> <button type="submit">{{ __('Create') }}</button></form>
@endsection
