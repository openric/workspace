{{-- OpenRiC Workspace - a bibliography opened by its link (roadmap phase 19c). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', $bibliography->name)
@section('content')
    <h1>{{ $bibliography->name }}</h1>
    @if($bibliography->description)<p>{{ $bibliography->description }}</p>@endif
    @forelse($bibliography->entries as $e)<p>{{ $service->styled($e, $bibliography->citationStyle->notation) }}</p>@empty<p>{{ __('No entry yet.') }}</p>@endforelse
@endsection
