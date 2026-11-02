{{-- OpenRiC Workspace - the researcher's inbox (Heratio's quick capture inbox; roadmap phase 19j). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Inbox'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Inbox') }}</h1>
    @include('research._head')
    <form method="post" action="{{ route('research.inbox.capture') }}" enctype="multipart/form-data">@csrf
        <label for="i-title">{{ __('Title') }}</label> <input id="i-title" name="title" size="40"> <label for="i-url">{{ __('Web address') }}</label> <input id="i-url" name="source_url" type="url" size="40"><br>
        <label for="i-body">{{ __('Note') }}</label><br><textarea id="i-body" name="body" rows="3" cols="80"></textarea><br>
        <label for="i-file">{{ __('File') }}</label> <input id="i-file" name="file" type="file"> <button type="submit">{{ __('Capture') }}</button></form>
    <p>@foreach(['inbox' => __('In the inbox'), 'triaged' => __('Sorted'), 'archived' => __('Archived')] as $s => $l)<a href="{{ route('research.inbox', ['status' => $s]) }}" @if($status === $s) aria-current="page" @endif>{{ $l }} ({{ $counts[$s] ?? 0 }})</a>@if(! $loop->last) · @endif @endforeach</p>
    @forelse($items as $item)
        <article style="border-top: 1px solid var(--line); padding: .3rem 0">
            <p><strong>{{ $item->title ?? $item->kind->label($lang) }}</strong> <span class="meta">{{ $item->kind->label($lang) }} · {{ $item->origin->label($lang) }} · {{ $item->created_at->timezone(config('workspace.timezone'))->format('j M Y H:i') }}@if($item->project) · <a href="{{ route('research.projects.show', $item->project) }}">{{ $item->project->title }}</a>@endif</span></p>
            @if($item->body)<p style="white-space: pre-wrap">{{ $item->body }}</p>@endif
            @if($item->source_url)<p><a href="{{ $item->source_url }}" rel="noopener noreferrer">{{ $item->source_url }}</a></p>@endif
            @if($item->file)<p>{{ __('File') }}: {{ $item->file->original_name }}</p>@endif
            <div>@if($status === 'inbox')<form method="post" action="{{ route('research.inbox.step', [$item, 'project']) }}" style="display: inline">@csrf<label for="ip-{{ $loop->index }}">{{ __('Sort into') }}</label> <select id="ip-{{ $loop->index }}" name="project_id">@foreach($projects as $p)<option value="{{ $p->id }}">{{ $p->title }}</option>@endforeach</select> <button type="submit">{{ __('Sort') }}</button></form>
                <form method="post" action="{{ route('research.inbox.step', [$item, 'sort']) }}" style="display: inline">@csrf<button type="submit">{{ __('Mark sorted') }}</button></form>
                <form method="post" action="{{ route('research.inbox.step', [$item, 'archive']) }}" style="display: inline">@csrf<button type="submit">{{ __('Archive') }}</button></form>
                @else<form method="post" action="{{ route('research.inbox.step', [$item, 'restore']) }}" style="display: inline">@csrf<button type="submit">{{ __('Back to the inbox') }}</button></form>@endif
                <form method="post" action="{{ route('research.inbox.step', [$item, 'delete']) }}" style="display: inline">@csrf<button type="submit">{{ __('Delete') }}</button></form></div>
        </article>
    @empty
        <p>{{ __('Nothing here.') }}</p>
    @endforelse
@endsection
