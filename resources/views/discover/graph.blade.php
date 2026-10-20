{{-- OpenRiC Workspace - the relationship graph around an entity, drawn and as a list (WS-009). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@php($root = $graph->describe($entity->getAttribute('iri')))
@section('title', 'Graph: '.$root['label'])
@section('content')
    <p><a href="{{ $root['url'] }}">Back to {{ $root['label'] }}</a></p>
    <h1 id="graph-title">Relationships of {{ $root['label'] }}</h1>
    <p>@if($depth === 1)One step out · <a href="{{ route('discover.graph', [$kind, $entity->getKey(), 'depth' => 2]) }}">Two steps out</a>@else<a href="{{ route('discover.graph', [$kind, $entity->getKey()]) }}">One step out</a> · Two steps out @endif
        · <a href="#as-a-list">The graph as a list</a></p>
    <p id="graph-desc" class="meta">{{ count($graph->nodes) }} entities and {{ count($graph->edges) }} relationships, each typed by its RiC-O class. Every entity opens its own description.
        @if($graph->truncated) Not every relationship is drawn: the list below says which entities are shown.@endif</p>
    @php($positions = $graph->layout())
    @php($colours = ['record' => '#10373e', 'agent' => '#8a4b08', 'repository' => '#5b3d8a', 'place' => '#2f6b2f', 'activity' => '#9b1c1c', 'concept' => '#52606d', 'instantiation' => '#52606d'])
    <svg viewBox="-520 -470 1040 940" role="group" aria-labelledby="graph-title graph-desc" style="width: 100%; max-width: 60rem; height: auto; border: 1px solid var(--line)">
        @foreach($graph->edges as $edge)
            @php([$a, $b] = [$positions[$edge['from']], $positions[$edge['to']]])
            <line x1="{{ round($a['x'], 1) }}" y1="{{ round($a['y'], 1) }}" x2="{{ round($b['x'], 1) }}" y2="{{ round($b['y'], 1) }}" stroke="currentColor" stroke-opacity=".35" stroke-width="1.5"><title>{{ $graph->describe($edge['from'])['label'] }} {{ $edge['phrase'] }} {{ $graph->describe($edge['to'])['label'] }}</title></line>
        @endforeach
        @foreach($graph->nodes as $iri => $node)
            @php($d = $graph->describe($iri))
            @php($p = $positions[$iri])
            @php($r = $node['depth'] === 0 ? 16 : 10)
            @if($d['url'])<a href="{{ $d['url'] }}">@endif
                <g>
                    <title>{{ $d['label'] }} ({{ $d['class'] }})</title>
                    <circle cx="{{ round($p['x'], 1) }}" cy="{{ round($p['y'], 1) }}" r="{{ $r }}" fill="{{ $colours[$d['kind']] ?? '#52606d' }}"></circle>
                    <text x="{{ round($p['x'], 1) }}" y="{{ round($p['y'] + $r + 15, 1) }}" text-anchor="middle" font-size="{{ $node['depth'] === 0 ? 15 : 12 }}" fill="currentColor">{{ \Illuminate\Support\Str::limit($d['label'], 30) }}</text>
                </g>
            @if($d['url'])</a>@endif
        @endforeach
    </svg>
    <p class="meta">Colours:
        @foreach(['record' => 'units of description', 'agent' => 'people, families and organisations', 'repository' => 'repositories', 'place' => 'places', 'activity' => 'activities', 'concept' => 'subjects'] as $key => $what)
            <span style="white-space: nowrap"><svg width="12" height="12" aria-hidden="true" focusable="false" style="vertical-align: middle"><circle cx="6" cy="6" r="5" fill="{{ $colours[$key] }}" stroke="currentColor" stroke-width=".5"></circle></svg> {{ $what }}</span>@if(! $loop->last),@endif
        @endforeach</p>

    <h2 id="as-a-list">The graph as a list</h2>
    <ul>
        @foreach($graph->edges as $edge)
            @php([$from, $to] = [$graph->describe($edge['from']), $graph->describe($edge['to'])])
            <li>@if($from['url'])<a href="{{ $from['url'] }}">{{ $from['label'] }}</a>@else{{ $from['label'] }}@endif
                {{ $edge['phrase'] }}
                @if($to['url'])<a href="{{ $to['url'] }}">{{ $to['label'] }}</a>@else{{ $to['label'] }}@endif
                @if($edge['term'])@include('description._ric', ['term' => $edge['term']])@endif</li>
        @endforeach
    </ul>
    <h3>Entities</h3>
    <ul>
        @foreach($graph->nodes as $iri => $node)
            @php($d = $graph->describe($iri))
            <li>@if($d['url'])<a href="{{ $d['url'] }}">{{ $d['label'] }}</a>@else{{ $d['label'] }}@endif @include('description._ric', ['term' => $d['class']])</li>
        @endforeach
    </ul>
@endsection
