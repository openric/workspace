<?php

// OpenRiC Workspace - the published RiC-O kept in a SPARQL triple store (roadmap phase 16e, ADR 0023): each published unit
// of description, authority record, activity, place, rule and repository, as the public API gives it, is a named graph
// of its own (the graph's name is the entity's IRI) in the Workspace's Apache Jena Fuseki dataset, written by the SPARQL
// 1.1 Graph Store Protocol; an entity deprecated, or no longer published, has its graph dropped. The store is brought up
// to date from what changed since its last sync, and in full on request. Only what is published ever goes in, so the
// store and the query endpoint over it never hold a draft.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Federation;

use App\Api\JsonLd;
use App\Exchange\ExportView;
use App\Models\Activity;
use App\Models\Agent;
use App\Models\Deprecation;
use App\Models\Institution;
use App\Models\Place;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\Rule;
use Carbon\CarbonImmutable;
use EasyRdf\Graph;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TripleStore
{
    /** The kinds put in the store, by their search entries' table. */
    private const KINDS = ['record_resources' => [RecordResource::class, 'record'], 'agents' => [Agent::class, 'agent'], 'activities' => [Activity::class, 'activity'],
        'places' => [Place::class, 'place'], 'rules' => [Rule::class, 'rule'], 'repositories' => [Repository::class, 'repository']];

    public static function configured(): bool
    {
        return (string) config('workspace.sparql.url') !== '';
    }

    /** The dataset's address, under the server's. */
    public static function dataset(): string
    {
        return rtrim((string) config('workspace.sparql.url'), '/').'/'.trim((string) config('workspace.sparql.dataset'), '/');
    }

    /**
     * Bring the store up to date for the institution: what changed since its last sync, or everything; the counts.
     *
     * @return array{put: int, dropped: int}
     */
    public function sync(Institution $institution, bool $full = false): array
    {
        self::configured() || throw new RuntimeException(__('No SPARQL triple store is set up (WORKSPACE_SPARQL_URL).'));
        $state = DB::table('sparql_sync_state')->where('institution_id', $institution->id)->first();
        // Search entries are stamped with the time their transaction began, so one still open when the last sync began is
        // committed with an earlier time; looking back a quarter of an hour before it takes those in, and putting a graph
        // again changes nothing.
        $since = $full || ! $state ? null : CarbonImmutable::parse($state->last_synced_at)->subMinutes(15);
        $started = now();
        $view = new ExportView(null, false, null, $institution);
        $put = $dropped = 0;
        $public = [];
        foreach (self::KINDS as $table => [$class, $method]) {
            $ids = DB::table('search_entries')->where(['institution_id' => $institution->id, 'public' => true, 'subject_table' => $table])
                ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))->pluck('subject_id');
            foreach ($ids->chunk(100) as $chunk) {
                foreach ($class::whereIn('id', $chunk)->whereNull('deleted_at')->get() as $entity) {
                    if (! $view->readable($entity)) {
                        continue;
                    }
                    $this->put($entity->iri, (new JsonLd($view))->{$method}($entity));
                    $public[$entity->iri] = true;
                    $put++;
                }
            }
        }
        // What is deprecated since leaves the store; in full, so does any graph of the institution no longer published.
        foreach (Deprecation::where(['institution_id' => $institution->id, 'was_public' => true])->when($since, fn ($q) => $q->where('deprecated_at', '>=', $since))->pluck('iri') as $iri) {
            $this->drop($iri);
            $dropped++;
        }
        if ($full) {
            foreach ($this->graphs(rtrim($institution->base_iri, '/').'/') as $graph) {
                if (! isset($public[$graph])) {
                    $this->drop($graph);
                    $dropped++;
                }
            }
        }
        DB::table('sparql_sync_state')->upsert([['institution_id' => $institution->id, 'last_synced_at' => $started, 'last_put' => $put, 'last_dropped' => $dropped]],
            ['institution_id'], ['last_synced_at', 'last_put', 'last_dropped']);

        return ['put' => $put, 'dropped' => $dropped];
    }

    /** Replace an entity's graph with its RiC-O. */
    public function put(string $iri, array $document): void
    {
        $graph = new Graph($iri);
        $graph->parse(json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'jsonld', $iri);
        $response = $this->http()->withBody($graph->serialise('ntriples'), 'application/n-triples')->put(self::dataset().'/data?graph='.rawurlencode($iri));
        $response->successful() || throw new RuntimeException(__('The triple store answered HTTP :status.', ['status' => $response->status()]));
    }

    public function drop(string $iri): void
    {
        $response = $this->http()->delete(self::dataset().'/data?graph='.rawurlencode($iri));
        // A graph that was never there is gone already.
        ($response->successful() || $response->status() === 404) || throw new RuntimeException(__('The triple store answered HTTP :status.', ['status' => $response->status()]));
    }

    /** @return list<string> the named graphs whose names begin with the prefix */
    public function graphs(string $prefix): array
    {
        $query = 'SELECT DISTINCT ?g WHERE { GRAPH ?g { ?s ?p ?o } FILTER(STRSTARTS(STR(?g), "'.addcslashes($prefix, '"\\').'")) }';
        $response = $this->http()->asForm()->accept('application/sparql-results+json')->post(self::dataset().'/query', ['query' => $query]);
        $response->successful() || throw new RuntimeException(__('The triple store answered HTTP :status.', ['status' => $response->status()]));

        return array_values(array_filter(array_map(fn ($b) => $b['g']['value'] ?? null, (array) $response->json('results.bindings', []))));
    }

    private function http(): PendingRequest
    {
        $http = Http::timeout(60);

        return config('workspace.sparql.user') ? $http->withBasicAuth((string) config('workspace.sparql.user'), (string) config('workspace.sparql.password')) : $http;
    }
}
