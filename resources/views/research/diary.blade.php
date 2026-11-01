{{-- OpenRiC Workspace - the research diary (Heratio's research journal entries; roadmap phase 19d): one's entries and those the Workspace writes, with the time spent. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Research diary'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($e = $editing)
    <h1>{{ __('Research diary') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @error('research')<p class="error" role="alert">{{ $message }}</p>@enderror
    @if($errors->any() && ! $errors->has('research'))<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <form method="get" action="{{ route('research.diary.index') }}">
        <label for="dy-type">{{ __('Kind') }}</label> <select id="dy-type" name="type"><option value="">{{ __('All') }}</option>@foreach($types as $c)<option value="{{ $c->notation }}" @selected(($filters['type'] ?? null) === $c->notation)>{{ $c->label($lang) }}</option>@endforeach</select>
        <label for="dy-from">{{ __('From') }}</label> <input id="dy-from" name="from" type="date" value="{{ $filters['from'] ?? '' }}">
        <label for="dy-to">{{ __('To') }}</label> <input id="dy-to" name="to" type="date" value="{{ $filters['to'] ?? '' }}">
        <label for="dy-q">{{ __('Words or tags') }}</label> <input id="dy-q" name="q" value="{{ $filters['q'] ?? '' }}"> <button type="submit">{{ __('Show') }}</button></form>
    <p>{{ __('Time spent') }}: {{ intdiv($minutes, 60) }} {{ __('h') }} {{ $minutes % 60 }} {{ __('min') }}</p>
    @forelse($entries as $d)
        <article style="border-top: 1px solid var(--line); padding: .3rem 0">
            <p><strong>{{ $d->entry_date->format('j M Y') }}</strong> · <span class="meta">{{ $d->entryType->label($lang) }}</span>@if($d->title) · {{ $d->title }}@endif
                @if($d->time_spent_minutes) · {{ __(':count min', ['count' => $d->time_spent_minutes]) }}@endif @if(! $d->is_private) · {{ __('not private') }}@endif</p>
            @if($d->content !== $d->title)<p style="white-space: pre-wrap">{{ $d->content }}</p>@endif
            @if($d->tags)<p class="meta">{{ __('Tags') }}: {{ $d->tags }}</p>@endif
            @include('research._comments', ['subject' => $d, 'kind' => 'diary'])
            <p>@include('research._project-select', ['thing' => $d, 'kind' => 'diary']) <a href="{{ route('research.diary.index', ['edit' => $d->id]) }}#dy-form">{{ __('Change') }}</a>
                <form method="post" action="{{ route('research.diary.delete', $d) }}" style="display: inline">@csrf<button type="submit">{{ __('Delete') }}</button></form></p>
        </article>
    @empty<p>{{ __('No entry yet.') }}</p>@endforelse
    {{ $entries->links() }}
    <h2 id="dy-form">{{ $e ? __('Change the entry') : __('New entry') }}</h2>
    <form method="post" action="{{ $e ? route('research.diary.update', $e) : route('research.diary.store') }}">@csrf
        <label for="de-date">{{ __('Date') }}</label> <input id="de-date" name="entry_date" type="date" required value="{{ old('entry_date', $e?->entry_date->toDateString() ?? now(config('workspace.timezone'))->toDateString()) }}">
        @if(! $e || in_array($e->entryType->notation, \App\Research\Diary::WRITTEN, true))<label for="de-type">{{ __('Kind') }}</label> <select id="de-type" name="entry_type">@foreach($types->whereIn('notation', \App\Research\Diary::WRITTEN) as $c)<option value="{{ $c->notation }}" @selected(old('entry_type', $e?->entryType->notation) === $c->notation)>{{ $c->label($lang) }}</option>@endforeach</select>@endif
        <label for="de-title">{{ __('Title') }}</label> <input id="de-title" name="title" size="40" value="{{ old('title', $e?->title) }}"><br>
        <label for="de-content">{{ __('Text') }}</label><br><textarea id="de-content" name="content" rows="6" cols="80" required>{{ old('content', $e?->content) }}</textarea><br>
        <label for="de-time">{{ __('Time spent (minutes)') }}</label> <input id="de-time" name="time_spent_minutes" type="number" min="0" max="1440" value="{{ old('time_spent_minutes', $e?->time_spent_minutes) }}" style="width: 6rem">
        <label for="de-tags">{{ __('Tags') }}</label> <input id="de-tags" name="tags" size="30" value="{{ old('tags', $e?->tags) }}">
        <label><input type="checkbox" name="is_private" value="1" @checked(old('is_private', $e?->is_private ?? true))> {{ __('Private') }}</label>
        <button type="submit">{{ __('Save') }}</button> @if($e)<a href="{{ route('research.diary.index') }}">{{ __('Cancel') }}</a>@endif</form>
@endsection
