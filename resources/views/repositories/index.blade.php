{{-- OpenRiC Workspace - repositories list. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Repositories'))
@section('content')
    <h1>{{ __('Repositories') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if(auth()->user()->may('create', 'repository'))<p><a href="{{ route('repositories.create') }}">{{ __('New repository') }}</a> · <a href="{{ route('imports.create') }}">{{ __('Import') }}</a></p>@endif
    @forelse($repositories as $repository)
        <p><a href="{{ route('repositories.show', $repository) }}">{{ $repository->described('authorised-name', [app()->getLocale(), 'en']) }}</a>@if($repository->identifier) ({{ $repository->identifier }})@endif
            @include('description._row-actions', ['entity' => $repository])</p>
    @empty
        <p>{{ __('No repositories yet.') }}</p>
    @endforelse
@endsection
