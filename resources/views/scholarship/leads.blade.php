{{-- OpenRiC Workspace - research leads to curate (roadmap phase 17d4), after Heratio's research-leads/admin (ahg-semantic-search). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Research leads'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Research leads') }}</h1>
    <p class="meta">{{ __($disclaimer) }}</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <form method="post" action="{{ route('scholarship.leads.generate') }}">@csrf <label for="lg-limit">{{ __('Promote the strongest discoveries, at most') }}</label> <input id="lg-limit" name="limit" type="number" min="1" max="200" value="25" style="width: 5rem">
        @unless($refusal)<label><input type="checkbox" name="enrich" value="1"> {{ __('Have a model write why each might matter') }}</label>@endunless <button type="submit">{{ __('Promote') }}</button></form>
    <p>@if($status === null)<strong>{{ __('All') }}</strong>@else<a href="{{ route('scholarship.leads') }}">{{ __('All') }}</a>@endif
        @foreach($statuses as $s) · @if($status === $s->notation)<strong>{{ $s->label($lang) }}</strong>@else<a href="{{ route('scholarship.leads', ['status' => $s->notation]) }}">{{ $s->label($lang) }}</a>@endif ({{ $counts[$s->notation] ?? 0 }})@endforeach</p>
    @forelse($leads as $l)
        <article style="border-top: 1px solid #ccc; padding: .5rem 0">
            <h2 style="font-size: 1.1rem"><a href="{{ route('scholarship.show', $l->record) }}">{{ $l->headline }}</a></h2>
            <p class="meta">{{ $l->status->label($lang) }} · {{ __(\App\Scholarship\Scholarship::band($l->confidence)['label']) }} ({{ $l->confidence }}) · {{ trans_choice(':count connection|:count connections', $l->connection_count, ['count' => $l->connection_count]) }}@if($l->ai_labelled) · {{ __('Drawn by AI') }}@endif</p>
            @if($l->lead_text)<p style="white-space: pre-line">{{ $l->lead_text }}</p>@endif
            <p><em>{{ $l->why_it_matters }}</em></p>
            <form method="post" action="{{ route('scholarship.leads.curate', [$l, 'published']) }}" style="display: inline">@csrf<button type="submit">{{ __('Publish') }}</button></form>
            <form method="post" action="{{ route('scholarship.leads.curate', [$l, 'dismissed']) }}" style="display: inline">@csrf<button type="submit">{{ __('Dismiss') }}</button></form>
            <form method="post" action="{{ route('scholarship.leads.curate', [$l, 'pending']) }}" style="display: inline">@csrf<button type="submit">{{ __('Back to pending') }}</button></form>
        </article>
    @empty<p>{{ __('None.') }}</p>@endforelse
@endsection
