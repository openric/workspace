{{-- OpenRiC Workspace - a project's knowledge graph (Heratio's knowledgeGraph; roadmap phase 19i): its claims as nodes and what they assert as edges, also as JSON. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Knowledge graph').': '.$project->title)
@section('content')
    @include('reasoning._nav')
    <h1>{{ __('Knowledge graph') }}</h1>
    <p class="meta">{{ __(':nodes nodes, :edges edges.', ['nodes' => count($graph['nodes']), 'edges' => count($graph['edges'])]) }} <a href="{{ route('research.reasoning.graph', [$project, 'format' => 'json']) }}">{{ __('As JSON') }}</a></p>
    @php($labels = collect($graph['nodes'])->pluck('label', 'id'))
    @if($graph['edges'])
        <table><thead><tr><th>{{ __('Subject') }}</th><th>{{ __('Predicate') }}</th><th>{{ __('Object') }}</th><th>{{ __('Status') }}</th></tr></thead><tbody>
        @foreach($graph['edges'] as $e)<tr><td>{{ $labels[$e['source']] ?? '' }}</td><td>{{ $e['label'] }}</td><td>{{ $labels[$e['target']] ?? '' }}</td><td>{{ $e['status'] }}</td></tr>@endforeach
        </tbody></table>
    @endif
    <h2>{{ __('Nodes') }}</h2>
    <ul>@foreach($graph['nodes'] as $n)<li>{{ $n['label'] }} <span class="meta">{{ $n['type'] }}@if($n['evidence']) · {{ trans_choice(':count piece of evidence|:count pieces of evidence', $n['evidence'], ['count' => $n['evidence']]) }}@endif</span></li>@endforeach</ul>
@endsection
