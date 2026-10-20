{{-- OpenRiC Workspace - repositories list. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'Repositories')
@section('content')
    <h1>Repositories</h1>
    <p><a href="{{ route('repositories.create') }}">New repository</a></p>
    @forelse($repositories as $repository)
        <p><a href="{{ route('repositories.show', $repository) }}">{{ $repository->described('authorised-name', [app()->getLocale(), 'en']) }}</a>@if($repository->identifier) ({{ $repository->identifier }})@endif</p>
    @empty
        <p>No repositories yet.</p>
    @endforelse
@endsection
