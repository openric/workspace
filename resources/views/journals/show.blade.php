{{-- OpenRiC Workspace - a research journal: its issues and articles (Heratio's research journal; roadmap phase 19g). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $journal->title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <p><a href="{{ route('research.journals.index') }}">{{ __('Research journals') }}</a></p>
    <h1>{{ $journal->title }}</h1>
    @if($journal->subtitle)<p>{{ $journal->subtitle }}</p>@endif
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @error('research')<p class="error" role="alert">{{ $message }}</p>@enderror
    <p>{{ $journal->kind->label($lang) }} · {{ $journal->status->label($lang) }}@if($journal->issn) · ISSN {{ $journal->issn }}@endif @if($journal->eissn) · e-ISSN {{ $journal->eissn }}@endif @if($journal->publisher) · {{ $journal->publisher }}@endif @if($journal->targetJournal) · {{ __('aimed at :title', ['title' => $journal->targetJournal->title]) }}@endif</p>
    @if($journal->aims_scope)<p>{{ $journal->aims_scope }}</p>@endif
    @if($article)
        <article style="border: 1px solid var(--line); padding: .6rem; margin: .6rem 0">
            <h2>{{ $article->title }}</h2>
            <p class="meta">{{ $article->authors }} · {{ $article->status->label($lang) }} · {{ trans_choice(':count word|:count words', $article->word_count, ['count' => $article->word_count]) }}@if($article->issue) · {{ trim($article->issue->volume.' '.$article->issue->number.' '.$article->issue->title) }}@endif</p>
            @php($problems = $service->check($article))
            @if($problems)<div class="error">@foreach($problems as $p)<p>{{ $p }}</p>@endforeach</div>@else<p>{{ __('Ready to send.') }}</p>@endif
            @if($article->abstract)<p><em>{{ $article->abstract }}</em></p>@endif
            <div>{!! \App\Research\Journals::html($article->body_markdown) !!}</div>
        </article>
        @if($edit)<details><summary>{{ __('Change the article') }}</summary><form method="post" action="{{ route('research.journals.step', [$journal, 'article', $article->id]) }}">@csrf @include('journals._article-fields', ['a' => $article])<button type="submit">{{ __('Save') }}</button></form>
            <form method="post" action="{{ route('research.journals.remove', ['articles', $article->id]) }}">@csrf<button type="submit">{{ __('Remove the article') }}</button></form></details>@endif
    @endif
    <h2>{{ __('Contents') }}</h2>
    @forelse($contents as $part)
        <h3>@if($part['issue']){{ trim(collect([$part['issue']->volume ? __('Volume :v', ['v' => $part['issue']->volume]) : null, $part['issue']->number ? __('Number :n', ['n' => $part['issue']->number]) : null, $part['issue']->title])->filter()->implode(', ')) }} <span class="meta">{{ $part['issue']->status->label($lang) }}@if($part['issue']->issue_date) · {{ $part['issue']->issue_date->format('j M Y') }}@endif</span>@else{{ __('Not yet in an issue') }}@endif</h3>
        @foreach($part['articles'] as $a)<p><a href="{{ route('research.journals.show', [$journal, 'article' => $a->id]) }}">{{ $a->title }}</a> <span class="meta">{{ $a->authors }} · {{ $a->status->label($lang) }}</span></p>@endforeach
        @if($edit && $part['issue'])<form method="post" action="{{ route('research.journals.remove', ['issues', $part['issue']->id]) }}">@csrf<button type="submit">{{ __('Remove the issue') }}</button></form>@endif
    @empty<p>{{ __('None.') }}</p>@endforelse
    @if($edit)
        <details><summary>{{ __('Add an issue') }}</summary><form method="post" action="{{ route('research.journals.step', [$journal, 'issue']) }}">@csrf
            <label for="ji-v">{{ __('Volume') }}</label> <input id="ji-v" name="volume" size="5"> <label for="ji-n">{{ __('Number') }}</label> <input id="ji-n" name="number" size="5">
            <label for="ji-t">{{ __('Title') }}</label> <input id="ji-t" name="title" size="30"> <label for="ji-d">{{ __('Date') }}</label> <input id="ji-d" name="issue_date" type="date">
            <label for="ji-s">{{ __('Status') }}</label> <select id="ji-s" name="status">@foreach($issueStatuses as $s)<option value="{{ $s->notation }}">{{ $s->label($lang) }}</option>@endforeach</select> <button type="submit">{{ __('Add') }}</button></form></details>
        <details><summary>{{ __('Add an article') }}</summary><form method="post" action="{{ route('research.journals.step', [$journal, 'article']) }}">@csrf @include('journals._article-fields', ['a' => null])<button type="submit">{{ __('Add') }}</button></form></details>
        <h2>{{ __('Settings') }}</h2><form method="post" action="{{ route('research.journals.step', [$journal, 'update']) }}">@csrf @include('journals._fields', ['j' => $journal])<button type="submit">{{ __('Save') }}</button></form>
        <form method="post" action="{{ route('research.journals.step', [$journal, 'delete']) }}">@csrf<button type="submit">{{ __('Delete the journal') }}</button></form>
    @endif
@endsection
