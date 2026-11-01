{{-- OpenRiC Workspace - a research workspace (Heratio's viewWorkspace and workspace files; roadmap phase 19d): its members and invitations, resources, files and the diary entries its members share. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $workspace->name)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <p><a href="{{ route('research.workspaces.index') }}">{{ __('Research workspaces') }}</a></p>
    <h1>{{ $workspace->name }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @error('research')<p class="error" role="alert">{{ $message }}</p>@enderror
    @if($errors->any() && ! $errors->has('research'))<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ $workspace->visibility->label($lang) }} · {{ __('Owner') }}: {{ $workspace->owner->name }}</p>
    @if($workspace->description)<p>{{ $workspace->description }}</p>@endif

    <h2>{{ __('Resources') }}</h2>
    @forelse($workspace->resources as $r)
        <p><span class="meta">{{ $r->resourceType->label($lang) }}</span> · @if($r->external_url)<a href="{{ $r->external_url }}" rel="noopener noreferrer">{{ $r->title }}</a>@else<a href="{{ route('research.workspaces.resource', $r) }}">{{ $r->title }}</a>@endif
            <span class="meta">{{ $r->addedBy?->name }}</span>@if($r->description)<br>{{ $r->description }}@endif
            @if($level >= 2 && ($r->added_by_id === $user->id || $level >= 3 || $role === 'editor'))<form method="post" action="{{ route('research.workspaces.resource.remove', $r) }}" style="display: inline">@csrf<button type="submit">{{ __('Remove') }}</button></form>@endif</p>
    @empty<p>{{ __('None.') }}</p>@endforelse
    @if($level >= 2)
        <details><summary>{{ __('Add a resource') }}</summary>
            <form method="post" action="{{ route('research.workspaces.step', [$workspace, 'resource']) }}">@csrf
                <label for="wr-type">{{ __('Kind') }}</label> <select id="wr-type" name="resource_type">@foreach($kinds as $c)<option value="{{ $c->notation }}">{{ $c->label($lang) }}</option>@endforeach</select>
                <label for="wr-id">{{ __('Yours, or the workspace\'s file') }}</label> <select id="wr-id" name="resource_id"><option value="">{{ __('None') }}</option>
                    @foreach($mine as $type => $list)@foreach($list as $m)<option value="{{ $m->id }}">{{ $kinds->firstWhere('notation', $type)?->label($lang) }}: {{ $m->name ?? $m->title }}</option>@endforeach @endforeach
                    @foreach($workspace->files as $f)<option value="{{ $f->id }}">{{ $kinds->firstWhere('notation', 'document')?->label($lang) }}: {{ $f->file->original_name }}</option>@endforeach</select><br>
                <label for="wr-url">{{ __('Web address, for a link') }}</label> <input id="wr-url" name="external_url" type="url" size="40">
                <label for="wr-title">{{ __('Title') }}</label> <input id="wr-title" name="title" size="30">
                <label for="wr-desc">{{ __('Description') }}</label> <input id="wr-desc" name="description" size="40"> <button type="submit">{{ __('Add') }}</button></form>
        </details>
    @endif

    <h2>{{ __('Files') }}</h2>
    @forelse($workspace->files as $f)
        <p><a href="{{ route('research.workspaces.file', $f) }}">{{ $f->file->original_name }}</a> <span class="meta">{{ \Illuminate\Support\Number::fileSize($f->file->size_bytes) }} · {{ $f->addedBy?->name }} · {{ $f->created_at?->timezone(config('workspace.timezone'))->format('j M Y') }} · SHA-256 {{ substr($f->file->sha256, 0, 12) }}</span>
            @if($level >= 2 && ($f->added_by_id === $user->id || $level >= 3 || $role === 'editor'))<form method="post" action="{{ route('research.workspaces.file.remove', $f) }}" style="display: inline">@csrf<button type="submit">{{ __('Remove') }}</button></form>@endif
            @if($f->added_by_id === $user->id) @include('research._project-select', ['thing' => $f, 'kind' => 'workspace-file'])@endif</p>
    @empty<p>{{ __('None.') }}</p>@endforelse
    @if($level >= 2)
        <form method="post" action="{{ route('research.workspaces.step', [$workspace, 'file']) }}" enctype="multipart/form-data">@csrf<label for="wf-file">{{ __('Add a file') }}</label> <input id="wf-file" name="file" type="file" required> <button type="submit">{{ __('Upload') }}</button></form>
    @endif

    @include('research._discussions', ['home' => $workspace, 'homeKind' => 'workspaces'])

    <h2>{{ __('Members') }}</h2>
    @foreach($workspace->members->sortBy(fn ($m) => [$m->status->notation !== 'accepted', $m->user->name]) as $m)
        @continue(in_array($m->status->notation, ['removed', 'declined'], true) && $level < 3)
        <p>{{ $m->user->name }} · {{ $m->role->label($lang) }} · <span class="meta">{{ $m->status->label($lang) }}@if($m->accepted_at) · {{ $m->accepted_at->timezone(config('workspace.timezone'))->format('j M Y') }}@endif</span>
            @if($level >= 3 && $m->role->notation !== 'owner' && $m->status->notation === 'accepted')
                <form method="post" action="{{ route('research.workspaces.member', [$m, 'role']) }}" style="display: inline">@csrf<select name="role" aria-label="{{ __('Role of :name', ['name' => $m->user->name]) }}">@foreach($roles as $c)@if($c->notation !== 'owner' || $level >= 4)<option value="{{ $c->notation }}" @selected($m->role->notation === $c->notation)>{{ $c->label($lang) }}</option>@endif @endforeach</select> <button type="submit">{{ __('Change') }}</button></form>
            @endif
            @if($m->role->notation !== 'owner' && in_array($m->status->notation, ['pending', 'accepted'], true) && ($level >= 3 || $m->user_id === $user->id))<form method="post" action="{{ route('research.workspaces.member', [$m, 'remove']) }}" style="display: inline">@csrf<button type="submit">{{ $m->user_id === $user->id ? __('Leave') : __('Remove') }}</button></form>@endif</p>
    @endforeach
    @if($level >= 3)
        <form method="post" action="{{ route('research.workspaces.step', [$workspace, 'invite']) }}">@csrf
            <label for="wi-email">{{ __('Invite by e-mail address') }}</label> <input id="wi-email" name="email" type="email" required>
            <label for="wi-role">{{ __('Role') }}</label> <select id="wi-role" name="role">@foreach($roles->reject(fn ($c) => $c->notation === 'owner') as $c)<option value="{{ $c->notation }}" @selected($c->notation === 'viewer')>{{ $c->label($lang) }}</option>@endforeach</select> <button type="submit">{{ __('Invite') }}</button></form>
    @endif

    @php($shared = \App\Models\ResearchDiaryEntry::where('is_private', false)->whereIn('user_id', $workspace->members->filter(fn ($m) => $m->status->notation === 'accepted')->pluck('user_id'))->with('user', 'entryType')->orderByDesc('entry_date')->orderByDesc('created_at')->limit(30)->get())
    @if($shared->isNotEmpty())
        <h2>{{ __('Diary entries the members share') }}</h2>
        @foreach($shared as $d)<p><strong>{{ $d->entry_date->format('j M Y') }}</strong> · {{ $d->user->name }} · <span class="meta">{{ $d->entryType->label($lang) }}</span>@if($d->title) · {{ $d->title }}@endif @if($d->content !== $d->title)<br><span style="white-space: pre-wrap">{{ \Illuminate\Support\Str::limit($d->content, 400) }}</span>@endif</p>@endforeach
    @endif

    @if($level >= 3)
        <h2>{{ __('Settings') }}</h2>
        <form method="post" action="{{ route('research.workspaces.step', [$workspace, 'update']) }}">@csrf
            <label for="ws-name">{{ __('Name') }}</label> <input id="ws-name" name="name" required value="{{ $workspace->name }}">
            <label for="ws-vis">{{ __('Who may see it') }}</label> <select id="ws-vis" name="visibility">@foreach($visibilities as $c)<option value="{{ $c->notation }}" @selected($workspace->visibility->notation === $c->notation)>{{ $c->label($lang) }}</option>@endforeach</select><br>
            <label for="ws-desc">{{ __('Description') }}</label> <input id="ws-desc" name="description" size="50" value="{{ $workspace->description }}"> <button type="submit">{{ __('Save') }}</button></form>
        @if($level >= 4)<form method="post" action="{{ route('research.workspaces.step', [$workspace, 'delete']) }}">@csrf<button type="submit">{{ __('Delete the research workspace') }}</button></form>@endif
    @endif
@endsection
