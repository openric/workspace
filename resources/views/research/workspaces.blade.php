{{-- OpenRiC Workspace - one's research workspaces and invitations (Heratio's workspaces; roadmap phase 19d). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Research workspaces'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Research workspaces') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @error('research')<p class="error" role="alert">{{ $message }}</p>@enderror
    @if($invitations->isNotEmpty())
        <h2>{{ __('Invitations') }}</h2>
        @foreach($invitations as $i)<p>{{ __(':who invites you to ":name" as :role.', ['who' => $i->invitedBy?->name, 'name' => $i->workspace->name, 'role' => $i->role->label($lang)]) }}
            <form method="post" action="{{ route('research.workspaces.member', [$i, 'accept']) }}" style="display: inline">@csrf<button type="submit">{{ __('Accept') }}</button></form>
            <form method="post" action="{{ route('research.workspaces.member', [$i, 'decline']) }}" style="display: inline">@csrf<button type="submit">{{ __('Decline') }}</button></form></p>@endforeach
    @endif
    @forelse($workspaces as $w)<p><a href="{{ route('research.workspaces.show', $w) }}">{{ $w->name }}</a> · {{ $w->visibility->label($lang) }} · {{ trans_choice(':count member|:count members', $w->members_count, ['count' => $w->members_count]) }} · <span class="meta">{{ $w->owner->name }}</span>@if($w->description)<br><span class="meta">{{ $w->description }}</span>@endif</p>
    @empty<p>{{ __('None.') }}</p>@endforelse
    <details><summary>{{ __('New research workspace') }}</summary>
        <form method="post" action="{{ route('research.workspaces.store') }}">@csrf
            <label for="rw-name">{{ __('Name') }}</label> <input id="rw-name" name="name" required>
            <label for="rw-vis">{{ __('Who may see it') }}</label> <select id="rw-vis" name="visibility">@foreach($visibilities as $c)<option value="{{ $c->notation }}">{{ $c->label($lang) }}</option>@endforeach</select><br>
            <label for="rw-desc">{{ __('Description') }}</label> <input id="rw-desc" name="description" size="50"> <button type="submit">{{ __('Create') }}</button></form>
    </details>
@endsection
