{{-- OpenRiC Workspace - home page. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'Home')
@section('content')
    @php($user = auth()->user()->load('institution', 'roles.labels'))
    <h1>{{ $user->institution->name }}</h1>
    <p>Signed in as {{ $user->name }} ({{ $user->email }}).</p>
    <h2>Your roles</h2>
    @forelse($user->roles as $role)
        <p>{{ $role->label(['en', 'und']) ?? $role->iri }}</p>
    @empty
        <p>You have no roles yet, so there is nothing you can change. An administrator can grant you one.</p>
    @endforelse
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <ul>
        <li><a href="{{ route('password.edit') }}">Change your password</a></li>
        @can(\App\Support\Vocab::permission('describe'))
            <li><a href="{{ route('repositories.index') }}">Repositories</a></li>
            <li><a href="{{ route('records.index') }}">Archival descriptions</a></li>
        @endcan
        @can(\App\Support\Vocab::permission('manage-accounts'))
            <li><a href="{{ route('accounts.index') }}">Accounts</a></li>
        @endcan
        @can(\App\Support\Vocab::permission('view-audit-log'))
            <li><a href="{{ route('audit') }}">Audit log</a></li>
        @endcan
    </ul>
@endsection
