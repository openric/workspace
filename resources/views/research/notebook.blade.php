{{-- OpenRiC Workspace - a notebook (Heratio's notebookShow; roadmap phase 19d): notes, pinned records and saved searches, in order, the pinned ones first. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $notebook->title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <p><a href="{{ route('research.notebooks.index') }}">{{ __('Notebooks') }}</a></p>
    <h1>{{ $notebook->title }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @error('research')<p class="error" role="alert">{{ $message }}</p>@enderror
    @if($notebook->summary)<p>{{ $notebook->summary }}</p>@endif
    @if($notebook->coverRecord)<p class="meta">{{ __('Cover') }}: <a href="{{ route('records.show', $notebook->coverRecord) }}">{{ \App\Relations\EntityKinds::label($notebook->coverRecord, $lang) }}</a></p>@endif
    @forelse($notebook->items as $i)
        <section style="border-top: 1px solid var(--line); padding: .3rem 0">
            <p>@if($i->pinned)<strong>{{ __('Pinned') }}</strong> · @endif<span class="meta">{{ $i->itemType->label($lang) }}</span>@if($i->title) · {{ $i->title }}@endif
                @if($i->sourceRecord) · <a href="{{ route('records.show', $i->sourceRecord) }}">{{ \App\Relations\EntityKinds::label($i->sourceRecord, $lang) }}</a>@endif
                @if($i->savedSearch) · <a href="{{ route('research.searches.step', [$i->savedSearch, 'run']) }}">{{ $i->savedSearch->name }}</a>@endif</p>
            @if($i->body)<p style="white-space: pre-wrap">{{ $i->body }}</p>@endif
            @if($i->ai_output_payload)<pre style="white-space: pre-wrap">{{ json_encode($i->ai_output_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>@endif
            <details><summary>{{ __('Change') }}</summary>
                <form method="post" action="{{ route('research.notebooks.item', [$i, 'update']) }}">@csrf
                    <label for="ni-title-{{ $loop->index }}">{{ __('Title') }}</label> <input id="ni-title-{{ $loop->index }}" name="title" value="{{ $i->title }}" size="40"><br>
                    <label for="ni-body-{{ $loop->index }}">{{ __('Text') }}</label><br><textarea id="ni-body-{{ $loop->index }}" name="body" rows="4" cols="70">{{ $i->body }}</textarea><br>
                    <label><input type="checkbox" name="pinned" value="1" @checked($i->pinned)> {{ __('Pinned') }}</label>
                    <label for="ni-order-{{ $loop->index }}">{{ __('Order') }}</label> <input id="ni-order-{{ $loop->index }}" name="sort_order" type="number" min="0" value="{{ $i->sort_order }}" style="width: 5rem">
                    <button type="submit">{{ __('Save') }}</button></form>
                <form method="post" action="{{ route('research.notebooks.item', [$i, 'remove']) }}">@csrf<button type="submit">{{ __('Remove') }}</button></form>
            </details>
        </section>
    @empty<p>{{ __('No item yet.') }}</p>@endforelse
    <h2>{{ __('Add') }}</h2>
    <form method="post" action="{{ route('research.notebooks.step', [$notebook, 'item']) }}">@csrf
        <label for="na-type">{{ __('Kind') }}</label> <select id="na-type" name="item_type">@foreach($types as $c)<option value="{{ $c->notation }}">{{ $c->label($lang) }}</option>@endforeach</select>
        <label for="na-title">{{ __('Title') }}</label> <input id="na-title" name="title" size="30"><br>
        <label for="na-body">{{ __('Text') }}</label><br><textarea id="na-body" name="body" rows="4" cols="70"></textarea><br>
        <label for="na-iri">{{ __('A record (its persistent IRI)') }}</label> <input id="na-iri" name="iri" size="40">
        @if($searches->isNotEmpty())<label for="na-search">{{ __('Saved search') }}</label> <select id="na-search" name="saved_search_id"><option value="">{{ __('None') }}</option>@foreach($searches as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select>@endif
        <label><input type="checkbox" name="pinned" value="1"> {{ __('Pinned') }}</label> <button type="submit">{{ __('Add') }}</button></form>
    <h2>{{ __('Settings') }}</h2>
    <form method="post" action="{{ route('research.notebooks.update', $notebook) }}">@csrf
        <label for="ns-title">{{ __('Title') }}</label> <input id="ns-title" name="title" required value="{{ $notebook->title }}">
        <label for="ns-sum">{{ __('Summary') }}</label> <input id="ns-sum" name="summary" size="40" value="{{ $notebook->summary }}"><br>
        <label for="ns-cover">{{ __('Cover record (its persistent IRI)') }}</label> <input id="ns-cover" name="cover_iri" size="40" value="{{ $notebook->coverRecord?->iri }}">
        <label for="ns-order">{{ __('Order') }}</label> <input id="ns-order" name="sort_order" type="number" min="0" value="{{ $notebook->sort_order }}" style="width: 5rem"> <button type="submit">{{ __('Save') }}</button></form>
    @if($notebook->promotedTo)<p>{{ __('Promoted to the project') }} <a href="{{ route('research.projects.show', $notebook->promotedTo) }}">{{ $notebook->promotedTo->title }}</a> · {{ $notebook->promoted_at->timezone(config('workspace.timezone'))->format('j M Y') }}</p>
    @else<form method="post" action="{{ route('research.notebooks.promote', $notebook) }}">@csrf<button type="submit">{{ __('Promote it to a research project') }}</button></form>@endif
    <form method="post" action="{{ route('research.notebooks.step', [$notebook, 'delete']) }}">@csrf<button type="submit">{{ __('Delete the notebook') }}</button></form>
@endsection
