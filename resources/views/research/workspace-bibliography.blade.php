{{-- OpenRiC Workspace - a bibliography opened through a research workspace (roadmap phase 19d). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $bibliography->name)
@section('content')
    @php($back ??= [route('research.workspaces.show', $resource->workspace), $resource->workspace->name])
    <p><a href="{{ $back[0] }}">{{ $back[1] }}</a></p>
    <h1>{{ $bibliography->name }}</h1>
    @if($bibliography->description)<p>{{ $bibliography->description }}</p>@endif
    @forelse($bibliography->entries as $e)<p>{{ $service->styled($e, $bibliography->citationStyle->notation) }}</p>@empty<p>{{ __('No entry yet.') }}</p>@endforelse
@endsection
