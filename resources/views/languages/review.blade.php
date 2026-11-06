{{-- OpenRiC Workspace - glossary entries and transcriptions people contributed, to review (roadmap phase 17d4), after Heratio's language moderation (ahg-semantic-search). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Language contributions to review'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Language contributions to review') }}</h1>
    <p class="meta">{{ __($disclaimer) }}</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p>@foreach($statuses as $s)@if($status === $s->notation)<strong>{{ $s->label($lang) }}</strong>@else<a href="{{ route('languages.review', ['status' => $s->notation]) }}">{{ $s->label($lang) }}</a>@endif ({{ $counts[$s->notation] ?? 0 }})@if(! $loop->last) · @endif @endforeach</p>
    <h2>{{ __('Transcriptions, corrections, translations and notes') }}</h2>
    @forelse($contributions as $c)
        <article style="border-top: 1px solid #ccc; padding: .5rem 0">
            <p><strong>{{ $c->type->label($lang) }}</strong> · {{ \App\Language\Corpus::label($c->language) }} · <a href="{{ route('records.show', $c->record) }}">{{ \App\Relations\EntityKinds::label($c->record, $lang) }}</a> · {{ $c->contributor_name ?: __('Anonymous contributor') }} · {{ $c->created_at->format('Y-m-d') }}</p>
            <p style="white-space: pre-line">{{ $c->body }}</p>@if($c->source)<p><small>{{ $c->source }}</small></p>@endif
            <form method="post" action="{{ route('languages.moderate.contribution', $c) }}">@csrf @foreach($statuses->where('notation', '!=', $status) as $s)<button type="submit" name="status" value="{{ $s->notation }}">{{ $s->label($lang) }}</button> @endforeach</form>
        </article>
    @empty<p>{{ __('None.') }}</p>@endforelse
    <h2>{{ __('Glossary entries') }}</h2>
    @forelse($glossary as $g)
        <article style="border-top: 1px solid #ccc; padding: .5rem 0">
            <p><strong>{{ $g->term }}</strong> · {{ \App\Language\Corpus::label($g->language) }} · {{ $g->contributor_name ?: __('Anonymous contributor') }} · {{ $g->created_at->format('Y-m-d') }}</p>
            <p style="white-space: pre-line">{{ $g->meaning }}</p>@if($g->usage_example)<p><em>{{ $g->usage_example }}</em></p>@endif @if($g->source)<p><small>{{ $g->source }}</small></p>@endif
            <form method="post" action="{{ route('languages.moderate.glossary', $g) }}">@csrf @foreach($statuses->where('notation', '!=', $status) as $s)<button type="submit" name="status" value="{{ $s->notation }}">{{ $s->label($lang) }}</button> @endforeach</form>
        </article>
    @empty<p>{{ __('None.') }}</p>@endforelse
@endsection
