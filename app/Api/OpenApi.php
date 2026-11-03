<?php

// OpenRiC Workspace - an OpenAPI 3.1 description of the Workspace's API (roadmap phase 22c), after Heratio's ahg-api
// OpenApiGenerator. It is built from the API's own routes each time it is asked for, so it never describes an
// address the API does not have; each operation has a summary, its path parameters and the key it needs.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Api;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

class OpenApi
{
    /** What each operation does, by route name. */
    private const SUMMARIES = [
        'api.service' => 'The service description and the OpenRiC profiles it declares',
        'api.health' => 'Whether the service is up',
        'api.vocabulary' => 'The vocabularies the service uses',
        'api.records' => 'Units of description, paged and filtered',
        'api.record' => 'One unit of description as RiC-O JSON-LD',
        'api.export' => 'One unit of description exported (JSON-LD, Turtle or RDF/XML)',
        'api.files' => 'The files of a unit of description the caller may receive',
        'api.oai' => 'OAI-PMH 2.0',
        'api.agents' => 'Authority records', 'api.agent' => 'One authority record',
        'api.repositories' => 'Repositories', 'api.repository' => 'One repository',
        'api.autocomplete' => 'Entities whose names begin with the text given',
        'api.places' => 'Places', 'api.place' => 'One place',
        'api.rules' => 'Rules and mandates', 'api.rule' => 'One rule',
        'api.activities' => 'Activities', 'api.activity' => 'One activity',
        'api.instantiations' => 'Instantiations', 'api.instantiation' => 'One instantiation',
        'api.functions' => 'Functions', 'api.function' => 'One function',
        'api.graph' => 'The relationship graph around an entity',
        'api.relations' => 'Relations', 'api.relations-for' => 'The relations of one entity',
        'api.hierarchy' => 'The hierarchy above and below a unit of description',
        'api.dataset' => 'The dataset descriptor', 'api.changelog' => 'The change feed',
        'api.proposals.store' => 'Propose values, entities or relations for a record, for an archivist to review (needs the write scope)',
        'api.proposals' => 'The proposals sent with this key',
        'api.intake' => 'A capture station\'s files made a record (needs the write scope)',
        'api.openapi' => 'This description',
        'api.graphql' => 'GraphQL queries over what the caller may read (read only)',
        'api.places.within' => 'Places within a bounding box or radius, as GeoJSON',
    ];

    public function document(): array
    {
        $paths = [];
        /** @var Route $route */
        foreach (Router::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/ric/v1') || ! $route->getName()) {
                continue;
            }
            $path = '/'.ltrim(substr($route->uri(), strlen('api/ric/v1')), '/');
            $scope = collect($route->gatherMiddleware())->map(fn ($m) => is_string($m) && str_contains($m, 'AuthenticateApiKey:') ? substr($m, strrpos($m, ':') + 1) : null)->filter()->first();
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $paths[$path === '' ? '/' : $path][strtolower($method)] = array_filter([
                    'operationId' => $route->getName().($method === 'POST' && in_array('GET', $route->methods(), true) ? '.post' : ''),
                    'summary' => self::SUMMARIES[$route->getName()] ?? null,
                    'parameters' => array_map(fn ($p) => ['name' => $p, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']], $route->parameterNames()) ?: null,
                    'security' => $scope ? [['apiKey' => []]] : [[], ['apiKey' => []]],
                    'responses' => ['200' => ['description' => 'Done'], '401' => ['description' => 'A key is needed, or the key may not do this'], '404' => ['description' => 'Not here, or not yours to read']],
                ], fn ($v) => $v !== null);
            }
        }
        ksort($paths);

        return ['openapi' => '3.1.0',
            'info' => ['title' => 'OpenRiC Workspace API', 'version' => trim((string) @file_get_contents(base_path('VERSION'))) ?: 'dev',
                'description' => 'The read API of an OpenRiC Workspace: RiC-O JSON-LD for every kind of entity, OAI-PMH, the graph, the dataset and its changes, GraphQL; proposals and intake for keys that may write. Writes through the API are proposals an archivist reviews (ADR 0007).',
                'license' => ['name' => 'AGPL-3.0-or-later', 'identifier' => 'AGPL-3.0-or-later']],
            'servers' => [['url' => url('/api/ric/v1')]],
            'components' => ['securitySchemes' => ['apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key']]],
            'paths' => $paths];
    }
}
