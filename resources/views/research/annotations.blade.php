{{-- OpenRiC Workspace - one's annotations (Heratio's annotations list and its search; roadmap phase 19d). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('My annotations'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('My annotations') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @error('research')<p class="error" role="alert">{{ $message }}</p>@enderror
    <form method="get" action="{{ route('research.annotations.index') }}"><label for="ma-q">{{ __('Words or tags') }}</label> <input id="ma-q" name="q" value="{{ request('q') }}">
        <label for="ma-type">{{ __('Kind') }}</label> <select id="ma-type" name="type"><option value="">{{ __('All') }}</option>@foreach($types as $c)<option value="{{ $c->notation }}" @selected(request('type') === $c->notation)>{{ $c->label($lang) }}</option>@endforeach</select> <button type="submit">{{ __('Search') }}</button></form>
    @forelse($annotations as $n)
        @php($subject = $service->subject($n))
        @php($kind = \App\Relations\EntityKinds::get(\App\Relations\EntityKinds::of($subject))['segment'])
        <article style="border-top: 1px solid var(--line); padding: .3rem 0">
            <p><a href="{{ route('research.studio', [$kind, $subject->getKey()]) }}">{{ \App\Relations\EntityKinds::label($subject, $lang) }}</a> · <strong>{{ $n->annotationType->label($lang) }}</strong>@if($n->title): {{ $n->title }}@endif
                <span class="meta">{{ $n->visibility->label($lang) }} · {{ $n->created_at->timezone(config('workspace.timezone'))->format('j M Y') }}</span></p>
            @if($n->content)<p style="white-space: pre-wrap">{{ \Illuminate\Support\Str::limit($n->content, 400) }}</p>@endif
            @if($n->tags)<p class="meta">{{ __('Tags') }}: {{ $n->tags }}</p>@endif
        </article>
    @empty<p>{{ __('None.') }} {{ __('Annotate a record from its page.') }}</p>@endforelse
@endsection
