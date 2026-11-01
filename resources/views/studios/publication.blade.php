{{-- OpenRiC Workspace - a project's publication studio (Heratio's publication studio; roadmap phase 19g): venues, manuscripts sent, their requirements and the reviewers' points. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Publication studio').': '.$project->title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @include('studios._nav')
    <h1>{{ __('Publication studio') }}</h1>
    <h2>{{ __('Venues that fit') }}</h2>
    <form method="get" action="{{ route('research.studios.publication', $project) }}"><label for="pv-scope">{{ __('More words') }}</label> <input id="pv-scope" name="scope" value="{{ $filters['scope'] ?? '' }}">
        <label><input type="checkbox" name="open_access" value="1" @checked(! empty($filters['open_access']))> {{ __('Open access') }}</label>
        <label for="pv-market">{{ __('Accreditation') }}</label> <input id="pv-market" name="market" size="4" value="{{ $filters['market'] ?? '' }}"> <button type="submit">{{ __('Show') }}</button></form>
    @forelse($venues as $v)<p><strong>{{ $v->title }}</strong> <span class="meta">{{ $v->publisher }}@if($v->accreditation) · {{ $v->accreditation }}@endif @if($v->open_access) · {{ __('open access') }}@endif · {{ trans_choice(':count word in common|:count words in common', $v->match_score, ['count' => $v->match_score]) }}</span>@if($v->subject_scope)<br>{{ $v->subject_scope }}@endif</p>
    @empty<p class="meta">{{ __('No journal of the directory fits the project\'s words.') }} <a href="{{ route('research.journals.directory') }}">{{ __('The directory') }}</a></p>@endforelse
    <h2>{{ __('Manuscripts') }}</h2>
    @forelse($submissions as $s)
        <section style="border-top: 1px solid var(--line); padding: .3rem 0">
            <p><strong>{{ $s->manuscript_title ?? __('Untitled') }}</strong> · {{ $s->venue_name }} · {{ $s->status->label($lang) }}@if($s->submitted_at) · {{ __('sent :date', ['date' => $s->submitted_at->format('j M Y')]) }}@endif @if($s->decision_at) · {{ __('decided :date', ['date' => $s->decision_at->format('j M Y')]) }}@endif
                @if($s->doi) · <a href="https://doi.org/{{ $s->doi }}" rel="noopener noreferrer">{{ $s->doi }}</a>@endif</p>
            <p>{{ __('Requirements met: :met of :all', ['met' => $s->requirements->where('met', true)->count(), 'all' => $s->requirements->count()]) }}</p>
            @if($level >= 2)
                @foreach($transitions[$s->status->notation] ?? [] as $to)<form method="post" action="{{ route('research.studios.publication.step', [$project, 'move', $s]) }}" style="display: inline">@csrf<input type="hidden" name="to" value="{{ $to }}"><button type="submit">{{ $statuses[$to]->label($lang) }}</button></form> @endforeach
                <details><summary>{{ __('Requirements') }}</summary>
                    @foreach($s->requirements as $r)<form method="post" action="{{ route('research.studios.publication.step', [$project, 'tick', $s]) }}">@csrf<input type="hidden" name="requirement_id" value="{{ $r->id }}">
                        <label><input type="checkbox" name="met" value="1" @checked($r->met)> {{ $r->label }}</label> <input name="note" size="25" value="{{ $r->note }}" aria-label="{{ __('Note') }}"> <button type="submit">{{ __('Save') }}</button></form>@endforeach
                    <form method="post" action="{{ route('research.studios.publication.step', [$project, 'requirement', $s]) }}">@csrf<input name="label" size="40" required aria-label="{{ __('Requirement') }}"> <button type="submit">{{ __('Add') }}</button></form>
                </details>
                <details><summary>{{ __('Reviewers\' points and replies') }} ({{ $s->responses->count() }})</summary>
                    @foreach($s->responses as $r)<p><strong>{{ $r->reviewer_label }}</strong>: {{ $r->point }}@if($r->response)<br><em>{{ __('Reply') }}:</em> {{ $r->response }}@endif @if($r->revision_note)<br><em>{{ __('Revised') }}:</em> {{ $r->revision_note }}@endif</p>@endforeach
                    <form method="post" action="{{ route('research.studios.publication.step', [$project, 'response', $s]) }}">@csrf<label for="rp-l{{ $loop->index }}">{{ __('Reviewer') }}</label> <input id="rp-l{{ $loop->index }}" name="reviewer_label" size="15"><br>
                        <textarea name="point" rows="2" cols="70" required aria-label="{{ __('The point') }}"></textarea><br><textarea name="response" rows="2" cols="70" aria-label="{{ __('Reply') }}"></textarea><br>
                        <input name="revision_note" size="60" aria-label="{{ __('What was revised') }}"> <button type="submit">{{ __('Add') }}</button></form>
                </details>
            @endif
        </section>
    @empty<p>{{ __('None.') }}</p>@endforelse
    @if($level >= 2)<h2>{{ __('A new manuscript') }}</h2><form method="post" action="{{ route('research.studios.publication.step', [$project, 'save']) }}">@csrf
        <label for="pm-t">{{ __('Title') }}</label> <input id="pm-t" name="manuscript_title" size="50"><br>
        <label for="pm-j">{{ __('Venue') }}</label> <select id="pm-j" name="target_journal_id"><option value="">{{ __('Not in the directory') }}</option>@foreach($journals as $j)<option value="{{ $j->id }}">{{ $j->title }}</option>@endforeach</select>
        <label for="pm-v">{{ __('or named') }}</label> <input id="pm-v" name="venue_name" size="30"> <button type="submit">{{ __('Create') }}</button></form>@endif
@endsection
