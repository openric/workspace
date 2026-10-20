{{-- OpenRiC Workspace - authority records at a glance, and the work queue of incomplete ones. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'Authority records dashboard')
@section('content')
    <p><a href="{{ route('agents.index') }}">Authority records</a></p>
    <h1>Authority records dashboard</h1>
    <p>{{ $agents->count() }} {{ $agents->count() === 1 ? 'authority record' : 'authority records' }}.</p>
    <div style="display: flex; gap: 3rem; flex-wrap: wrap">
        <section><h2>By type of entity</h2>@forelse($byType as $label => $n)<p>{{ $label }}: {{ $n }}</p>@empty<p>None.</p>@endforelse</section>
        <section><h2>By status</h2>@forelse($byStatus as $label => $n)<p>{{ $label }}: {{ $n }}</p>@empty<p>None.</p>@endforelse</section>
    </div>
    <h2>Work queue</h2>
    <p>Records that lack an ISAAR(CPF) essential element, the least complete first.</p>
    @forelse($queue as $agent)
        <p><a href="{{ route('agents.show', $agent) }}">{{ $agent->described('agent-authorised-name', ['en']) }}</a>: {{ $completeness[$agent->id]['percent'] }}% complete; missing {{ implode(', ', $completeness[$agent->id]['missing']) }}
            @include('description._row-actions', ['entity' => $agent])</p>
    @empty
        <p>Every record has its essential elements.</p>
    @endforelse
    <h2>Recently changed</h2>
    @forelse($recent as $agent)
        <p><a href="{{ route('agents.show', $agent) }}">{{ $agent->described('agent-authorised-name', ['en']) }}</a>, {{ $agent->updated_at->format('Y-m-d H:i') }}</p>
    @empty
        <p>None.</p>
    @endforelse
@endsection
