{{-- OpenRiC Workspace - a project's hypotheses and their evidence (Heratio's hypotheses; roadmap phase 19i). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Hypotheses').': '.$project->title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @include('reasoning._nav')
    <h1>{{ __('Hypotheses') }}</h1>
    @forelse($hypotheses as $h)
        <section style="border-top: 1px solid var(--line); padding: .3rem 0">
            <p><strong>{{ $h->statement }}</strong> <span class="meta">{{ $h->status->label($lang) }} · {{ trans_choice(':count piece of evidence|:count pieces of evidence', $h->evidence_count, ['count' => $h->evidence_count]) }} · {{ $h->user?->name }}@if($h->tags) · {{ $h->tags }}@endif</span></p>
            @foreach($h->evidence as $e)@php($d = $service->describe($e, auth()->user()))
                <p style="margin-left: 1.5rem">{{ $e->relationship->label($lang) }} · <span class="meta">{{ $e->sourceType->label($lang) }}</span> · @if($d['url'])<a href="{{ $d['url'] }}">{{ $d['label'] }}</a>@else{{ $d['label'] }}@endif @if($e->confidence !== null) ({{ number_format($e->confidence, 2) }})@endif @if($e->note) · {{ $e->note }}@endif
                    @if($level >= 3 || $level >= 2 && $e->added_by_id === auth()->id())<form method="post" action="{{ route('research.reasoning.hypotheses.evidence.remove', $e) }}" style="display: inline">@csrf<button type="submit">{{ __('Remove') }}</button></form>@endif</p>
            @endforeach
            @if($level >= 2)
                @include('reasoning._evidence-form', ['action' => route('research.reasoning.hypotheses.step', [$h, 'evidence']), 'x' => 'he'.$loop->index, 'confidence' => true])
                <details><summary>{{ __('Change') }}</summary><form method="post" action="{{ route('research.reasoning.hypotheses.save', [$project, $h]) }}">@csrf
                    <textarea name="statement" rows="2" cols="80" required aria-label="{{ __('Hypothesis') }}">{{ $h->statement }}</textarea><br>
                    <label for="hs-{{ $loop->index }}">{{ __('Status') }}</label> <select id="hs-{{ $loop->index }}" name="status">@foreach($statuses as $s)<option value="{{ $s->notation }}" @selected($h->status->notation === $s->notation)>{{ $s->label($lang) }}</option>@endforeach</select>
                    <label for="ht-{{ $loop->index }}">{{ __('Tags') }}</label> <input id="ht-{{ $loop->index }}" name="tags" value="{{ $h->tags }}"> <button type="submit">{{ __('Save') }}</button></form>
                    @if($level >= 3 || $h->user_id === auth()->id())<form method="post" action="{{ route('research.reasoning.hypotheses.step', [$h, 'delete']) }}">@csrf<button type="submit">{{ __('Delete') }}</button></form>@endif</details>
            @endif
        </section>
    @empty<p>{{ __('None.') }}</p>@endforelse
    @if($level >= 2)
        <h2>{{ __('New hypothesis') }}</h2>
        <form method="post" action="{{ route('research.reasoning.hypotheses.save', $project) }}">@csrf<textarea name="statement" rows="2" cols="80" required aria-label="{{ __('Hypothesis') }}"></textarea><br>
            <label for="hn-tags">{{ __('Tags') }}</label> <input id="hn-tags" name="tags"> <button type="submit">{{ __('Add') }}</button></form>
    @endif
@endsection
