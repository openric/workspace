{{-- OpenRiC Workspace - one role. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $role->name())
@section('content')
    <p><a href="{{ route('roles.index') }}">All roles</a></p>
    <h1>{{ $role->name() }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif

    <h2>Permissions</h2>
    <p>What members of this role may do, for each kind of record. Inherited from {{ $role->parent?->name() ?? 'nothing' }} as well.</p>
    <ul>
        @foreach($kinds as $kind)
            <li><a href="{{ route('roles.permissions', [$role, $kind->notation]) }}">{{ $kind->label(\App\Support\Vocab::languages()) }}</a></li>
        @endforeach
    </ul>

    <h2>Members</h2>
    @if($role->is_system)
        <p>{{ $role->isTemplate('public') ? 'Everyone belongs to this role, signed in or not.' : 'Every signed-in account belongs to this role.' }}</p>
    @else
        @forelse($role->members as $member)
            <form method="post" action="{{ route('roles.members.remove', [$role, $member]) }}">
                @csrf
                <a href="{{ route('accounts.show', $member) }}">{{ $member->name }}</a> ({{ $member->email }})@if($member->isDisabled()) <small>disabled</small>@endif
                <button type="submit">Remove from role</button>
            </form>
        @empty
            <p>No members yet.</p>
        @endforelse
        @if($candidates->isNotEmpty())
            <form method="post" action="{{ route('roles.members.add', $role) }}" style="display: flex; gap: .5rem; align-items: end; flex-wrap: wrap">
                @csrf
                <div><label for="account">Add an account</label>
                <select id="account" name="account" style="font: inherit; padding: .5rem">
                    @foreach($candidates as $candidate)<option value="{{ $candidate->id }}">{{ $candidate->name }} ({{ $candidate->email }})</option>@endforeach
                </select></div>
                <button type="submit">Add</button>
            </form>
        @endif
    @endif

    <h2>Translation</h2>
    <form method="post" action="{{ route('roles.languages', $role) }}">
        @csrf
        <label for="languages">Languages members may translate into (BCP 47, separated by spaces or commas)</label>
        <small id="languages-help">Used with the Translate permission on archival descriptions.</small>
        <input id="languages" name="languages" aria-describedby="languages-help" value="{{ old('languages', implode(' ', $role->translationLanguages())) }}">
        <button type="submit">Save languages</button>
    </form>

    <h2>Name and inheritance</h2>
    <form method="post" action="{{ route('roles.update', $role) }}">
        @csrf
        @include('roles._profile', ['role' => $role, 'language' => request('language', 'en')])
        <button type="submit">Save role</button>
    </form>

    @unless($role->is_system || $role->isTemplate('administrator'))
        <h2>Remove</h2>
        <form method="post" action="{{ route('roles.destroy', $role) }}">
            @csrf
            <p>Its members lose the role and its permissions are removed. This is recorded in the audit log.</p>
            <button type="submit">Remove this role</button>
        </form>
    @endunless
@endsection
