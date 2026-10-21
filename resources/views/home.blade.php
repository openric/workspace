{{-- OpenRiC Workspace - home page. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'Home')
@section('content')
    @php($user = auth()->user()->load('institution', 'roles.labels'))
    <h1>{{ $user->institution->name }}</h1>
    <p>Signed in as {{ $user->name }} ({{ $user->email }}).</p>
    <h2>Your roles</h2>
    @forelse($user->roles as $role)
        <p>{{ $role->name() }}</p>
    @empty
        <p>You have no roles of your own yet, so you can do only what every signed-in account may. An administrator can give you a role.</p>
    @endforelse
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <ul>
        <li><a href="{{ route('account.profile') }}">Your profile</a></li>
        <li><a href="{{ route('password.edit') }}">Change your password</a></li>
        @if($user->mayAnywhere('read', 'repository'))<li><a href="{{ route('repositories.index') }}">Repositories</a></li>@endif
        @if($user->mayAnywhere('read', 'description'))<li><a href="{{ route('records.index') }}">Archival descriptions</a></li>@endif
        @can(\App\Support\Vocab::permission('manage-accounts'))
            <li><a href="{{ route('accounts.index') }}">Accounts</a></li>
        @endcan
        @can(\App\Support\Vocab::permission('manage-roles'))
            <li><a href="{{ route('roles.index') }}">Roles and permissions</a></li>
        @endcan
        @can(\App\Support\Vocab::permission('view-audit-log'))
            <li><a href="{{ route('audit') }}">Audit log</a></li>
        @endcan
    </ul>
@endsection
