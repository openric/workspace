{{-- OpenRiC Workspace - the relationship graph around an entity, drawn and as a list (WS-009). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@php($root = $graph->describe($entity->getAttribute('iri')))
@section('title', __('Graph: :name', ['name' => $root['label']]))
@section('content')
    <p><a href="{{ $root['url'] }}">{{ __('Back to :name', ['name' => $root['label']]) }}</a></p>
    <h1 id="graph-title">{{ __('Relationships of :name', ['name' => $root['label']]) }}</h1>
    <p>@if($depth === 1){{ __('One step out') }} · <a href="{{ route('discover.graph', [$kind, $entity->getKey(), 'depth' => 2]) }}">{{ __('Two steps out') }}</a>@else<a href="{{ route('discover.graph', [$kind, $entity->getKey()]) }}">{{ __('One step out') }}</a> · {{ __('Two steps out') }} @endif
        · <a href="#as-a-list">{{ __('The graph as a list') }}</a></p>
    <p id="graph-desc" class="meta">{{ __(':nodes entities and :edges relationships, each typed by its RiC-O class. Every entity opens its own description.', ['nodes' => count($graph->nodes), 'edges' => count($graph->edges)]) }}
        @if($graph->truncated) {{ __('Not every relationship is drawn: the list below says which entities are shown.') }}@endif</p>
    {{-- The interactive viewer (@openric/viewer, as on openric.org): 2D, or 3D on request; the drawing below stays for a browser without JavaScript. --}}
    <div class="graph-viewer" hidden>
        <p role="group" aria-label="{{ __('View') }}" style="margin: 0 0 .5rem">
            <button type="button" data-mode="2d" aria-pressed="true">2D</button>
            <button type="button" data-mode="3d" aria-pressed="false">3D</button>
            <button type="button" data-mode="picture" aria-pressed="false">{{ __('Picture') }}</button>
        </p>
        <div id="graph-canvas" aria-hidden="true" style="height: min(70vh, 40rem); background: #0f2328; border: 1px solid var(--line)"></div>
        <p class="meta" id="graph-key"></p>
        <p class="meta">{{ __('Drag to move, scroll or pinch to zoom; select an entity to open it. The list below gives the same relationships for a screen reader or keyboard.') }}</p>
    </div>
    <script type="application/json" id="graph-data">@json($graph->subgraph())</script>
    @php($positions = $graph->layout())
    @php($colours = ['record' => '#10373e', 'agent' => '#8a4b08', 'repository' => '#5b3d8a', 'place' => '#2f6b2f', 'activity' => '#9b1c1c', 'concept' => '#52606d', 'instantiation' => '#52606d'])
    <div id="graph-picture">
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
    <p class="meta">{{ __('Colours:') }}
        @foreach(['record' => 'units of description', 'agent' => 'people, families and organisations', 'repository' => 'repositories', 'place' => 'places', 'activity' => 'activities', 'concept' => 'subjects'] as $key => $what)
            <span style="white-space: nowrap"><svg width="12" height="12" aria-hidden="true" focusable="false" style="vertical-align: middle"><circle cx="6" cy="6" r="5" fill="{{ $colours[$key] }}" stroke="currentColor" stroke-width=".5"></circle></svg> {{ $what }}</span>@if(! $loop->last),@endif
        @endforeach</p>
    </div>

    <script src="{{ asset('vendor/graph/cytoscape.min.js') }}"></script>
    <script src="{{ asset('vendor/graph/openric-viewer.umd.js') }}"></script>
    <script>
    (function () {
        var box = document.querySelector('.graph-viewer'), canvas = document.getElementById('graph-canvas'), picture = document.getElementById('graph-picture');
        if (!box || !window.cytoscape || !window.OpenricViewer) return;
        var data = JSON.parse(document.getElementById('graph-data').textContent), handle = null, loaded3d = null;
        var open = function (node) { var url = node && (node.atomUrl || (node.data && node.data.atomUrl)); if (url) window.location.href = url; };
        // The 3D libraries are large, so they load only when 3D is chosen.
        var load3d = function () {
            if (loaded3d) return loaded3d;
            var urls = [@json(asset('vendor/graph/three.min.js')), @json(asset('vendor/graph/three-spritetext.min.js')), @json(asset('vendor/graph/3d-force-graph.min.js'))];
            loaded3d = urls.reduce(function (p, url) { return p.then(function () { return new Promise(function (ok, fail) {
                var s = document.createElement('script'); s.src = url; s.onload = ok; s.onerror = fail; document.head.appendChild(s); }); }); }, Promise.resolve());
            return loaded3d;
        };
        var show = function (mode) {
            box.querySelectorAll('button[data-mode]').forEach(function (b) { b.setAttribute('aria-pressed', String(b.dataset.mode === mode)); });
            if (handle && handle.destroy) handle.destroy(); else if (handle && handle._destructor) handle._destructor();
            handle = null; canvas.innerHTML = '';
            canvas.hidden = mode === 'picture'; picture.hidden = mode !== 'picture';
            if (mode === '2d') handle = OpenricViewer.init2D(canvas, data, { onNodeClick: open });
            if (mode === '3d') load3d().then(function () {
                handle = OpenricViewer.init3D(canvas, data, { onNodeClick: open });
                if (handle && handle.onEngineStop) handle.onEngineStop(function () { handle.zoomToFit(400, 20); });
            }, function () { show('picture'); });
        };
        box.querySelectorAll('button[data-mode]').forEach(function (b) { b.addEventListener('click', function () { show(b.dataset.mode); }); });
        // The colour key, from the viewer's own colours for the classes on this graph.
        var key = document.getElementById('graph-key'), seen = {};
        data['openric:nodes'].forEach(function (n) {
            var name = String(n.type || '').replace(/^.*:/, '');
            if (seen[name]) return; seen[name] = true;
            var item = document.createElement('span'); item.style.whiteSpace = 'nowrap'; item.style.marginRight = '1rem';
            var dot = document.createElement('span'); dot.setAttribute('aria-hidden', 'true');
            dot.style.cssText = 'display: inline-block; width: .8rem; height: .8rem; border-radius: 50%; margin-right: .3rem; vertical-align: middle; background: ' + OpenricViewer.getColour(n);
            item.appendChild(dot); item.appendChild(document.createTextNode(n.type)); key.appendChild(item);
        });
        box.hidden = false;
        show('2d');
    })();
    </script>

    <h2 id="as-a-list">{{ __('The graph as a list') }}</h2>
    <ul>
        @foreach($graph->edges as $edge)
            @php([$from, $to] = [$graph->describe($edge['from']), $graph->describe($edge['to'])])
            <li>@if($from['url'])<a href="{{ $from['url'] }}">{{ $from['label'] }}</a>@else{{ $from['label'] }}@endif
                {{ $edge['phrase'] }}
                @if($to['url'])<a href="{{ $to['url'] }}">{{ $to['label'] }}</a>@else{{ $to['label'] }}@endif
                @if($edge['term'])@include('description._ric', ['term' => $edge['term']])@endif</li>
        @endforeach
    </ul>
    <h3>{{ __('Entities') }}</h3>
    <ul>
        @foreach($graph->nodes as $iri => $node)
            @php($d = $graph->describe($iri))
            <li>@if($d['url'])<a href="{{ $d['url'] }}">{{ $d['label'] }}</a>@else{{ $d['label'] }}@endif @include('description._ric', ['term' => $d['class']])</li>
        @endforeach
    </ul>
@endsection
