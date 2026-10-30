{{-- OpenRiC Workspace - the workflow dashboard (Heratio's workflow dashboard). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Workflow'))
@section('content')
    <h1>{{ __('Workflow') }}</h1>
    @include('workflow._nav')
    <p><strong>{{ __('My tasks') }}:</strong> {{ $stats['mine'] }} · <strong>{{ __('In the pool') }}:</strong> {{ $stats['pool'] }}
        · <strong>{{ __('Completed today') }}:</strong> {{ $stats['completed_today'] }} · <strong>{{ __('Overdue') }}:</strong> {{ $stats['overdue'] }}</p>
    <h2>{{ __('My tasks') }}</h2>
    @include('workflow._tasks', ['tasks' => $mine])
    <h2>{{ __('In the pool') }}</h2>
    @include('workflow._tasks', ['tasks' => $pool])
    <h2>{{ __('Lately') }}</h2>
    @include('workflow._events', ['events' => $recent])
@endsection
