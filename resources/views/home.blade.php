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
    @can(\App\Support\Vocab::permission('view-audit-log'))
        <p><a href="{{ route('audit') }}">Audit log</a></p>
    @endcan
@endsection
