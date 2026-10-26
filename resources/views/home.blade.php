{{-- OpenRiC Workspace - home page. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Home'))
@section('content')
    @php($user = auth()->user()->load('institution', 'roles.labels'))
    <h1>{{ $user->institution->name }}</h1>
    <p>{{ __('Signed in as :name (:email).', ['name' => $user->name, 'email' => $user->email]) }}</p>
    <h2>{{ __('Your roles') }}</h2>
    @forelse($user->roles as $role)
        <p>{{ $role->name() }}</p>
    @empty
        <p>{{ __('You have no roles of your own yet, so you can do only what every signed-in account may. An administrator can give you a role.') }}</p>
    @endforelse
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <ul>
        <li><a href="{{ route('account.profile') }}">{{ __('Profile') }}</a></li>
        <li><a href="{{ route('password.edit') }}">{{ __('Change password') }}</a></li>
        @if($user->mayAnywhere('read', 'repository'))<li><a href="{{ route('repositories.index') }}">{{ __('Repositories') }}</a></li>@endif
        @if($user->mayAnywhere('read', 'description'))<li><a href="{{ route('records.index') }}">{{ __('Archival descriptions') }}</a></li>@endif
        @can(\App\Support\Vocab::permission('manage-accounts'))
            <li><a href="{{ route('accounts.index') }}">{{ __('Users') }}</a></li>
        @endcan
        @can(\App\Support\Vocab::permission('manage-roles'))
            <li><a href="{{ route('roles.index') }}">{{ __('Roles and permissions') }}</a></li>
        @endcan
        @can(\App\Support\Vocab::permission('view-audit-log'))
            <li><a href="{{ route('audit') }}">{{ __('Audit log') }}</a></li>
        @endcan
    </ul>
@endsection
