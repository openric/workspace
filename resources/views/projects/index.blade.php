{{-- OpenRiC Workspace - one's research projects and invitations (Heratio's projects; roadmap phase 19f). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Research projects'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Research projects') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @error('research')<p class="error" role="alert">{{ $message }}</p>@enderror
    @if($errors->any() && ! $errors->has('research'))<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    @if($invitations->isNotEmpty())
        <h2>{{ __('Invitations') }}</h2>
        @foreach($invitations as $i)<p>{{ __(':who invites you to ":name" as :role.', ['who' => $i->invitedBy?->name, 'name' => $i->project->title, 'role' => $i->role->label($lang)]) }}
            <form method="post" action="{{ route('research.projects.member', [$i, 'accept']) }}" style="display: inline">@csrf<button type="submit">{{ __('Accept') }}</button></form>
            <form method="post" action="{{ route('research.projects.member', [$i, 'decline']) }}" style="display: inline">@csrf<button type="submit">{{ __('Decline') }}</button></form></p>@endforeach
    @endif
    @forelse($projects as $p)<p><a href="{{ route('research.projects.show', $p) }}">{{ $p->title }}</a> · {{ $p->projectType->label($lang) }} · {{ $p->status->label($lang) }} · <span class="meta">{{ $p->owner->name }}</span></p>
    @empty<p>{{ __('None.') }}</p>@endforelse
    <details><summary>{{ __('New research project') }}</summary>
        <form method="post" action="{{ route('research.projects.store') }}">@csrf @include('projects._fields', ['p' => null])<button type="submit">{{ __('Create') }}</button></form>
    </details>
@endsection
