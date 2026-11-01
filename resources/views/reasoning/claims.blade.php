{{-- OpenRiC Workspace - a project's claim ledger (Heratio's claim ledger, assertions and assertion batch review; roadmap phase 19i). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Claims').': '.$project->title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @include('reasoning._nav')
    <h1>{{ __('Claims') }}</h1>
    <p>@foreach($statuses as $s)@if($counts[$s->notation] ?? 0)<a href="{{ route('research.reasoning.claims', [$project, 'status' => $s->notation]) }}">{{ $s->label($lang) }}</a> {{ $counts[$s->notation] }} · @endif @endforeach <a href="{{ route('research.reasoning.claims', $project) }}">{{ __('All') }}</a></p>
    @if($unsupported->isNotEmpty())<p class="error">{{ trans_choice('{1} :count claim has no evidence.|[2,*] :count claims have no evidence.', $unsupported->count(), ['count' => $unsupported->count()]) }}</p>@endif
    @if($dependent->isNotEmpty())<p class="error">{{ trans_choice('{1} :count claim rests on one source alone.|[2,*] :count claims rest on one source alone.', $dependent->count(), ['count' => $dependent->count()]) }}</p>@endif
    <form method="get" action="{{ route('research.reasoning.claims', $project) }}"><label for="cl-q">{{ __('Words') }}</label> <input id="cl-q" name="q" value="{{ $filters['q'] ?? '' }}">
        <label for="cl-type">{{ __('Kind') }}</label> <select id="cl-type" name="type"><option value="">{{ __('All') }}</option>@foreach($types as $t)<option value="{{ $t->notation }}" @selected(($filters['type'] ?? null) === $t->notation)>{{ $t->label($lang) }}</option>@endforeach</select>
        <button type="submit">{{ __('Show') }}</button></form>
    @forelse($claims as $c)
        <p style="border-top: 1px solid var(--line); padding-top: .3rem"><a href="{{ route('research.reasoning.claim', [$project, $c]) }}">{{ \Illuminate\Support\Str::limit($c->object_value, 160) }}</a><br>
            <span class="meta">{{ $c->claimType->label($lang) }} · {{ $c->status->label($lang) }}@if($c->confidenceLevel) · {{ $c->confidenceLevel->label($lang) }}@endif @if($c->confidence !== null) ({{ number_format($c->confidence, 2) }})@endif · {{ trans_choice(':count piece of evidence|:count pieces of evidence', $c->evidence_count, ['count' => $c->evidence_count]) }}
                @if($c->subject_label) · {{ $c->subject_label }} {{ $c->predicate }} {{ $c->object_label }}@endif · {{ $c->user?->name }}</span></p>
    @empty<p>{{ __('None.') }}</p>@endforelse
    @if($level >= 2)
        <h2>{{ __('New claim') }}</h2>
        <form method="post" action="{{ route('research.reasoning.claims.store', $project) }}">@csrf @include('reasoning._claim-fields', ['c' => null, 'x' => 'cn'])<button type="submit">{{ __('Add') }}</button></form>
    @endif
    @if($level >= 3 && $proposed->isNotEmpty())
        <h2>{{ __('Proposed assertions to review') }}</h2>
        <form method="post" action="{{ route('research.reasoning.claims.review', $project) }}">@csrf
            @foreach($proposed as $p)<label style="display: block"><input type="checkbox" name="ids[]" value="{{ $p->id }}"> {{ \Illuminate\Support\Str::limit($p->object_value, 160) }}</label>@endforeach
            <label for="cl-rs">{{ __('Mark them') }}</label> <select id="cl-rs" name="status">@foreach($statuses->whereIn('notation', ['verified', 'disputed', 'retracted', 'supported', 'rejected']) as $s)<option value="{{ $s->notation }}">{{ $s->label($lang) }}</option>@endforeach</select>
            <button type="submit">{{ __('Apply') }}</button></form>
    @endif
@endsection
