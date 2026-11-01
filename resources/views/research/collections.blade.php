{{-- OpenRiC Workspace - one's collections (Heratio's collections list; roadmap phase 19d). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Collections'))
@section('content')
    <h1>{{ __('Collections') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @error('research')<p class="error" role="alert">{{ $message }}</p>@enderror
    @forelse($collections as $c)<p><a href="{{ route('research.collections.show', $c) }}">{{ $c->name }}</a> · {{ trans_choice(':count item|:count items', $c->items_count, ['count' => $c->items_count]) }}@if($c->is_public) · {{ __('shared') }}@endif @if($c->description)<br><span class="meta">{{ $c->description }}</span>@endif</p>
    @empty<p>{{ __('None.') }} {{ __('Add records to a collection from their pages.') }}</p>@endforelse
    <details><summary>{{ __('New collection') }}</summary>
        <form method="post" action="{{ route('research.collections.store') }}">@csrf
            <label for="rc-name">{{ __('Name') }}</label> <input id="rc-name" name="name" required>
            <label for="rc-desc">{{ __('Description') }}</label> <input id="rc-desc" name="description" size="40"> <button type="submit">{{ __('Create') }}</button></form>
    </details>
@endsection
