{{-- OpenRiC Workspace - one's bibliographies (Heratio's bibliographies list; roadmap phase 19c). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Bibliographies'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Bibliographies') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @error('research')<p class="error" role="alert">{{ $message }}</p>@enderror
    @forelse($bibliographies as $b)<p><a href="{{ route('research.bibliographies.show', $b) }}">{{ $b->name }}</a> · {{ $b->citationStyle->label($lang) }} · {{ trans_choice(':count entry|:count entries', $b->entries_count, ['count' => $b->entries_count]) }}@if($b->is_public) · {{ __('shared') }}@endif</p>
    @empty<p>{{ __('None.') }}</p>@endforelse
    <details><summary>{{ __('New bibliography') }}</summary>
        <form method="post" action="{{ route('research.bibliographies.store') }}">@csrf
            <label for="bb-name">{{ __('Name') }}</label> <input id="bb-name" name="name" required> <label for="bb-style">{{ __('Citation style') }}</label> <select id="bb-style" name="citation_style">@foreach($styles as $s)<option value="{{ $s->notation }}">{{ $s->label($lang) }}</option>@endforeach</select>
            <label for="bb-desc">{{ __('Description') }}</label> <input id="bb-desc" name="description" size="40"> <button type="submit">{{ __('Create') }}</button></form>
    </details>
@endsection
