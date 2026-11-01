{{-- OpenRiC Workspace - one's submissions (PSIS's researcher dashboard and submissions; roadmap phase 19e). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('My submissions'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('My submissions') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @error('research')<p class="error" role="alert">{{ $message }}</p>@enderror
    @if($errors->any() && ! $errors->has('research'))<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    @if($reviewer)<p><a href="{{ route('research.submissions.pending') }}">{{ __('Submissions to review') }}</a></p>@endif
    <p>{{ __('Descriptions you write for the catalogue, and notes on its records, go to the reviewers; what they approve is published.') }}</p>
    <form method="get" action="{{ route('research.submissions.index') }}"><label for="sb-status">{{ __('Status') }}</label> <select id="sb-status" name="status"><option value="">{{ __('All') }}</option>@foreach($statuses as $c)<option value="{{ $c->notation }}" @selected($status === $c->notation)>{{ $c->label($lang) }} ({{ $stats[$c->notation] ?? 0 }})</option>@endforeach</select> <button type="submit">{{ __('Show') }}</button></form>
    @forelse($submissions as $s)<p><a href="{{ route('research.submissions.show', $s) }}">{{ $s->title }}</a> · {{ $s->status->label($lang) }} · {{ trans_choice(':count description|:count descriptions', $s->total_items, ['count' => $s->total_items]) }} · <span class="meta">{{ $s->updated_at->timezone(config('workspace.timezone'))->format('j M Y') }}</span></p>
    @empty<p>{{ __('None.') }}</p>@endforelse
    <h2>{{ __('New submission') }}</h2>
    <form method="post" action="{{ route('research.submissions.store') }}">@csrf @include('submissions._fields', ['s' => null])<button type="submit">{{ __('Create') }}</button></form>
    @if($collections->isNotEmpty())
        <details><summary>{{ __('From one of your collections') }}</summary>
            <form method="post" action="{{ route('research.submissions.from-collection') }}">@csrf<label for="sb-coll">{{ __('Collection') }}</label> <select id="sb-coll" name="collection_id">@foreach($collections as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select>
                <span class="meta">{{ __('Each record of it becomes a note, with your notes on it.') }}</span> <button type="submit">{{ __('Create') }}</button></form>
        </details>
    @endif
    <details><summary>{{ __('From an exchange file made offline') }}</summary>
        <form method="post" action="{{ route('research.submissions.import') }}" enctype="multipart/form-data">@csrf
            <label for="sb-file">{{ __('Exchange file (JSON)') }}</label> <input id="sb-file" name="exchange_file" type="file" accept=".json,application/json" required>
            <label for="sb-irepo">{{ __('Repository') }}</label> <select id="sb-irepo" name="repository_id"><option value="">{{ __('None') }}</option>@foreach($repositories as $r)<option value="{{ $r->id }}">{{ \App\Relations\EntityKinds::label($r, $lang) }}</option>@endforeach</select>
            <button type="submit">{{ __('Import') }}</button></form>
    </details>
@endsection
