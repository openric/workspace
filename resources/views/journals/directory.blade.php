{{-- OpenRiC Workspace - the directory of journals to aim at (Heratio's target journals; roadmap phase 19g). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Journals to aim at'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($j = $editing)
    <p><a href="{{ route('research.journals.index') }}">{{ __('Research journals') }}</a></p>
    <h1>{{ __('Journals to aim at') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @error('research')<p class="error" role="alert">{{ $message }}</p>@enderror
    <form method="get" action="{{ route('research.journals.directory') }}"><label for="jd-q">{{ __('Words') }}</label> <input id="jd-q" name="q" value="{{ request('q') }}">
        <label for="jd-m">{{ __('Accreditation') }}</label> <input id="jd-m" name="market" size="4" value="{{ request('market') }}"> <label><input type="checkbox" name="open_access" value="1" @checked(request('open_access'))> {{ __('Open access') }}</label> <button type="submit">{{ __('Show') }}</button></form>
    @forelse($journals as $t)
        <p><strong>@if($t->homepage_url)<a href="{{ $t->homepage_url }}" rel="noopener noreferrer">{{ $t->title }}</a>@else{{ $t->title }}@endif</strong> <span class="meta">{{ $t->publisher }}@if($t->accreditation) · {{ $t->accreditation }}@endif @if($t->open_access) · {{ __('open access') }}@endif @if($t->reference_style) · {{ $t->reference_style }}@endif @if($t->max_words) · {{ __('up to :n words', ['n' => $t->max_words]) }}@endif · {{ $t->status->label($lang) }}</span>
            @if($t->subject_scope)<br>{{ $t->subject_scope }}@endif @if($t->notes)<br><small>{{ $t->notes }}</small>@endif @if($run) <a href="{{ route('research.journals.directory', ['edit' => $t->id]) }}">{{ __('Change') }}</a>@endif</p>
    @empty<p>{{ __('None.') }}</p>@endforelse
    @if($run)
        <form method="post" action="{{ route('research.journals.directory.save') }}">@csrf<input type="hidden" name="seed" value="1"><button type="submit">{{ __('Add the South African journals accredited by the DHET') }}</button></form>
        <h2>{{ $j ? __('Change :title', ['title' => $j->title]) : __('Add a journal') }}</h2>
        <form method="post" action="{{ route('research.journals.directory.save', $j) }}">@csrf
            <label for="td-title">{{ __('Title') }}</label> <input id="td-title" name="title" required size="40" value="{{ $j?->title }}">
            <label for="td-pub">{{ __('Publisher') }}</label> <input id="td-pub" name="publisher" size="25" value="{{ $j?->publisher }}"><br>
            <label for="td-issn">ISSN</label> <input id="td-issn" name="issn" size="9" value="{{ $j?->issn }}"> <label for="td-eissn">e-ISSN</label> <input id="td-eissn" name="eissn" size="9" value="{{ $j?->eissn }}">
            <label for="td-acc">{{ __('Accreditation') }}</label> <input id="td-acc" name="accreditation" size="25" value="{{ $j?->accreditation }}"> <label for="td-mkt">{{ __('Market') }}</label> <input id="td-mkt" name="accreditation_market" size="4" value="{{ $j?->accreditation_market }}"><br>
            <label for="td-home">{{ __('Web site') }}</label> <input id="td-home" name="homepage_url" type="url" size="30" value="{{ $j?->homepage_url }}"> <label for="td-sub">{{ __('Where to submit') }}</label> <input id="td-sub" name="submission_url" type="url" size="30" value="{{ $j?->submission_url }}"><br>
            <label for="td-style">{{ __('Reference style') }}</label> <input id="td-style" name="reference_style" size="12" value="{{ $j?->reference_style }}"> <label for="td-max">{{ __('Most words') }}</label> <input id="td-max" name="max_words" type="number" min="1" style="width: 6rem" value="{{ $j?->max_words }}">
            <label for="td-amax">{{ __('Most words in the abstract') }}</label> <input id="td-amax" name="abstract_max_words" type="number" min="1" style="width: 6rem" value="{{ $j?->abstract_max_words }}">
            <label for="td-peer">{{ __('Peer review') }}</label> <input id="td-peer" name="peer_review" size="15" value="{{ $j?->peer_review }}"> <label><input type="checkbox" name="open_access" value="1" @checked($j?->open_access)> {{ __('Open access') }}</label><br>
            <label for="td-apc">{{ __('Charges') }}</label> <input id="td-apc" name="apc_amount" size="12" value="{{ $j?->apc_amount }}"> <label for="td-turn">{{ __('Turnaround') }}</label> <input id="td-turn" name="turnaround" size="15" value="{{ $j?->turnaround }}">
            <label for="td-lang">{{ __('Languages') }}</label> <input id="td-lang" name="languages" size="15" value="{{ $j?->languages }}"> <label for="td-types">{{ __('Kinds of article') }}</label> <input id="td-types" name="article_types" size="20" value="{{ $j?->article_types }}"><br>
            <label for="td-scope">{{ __('Scope') }}</label><br><textarea id="td-scope" name="subject_scope" rows="2" cols="80">{{ $j?->subject_scope }}</textarea><br>
            <label for="td-struct">{{ __('How articles are laid out') }}</label><br><textarea id="td-struct" name="structure_notes" rows="2" cols="80">{{ $j?->structure_notes }}</textarea><br>
            <label for="td-notes">{{ __('Notes') }}</label> <input id="td-notes" name="notes" size="60" value="{{ $j?->notes }}">
            <label for="td-status">{{ __('Status') }}</label> <select id="td-status" name="status">@foreach($statuses as $s)<option value="{{ $s->notation }}" @selected(($j?->status->notation ?? 'active') === $s->notation)>{{ $s->label($lang) }}</option>@endforeach</select><br>
            <button type="submit">{{ __('Save') }}</button></form>
    @endif
@endsection
