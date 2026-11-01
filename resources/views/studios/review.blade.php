{{-- OpenRiC Workspace - a project's review studio (Heratio's review studio; roadmap phase 19g): reviewers' comments on the project or its claims. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Review studio').': '.$project->title)
@section('content')
    @include('studios._nav')
    <h1>{{ __('Review studio') }}</h1>
    @forelse($comments as $c)
        <section style="border-top: 1px solid var(--line); padding: .3rem 0">
            <p>@if($c->claim)<span class="meta">{{ __('On the claim') }}: {{ \Illuminate\Support\Str::limit($c->claim->object_value, 80) }}</span><br>@endif<span style="white-space: pre-wrap">{{ $c->body }}</span>
                <span class="meta">{{ $c->author?->name }} · {{ $c->created_at->timezone(config('workspace.timezone'))->format('j M Y') }}@if($c->resolved) · {{ __('resolved') }}@endif</span>
                <form method="post" action="{{ route('research.studios.review.step', [$project, $c]) }}" style="display: inline">@csrf<button type="submit">{{ $c->resolved ? __('Open again') : __('Resolve') }}</button></form></p>
            @foreach($c->replies as $r)<p style="margin-left: 1.5rem"><span class="meta">{{ $r->author?->name }}</span> {{ $r->body }}</p>@endforeach
            <form method="post" action="{{ route('research.studios.review.step', $project) }}" style="margin-left: 1.5rem">@csrf<input type="hidden" name="thread_id" value="{{ $c->id }}"><input name="body" size="60" required aria-label="{{ __('Reply') }}"> <button type="submit">{{ __('Reply') }}</button></form>
        </section>
    @empty<p>{{ __('None.') }}</p>@endforelse
    <h2>{{ __('Comment') }}</h2>
    <form method="post" action="{{ route('research.studios.review.step', $project) }}">@csrf
        <label for="rv-claim">{{ __('On the claim') }}</label> <select id="rv-claim" name="claim_id"><option value="">{{ __('The project as a whole') }}</option>@foreach($claims as $c)<option value="{{ $c->id }}">{{ \Illuminate\Support\Str::limit($c->object_value, 80) }}</option>@endforeach</select><br>
        <textarea name="body" rows="3" cols="80" required aria-label="{{ __('Comment') }}"></textarea><br><button type="submit">{{ __('Comment') }}</button></form>
@endsection
