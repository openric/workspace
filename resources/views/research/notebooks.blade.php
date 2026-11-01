{{-- OpenRiC Workspace - one's notebooks (Heratio's notebooks list; roadmap phase 19d). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Notebooks'))
@section('content')
    <h1>{{ __('Notebooks') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @error('research')<p class="error" role="alert">{{ $message }}</p>@enderror
    @forelse($notebooks as $n)<p><a href="{{ route('research.notebooks.show', $n) }}">{{ $n->title }}</a> · {{ trans_choice(':count item|:count items', $n->items_count, ['count' => $n->items_count]) }} · <span class="meta">{{ __('Changed :date', ['date' => $n->updated_at->timezone(config('workspace.timezone'))->format('j M Y')]) }}</span>@if($n->summary)<br><span class="meta">{{ $n->summary }}</span>@endif</p>
    @empty<p>{{ __('None.') }}</p>@endforelse
    <details><summary>{{ __('New notebook') }}</summary>
        <form method="post" action="{{ route('research.notebooks.store') }}">@csrf
            <label for="nb-title">{{ __('Title') }}</label> <input id="nb-title" name="title" required>
            <label for="nb-sum">{{ __('Summary') }}</label> <input id="nb-sum" name="summary" size="40"> <button type="submit">{{ __('Create') }}</button></form>
    </details>
@endsection
