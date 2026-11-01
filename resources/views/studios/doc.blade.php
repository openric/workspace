{{-- OpenRiC Workspace - a document of the writing studio: its sections, claims and works pulled in, and its versions (Heratio's writing studio; roadmap phase 19g). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $doc->title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @include('studios._nav')
    <h1>{{ $doc->title }}</h1>
    <p>{{ $doc->docType->label($lang) }} · {{ $doc->status->label($lang) }} · <a href="{{ route('research.studios.doc', [$doc, 'format' => 'markdown']) }}">{{ __('As Markdown') }}</a></p>
    @foreach($doc->sections as $s)
        <section style="border-top: 1px solid var(--line); padding: .3rem 0">
            @if($s->heading)<h2>{{ $s->heading }}</h2>@endif
            @if($s->body)<p style="white-space: pre-wrap">{{ $s->body }}</p>@endif
            @if($level >= 2)
                <details><summary>{{ __('Change') }}</summary><form method="post" action="{{ route('research.studios.writing.step', [$project, 'update-section', $s->id]) }}">@csrf
                    <label for="ws-h{{ $loop->index }}">{{ __('Heading') }}</label> <input id="ws-h{{ $loop->index }}" name="heading" size="50" value="{{ $s->heading }}"><br>
                    <textarea name="body" rows="10" cols="90" aria-label="{{ __('Text') }}">{{ $s->body }}</textarea><br><button type="submit">{{ __('Save') }}</button></form>
                    <form method="post" action="{{ route('research.studios.writing.step', [$project, 'remove-section', $s->id]) }}">@csrf<button type="submit">{{ __('Remove the section') }}</button></form></details>
                @if($claims->isNotEmpty() || $entries->isNotEmpty())<form method="post" action="{{ route('research.studios.writing.step', [$project, 'pull', $s->id]) }}">@csrf
                    <label for="wp{{ $loop->index }}">{{ __('Bring in') }}</label> <select id="wp{{ $loop->index }}" name="pull_id">@foreach($claims as $c)<option value="{{ $c->id }}" data-kind="claim">{{ __('Claim') }}: {{ \Illuminate\Support\Str::limit($c->object_value, 70) }}</option>@endforeach
                        @foreach($entries as $e)<option value="{{ $e->id }}" data-kind="source">{{ __('Work') }}: {{ \Illuminate\Support\Str::limit(trim($e->authors.' '.$e->title), 70) }}</option>@endforeach</select>
                    <input type="hidden" name="kind" value="claim"><button type="submit" onclick="this.form.kind.value = this.form.pull_id.selectedOptions[0].dataset.kind">{{ __('Add to the section') }}</button></form>@endif
            @endif
        </section>
    @endforeach
    @if($level >= 2)
        <h2>{{ __('Add a section') }}</h2>
        <form method="post" action="{{ route('research.studios.writing.step', [$project, 'section', $doc->id]) }}">@csrf<label for="wn-h">{{ __('Heading') }}</label> <input id="wn-h" name="heading" size="50"><br>
            <textarea name="body" rows="6" cols="90" aria-label="{{ __('Text') }}"></textarea><br><button type="submit">{{ __('Add') }}</button></form>
        <h2>{{ __('Versions') }}</h2>
        <form method="post" action="{{ route('research.studios.writing.step', [$project, 'version', $doc->id]) }}">@csrf<label for="wv-note">{{ __('Note') }}</label> <input id="wv-note" name="note" size="40"> <button type="submit">{{ __('Keep this version') }}</button></form>
    @endif
    @foreach($doc->versions as $v)<p class="meta">{{ __('Version :n', ['n' => $v->version_no]) }} · {{ $v->created_at->timezone(config('workspace.timezone'))->format('j M Y H:i') }} · {{ $v->createdBy?->name }}@if($v->note) · {{ $v->note }}@endif · {{ trans_choice(':count section|:count sections', count($v->snapshot['sections'] ?? []), ['count' => count($v->snapshot['sections'] ?? [])]) }}</p>@endforeach
    @if($level >= 2)<h2>{{ __('Settings') }}</h2><form method="post" action="{{ route('research.studios.writing.step', [$project, 'doc', $doc->id]) }}">@csrf
        <label for="wd-t">{{ __('Title') }}</label> <input id="wd-t" name="title" required size="40" value="{{ $doc->title }}">
        <label for="wd-k">{{ __('Kind') }}</label> <select id="wd-k" name="doc_type">@foreach($types as $t)<option value="{{ $t->notation }}" @selected($doc->docType->notation === $t->notation)>{{ $t->label($lang) }}</option>@endforeach</select>
        <label for="wd-s">{{ __('Status') }}</label> <select id="wd-s" name="status">@foreach($statuses as $t)<option value="{{ $t->notation }}" @selected($doc->status->notation === $t->notation)>{{ $t->label($lang) }}</option>@endforeach</select> <button type="submit">{{ __('Save') }}</button></form>@endif
@endsection
