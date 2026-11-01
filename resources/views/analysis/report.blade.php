{{-- OpenRiC Workspace - a research report: its sections, written and moved, and its peer review (Heratio's viewReport; roadmap phase 19i). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $report->title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <p><a href="{{ route('research.reports.index') }}">{{ __('Research reports') }}</a>@if($report->project) · <a href="{{ route('research.projects.show', $report->project) }}">{{ $report->project->title }}</a>@endif · <a href="{{ route('research.reports.show', [$report, 'print' => 1]) }}">{{ __('To print') }}</a></p>
    <h1>{{ $report->title }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @error('research')<p class="error" role="alert">{{ $message }}</p>@enderror
    <p>{{ $report->templateType->label($lang) }} · {{ $report->status->label($lang) }} · {{ $report->user->name }}</p>
    @if($report->description)<p>{{ $report->description }}</p>@endif
    @include('research._comments', ['subject' => $report, 'kind' => 'report'])
    @foreach($report->sections as $s)
        <section style="border-top: 1px solid var(--line); padding: .3rem 0">
            <p class="meta">{{ $s->sectionType->label($lang) }}</p>
            @include('analysis._section-body')
            @include('research._comments', ['subject' => $s, 'kind' => 'report-section'])
            @if($write)<details><summary>{{ __('Change') }}</summary>
                <form method="post" action="{{ route('research.reports.section', [$s, 'update']) }}">@csrf @include('analysis._section-fields', ['s' => $s, 'x' => 'rs'.$loop->index])<button type="submit">{{ __('Save') }}</button></form>
                <form method="post" action="{{ route('research.reports.section', [$s, 'up']) }}" style="display: inline">@csrf<button type="submit">{{ __('Up') }}</button></form>
                <form method="post" action="{{ route('research.reports.section', [$s, 'down']) }}" style="display: inline">@csrf<button type="submit">{{ __('Down') }}</button></form>
                <form method="post" action="{{ route('research.reports.section', [$s, 'remove']) }}" style="display: inline">@csrf<button type="submit">{{ __('Remove') }}</button></form></details>@endif
        </section>
    @endforeach
    @if($write)
        <details><summary>{{ __('Add a section') }}</summary><form method="post" action="{{ route('research.reports.step', [$report, 'section']) }}">@csrf @include('analysis._section-fields', ['s' => null, 'x' => 'rsn'])<button type="submit">{{ __('Add') }}</button></form></details>
        @if($report->project)<form method="post" action="{{ route('research.reports.step', [$report, 'fill']) }}">@csrf<button type="submit">{{ __('Fill it from the project') }}</button></form>@endif
    @endif

    <h2>{{ __('Peer review') }}</h2>
    @forelse($report->peerReviews as $r)
        <p>{{ $r->reviewer->name }} · {{ $r->status->label($lang) }}@if($r->rating) · {{ __(':n of 5', ['n' => $r->rating]) }}@endif @if($r->feedback)<br><span style="white-space: pre-wrap">{{ $r->feedback }}</span>@endif</p>
        @if($r->reviewer_id === $user->id && ! in_array($r->status->notation, ['completed', 'declined'], true))
            @if($r->status->notation === 'pending')<form method="post" action="{{ route('research.reports.peer-review', [$r, 'accept']) }}" style="display: inline">@csrf<button type="submit">{{ __('Accept') }}</button></form>@endif
            <form method="post" action="{{ route('research.reports.peer-review', [$r, 'decline']) }}" style="display: inline">@csrf<button type="submit">{{ __('Decline') }}</button></form>
            <form method="post" action="{{ route('research.reports.peer-review', [$r, 'complete']) }}">@csrf<label for="pr-fb">{{ __('Your feedback') }}</label><br><textarea id="pr-fb" name="feedback" rows="4" cols="80" required></textarea><br>
                <label for="pr-rate">{{ __('Rating (1 to 5)') }}</label> <input id="pr-rate" name="rating" type="number" min="1" max="5" style="width: 4rem"> <button type="submit">{{ __('Give the review') }}</button></form>
        @endif
    @empty<p>{{ __('None.') }}</p>@endforelse
    @if($write)<form method="post" action="{{ route('research.reports.step', [$report, 'review']) }}">@csrf<label for="pr-email">{{ __('Ask for a review by e-mail address') }}</label> <input id="pr-email" name="email" type="email" required> <button type="submit">{{ __('Ask') }}</button></form>
        <h2>{{ __('Settings') }}</h2>
        <form method="post" action="{{ route('research.reports.step', [$report, 'update']) }}">@csrf<label for="rpe-title">{{ __('Title') }}</label> <input id="rpe-title" name="title" size="40" value="{{ $report->title }}">
            <label for="rpe-status">{{ __('Status') }}</label> <select id="rpe-status" name="status">@foreach($statuses as $c)<option value="{{ $c->notation }}" @selected($report->status->notation === $c->notation)>{{ $c->label($lang) }}</option>@endforeach</select>
            <label for="rpe-desc">{{ __('Description') }}</label> <input id="rpe-desc" name="description" size="40" value="{{ $report->description }}"> <button type="submit">{{ __('Save') }}</button></form>
        <form method="post" action="{{ route('research.reports.step', [$report, 'delete']) }}">@csrf<button type="submit">{{ __('Delete the report') }}</button></form>
    @endif
@endsection
