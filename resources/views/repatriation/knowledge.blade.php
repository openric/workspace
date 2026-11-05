{{-- OpenRiC Workspace - knowledge contributed about repatriation claims, to review (roadmap phase 17d2), after Heratio's repatriation-knowledge/moderate (ahg-semantic-search). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Knowledge to review'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Knowledge to review') }}</h1>
    <p class="meta">{{ __(\App\Repatriation\Knowledge::DISCLAIMER) }}</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p>@foreach($statuses as $s)@if($status === $s->notation)<strong>{{ $s->label($lang) }}</strong>@else<a href="{{ route('repatriation.knowledge', ['status' => $s->notation]) }}">{{ $s->label($lang) }}</a>@endif ({{ $counts[$s->notation] ?? 0 }})@if(! $loop->last) · @endif @endforeach</p>
    @forelse($contributions as $k)
        <article style="border-top: 1px solid #ccc; padding: .5rem 0">
            <p><strong>{{ $k->type->label($lang) }}</strong> · {{ $k->contributor_name ?: __('Anonymous contributor') }} · {{ $k->created_at->format('Y-m-d') }}
                · <a href="{{ route('repatriation.dialogue', $k->claim) }}">{{ $k->claim->claimant_community ?: __('Unnamed') }}</a> · {{ \App\Relations\EntityKinds::label($k->claim->record, $lang) }}</p>
            <p style="white-space: pre-line">{{ $k->body }}</p>
            @if($k->source)<p><small>{{ $k->source }}</small></p>@endif
            <form method="post" action="{{ route('repatriation.moderate', $k) }}">@csrf
                @foreach($statuses->where('notation', '!=', $status) as $s)<button type="submit" name="status" value="{{ $s->notation }}">{{ $s->label($lang) }}</button> @endforeach
            </form>
        </article>
    @empty<p>{{ __('None.') }}</p>@endforelse
@endsection
