{{-- OpenRiC Workspace - the assistant's answers readers flagged as wrong, for review (roadmap phase 17f, after Heratio's chatbot review queue). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Flagged answers'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Flagged answers') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p>{{ __('Answers of the catalogue\'s assistant that readers reported as wrong. An answer found wrong counts towards the AI register\'s monitor, which raises an incident after three in thirty days.') }}
        <a href="{{ route('ai.compliance') }}">{{ __('AI register and compliance') }}</a></p>
    @forelse($messages as $m)
        <article style="border: 1px solid var(--line); padding: .6rem .8rem; margin: .75rem 0">
            <p class="meta">{{ $m->flagged_at->timezone(config('workspace.timezone'))->format('Y-m-d H:i') }} · {{ $m->conversation->public ? __('Public catalogue') : __('Staff') }} · {{ $m->review?->label($lang) }}</p>
            <div style="white-space: pre-line">{{ $m->content }}</div>
            @if($m->flag_note)<p><strong>{{ __('The reader:') }}</strong> {{ $m->flag_note }}</p>@endif
            @if($m->sources)<ol>@foreach($m->sources as $s)<li>@if($s['url'])<a href="{{ $s['url'] }}">{{ $s['label'] }}</a>@else{{ $s['label'] }}@endif</li>@endforeach</ol>@endif
            @if($m->inference_id)<p><a href="{{ route('ai.inference', $m->inference_id) }}">{{ __('The inference behind it') }}</a></p>@endif
            <form method="post" action="{{ route('ai.answers.review', $m) }}" style="display: flex; gap: .5rem">@csrf
                <button type="submit" name="finding" value="right">{{ __('The answer was right') }}</button>
                <button type="submit" name="finding" value="wrong">{{ __('The answer was wrong') }}</button></form>
        </article>
    @empty
        <p>{{ __('None.') }}</p>
    @endforelse
@endsection
