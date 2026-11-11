<?php

// OpenRiC Workspace - the public SPARQL endpoint over the published RiC-O and the graph explorer on it (roadmap phase
// 16e, ADR 0023): /sparql forwards a query, and only a query (SELECT, ASK, CONSTRUCT or DESCRIBE), to the Workspace's
// Fuseki dataset, within a time limit, CORS-open; /sparnatural/config.ttl is Sparnatural's configuration made from the
// OpenRiC shapes and the relation types; /discover/explore is Sparnatural (sparna-git/Sparnatural 14.0.0, LGPL-3.0),
// the visual query builder FranceArchives and the Archives nationales use over RiC-O, so a visitor explores the graph
// without writing SPARQL. The store holds only what is published, so nothing here shows a draft.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Federation\SparnaturalConfig;
use App\Federation\TripleStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SparqlController extends Controller
{
    /** What may be asked: a query form, after any PREFIX and BASE declarations; never an update. */
    private const QUERY = '/^\s*(?:(?:PREFIX\s+[A-Za-z0-9_.-]*:\s*<[^>]*>|BASE\s+<[^>]*>)\s*)*(SELECT|ASK|CONSTRUCT|DESCRIBE)\b/i';

    private const UPDATE = '/\b(INSERT|DELETE|LOAD|CLEAR|CREATE|DROP|COPY|MOVE|ADD)\s+(DATA|WHERE|GRAPH|SILENT|DEFAULT|NAMED|ALL|\{|<)/i';

    /** A query that chooses its graphs itself: FROM, FROM NAMED or GRAPH. */
    private const NAMES_GRAPHS = '/\b(FROM|GRAPH)\b/i';

    /** Apache Jena's name for the union of a dataset's named graphs. */
    private const UNION_GRAPH = 'urn:x-arq:UnionGraph';

    public function query(Request $request): Response
    {
        if (! TripleStore::configured()) {
            return response(__('No SPARQL endpoint is set up on this installation.'), 503, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $query = (string) ($request->input('query') ?? '');
        if ($query === '' || ! preg_match(self::QUERY, $query) || preg_match(self::UPDATE, $query)) {
            return response(__('Send a SPARQL query (SELECT, ASK, CONSTRUCT or DESCRIBE) as the query parameter; this endpoint answers queries only.'), 400, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $accept = (string) $request->header('Accept', 'application/sparql-results+json');
        $timeout = (int) config('workspace.sparql.timeout_seconds');
        try {
            $answer = Http::timeout($timeout + 5)->withHeaders(['Accept' => $accept])->asForm()
                ->post(TripleStore::dataset().'/query', ['query' => $query, 'timeout' => $timeout * 1000,
                    // Each entity is a named graph of its own (TripleStore), so a query that names no graph is asked of all of
                    // them together, Jena's union graph; the dataset's own default graph is empty. A query with FROM or GRAPH
                    // keeps the graphs it names.
                    ...(preg_match(self::NAMES_GRAPHS, $query) ? [] : ['default-graph-uri' => self::UNION_GRAPH])]);
        } catch (Throwable) {
            return response(__('The SPARQL endpoint did not answer in time.'), 504, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return response($answer->body(), $answer->status(), ['Content-Type' => $answer->header('Content-Type') ?: 'application/sparql-results+json',
            'Access-Control-Allow-Origin' => '*', 'Cache-Control' => 'public, max-age=60']);
    }

    public function config(): Response
    {
        return response(app(SparnaturalConfig::class)->turtle(), 200, ['Content-Type' => 'text/turtle; charset=utf-8', 'Access-Control-Allow-Origin' => '*']);
    }

    public function explore(Request $request): View
    {
        return view('discover.explore', ['institution' => $request->attributes->get('institution'), 'configured' => TripleStore::configured()]);
    }
}
