{{-- OpenRiC Workspace - roles list. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'Roles and permissions')
@section('content')
    <h1>Roles and permissions</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p>A role's permissions say what its members may do with each kind of record. A role inherits the permissions of the role above it. Where a role grants something and another denies it, the denial wins. Anything not granted is refused.</p>
    <p><a href="{{ route('roles.create') }}">New role</a></p>
    <table>
        <thead><tr><th scope="col">Role</th><th scope="col">Inherits from</th><th scope="col">Members</th></tr></thead>
        <tbody>
        @foreach($roles as $role)
            <tr>
                <td><a href="{{ route('roles.show', $role) }}">{{ $role->name() }}</a>@if($role->is_system) <small>(system)</small>@endif</td>
                <td>{{ $role->parent?->name() ?? 'nothing' }}</td>
                <td>{{ $role->is_system ? ($role->isTemplate('public') ? 'everyone' : 'every signed-in account') : $role->members->count() }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endsection
