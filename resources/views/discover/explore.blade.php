{{-- OpenRiC Workspace - explore the published RiC-O graph with Sparnatural, without writing SPARQL (roadmap phase 16e). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', __('Explore the graph'))
@section('content')
    <h1>{{ __('Explore the graph') }}</h1>
    <p>{{ __('Build a question of the published records, people, organisations, places and activities by choosing what you look for and how they are linked; Sparnatural writes the SPARQL query, and the answer appears below. The same query can be sent to :url.', ['url' => route('sparql')]) }}</p>
    @if(! $configured)
        <p class="meta">{{ __('The graph is not yet open on this installation.') }}</p>
    @else
        <link rel="stylesheet" href="{{ asset('vendor/sparnatural/14.0.0/sparnatural.css') }}">
        <spar-natural id="sparnatural" src="{{ route('sparnatural.config') }}" endpoint="{{ route('sparql') }}" lang="{{ app()->getLocale() === 'af' ? 'en' : app()->getLocale() }}" distinct="true" limit="100"></spar-natural>
        <div id="explore-results" aria-live="polite"></div>
        <script src="{{ asset('vendor/sparnatural/14.0.0/sparnatural.js') }}"></script>
        <script>
            // Each query Sparnatural builds is sent to the endpoint and its answer shown as a table of links.
            (function () {
                const element = document.getElementById('sparnatural');
                const results = document.getElementById('explore-results');
                element.addEventListener('queryUpdated', function (event) { element.dataset.query = event.detail.queryString; });
                element.addEventListener('submit', function () {
                    const query = element.dataset.query;
                    if (!query) { return; }
                    element.disablePlayBtn && element.disablePlayBtn();
                    fetch(@json(route('sparql')), {method: 'POST', headers: {'Accept': 'application/sparql-results+json', 'Content-Type': 'application/x-www-form-urlencoded'},
                        body: 'query=' + encodeURIComponent(query)})
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            const vars = data.head.vars;
                            const table = document.createElement('table');
                            const head = table.createTHead().insertRow();
                            vars.forEach(function (v) { const th = document.createElement('th'); th.scope = 'col'; th.textContent = v; head.appendChild(th); });
                            const body = table.createTBody();
                            data.results.bindings.forEach(function (b) {
                                const row = body.insertRow();
                                vars.forEach(function (v) {
                                    const cell = row.insertCell();
                                    if (!b[v]) { return; }
                                    if (b[v].type === 'uri') { const a = document.createElement('a'); a.href = b[v].value; a.textContent = b[v].value; cell.appendChild(a); }
                                    else { cell.textContent = b[v].value; }
                                });
                            });
                            results.replaceChildren(table);
                        })
                        .catch(function () { results.textContent = @json(__('The query could not be answered.')); })
                        .finally(function () { element.enablePlayBtn && element.enablePlayBtn(); });
                });
            })();
        </script>
    @endif
@endsection
