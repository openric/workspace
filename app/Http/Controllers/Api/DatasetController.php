<?php

// OpenRiC Workspace - the self-describing dataset (Portability 3) and its change feed (Portability 4, Governance 6),
// at /api/ric/v1/dataset and /api/ric/v1/changelog (roadmap phase 11b, ADR 0013).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's RicDatasetService: a DCAT and VoID descriptor naming every distribution the service offers and the
// standards it is pinned to, and the changes to the published Linked Data, newest first. The descriptor follows the
// specification's dataset.schema.json, which Heratio's does not (it types the dataset twice and gives each access URL
// as a node). There is no SPARQL endpoint to name, and no licence is claimed, as an institution sets none here.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deprecation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DatasetController extends Controller
{
    /** The standards the published Linked Data is pinned to (the governance pin), by IRI. */
    public const PINNED = [
        'https://www.ica.org/standards/RiC/ontology' => 'RiC-O 1.1 (2025-05-22)',
        'http://www.w3.org/2004/02/skos/core' => 'SKOS (W3C Recommendation, 2009)',
        'https://openric.org/ns/ext/v1' => 'OpenRiC extension ontology 1.6.0',
    ];

    /** The namespaces the published data uses. */
    public const VOCABULARIES = ['https://www.ica.org/standards/RiC/ontology#', 'https://openric.org/ns/ext/v1#', 'https://openric.org/ns/v1#',
        'http://www.w3.org/2004/02/skos/core#', 'http://purl.org/dc/terms/', 'http://www.w3.org/ns/prov#'];

    public const EXTENSION_NOTE = 'The data is RiC-O 1.1, extended where RiC-O stops by the OpenRiC extension ontology (openricx:, https://openric.org/ns/ext/v1#): date ranges and their sets, other and alternative forms of names, contact points, containers and carriers, checksums, the status of an assertion and the receipt of a model\'s inference, among others. Each title and name is also a rico:Title or rico:AgentName node of its own, one a language, beside its language-tagged rico:title or rico:name. An agent\'s authority record is a rico:Record of its own, of the documentary form Authority record, resulting from the activity of describing the agent. A consumer reading only RiC-O 1.1 loses the openricx: statements and nothing else.';

    public function dataset(Request $request): JsonResponse
    {
        $institution = $request->attributes->get('institution');
        // Each record's export is at /records/{UUID}/export: a URI template is not a URI, so its access URL is the list it is reached from.
        $records = route('api.records');
        $export = fn (string $format) => 'Each record and what it reaches, as '.$format.', at /records/{UUID}/export';
        $distribution = fn (string $title, string $url, string $type) => ['@type' => 'dcat:Distribution', 'dcterms:title' => $title, 'dcat:accessURL' => $url, 'dcat:mediaType' => $type];

        return $this->json([
            '@context' => ['dcat' => 'http://www.w3.org/ns/dcat#', 'dcterms' => 'http://purl.org/dc/terms/', 'void' => 'http://rdfs.org/ns/void#',
                'foaf' => 'http://xmlns.com/foaf/0.1/', 'rdfs' => 'http://www.w3.org/2000/01/rdf-schema#'],
            '@id' => route('api.dataset'),
            '@type' => 'dcat:Dataset',
            'dcterms:title' => $institution->name.': RiC-O Linked Data',
            'dcterms:description' => 'The published archival descriptions, authority records and repositories of '.$institution->name.', as RiC-O 1.1 in OpenRiC JSON-LD, Turtle and RDF/XML, from the OpenRiC Workspace.',
            'dcterms:publisher' => ['@type' => 'foaf:Organization', 'foaf:name' => $institution->name],
            'dcat:version' => trim((string) @file_get_contents(base_path('VERSION'))) ?: 'unknown',
            'dcterms:conformsTo' => collect(self::PINNED)->map(fn ($label, $iri) => ['@id' => $iri, 'rdfs:label' => $label])->values()->all(),
            // The vocabularies the data uses, and what the extension adds, so a consumer can take it into account where
            // RiC-O 1.1 stops (Richard Williamson, EGAD, Records in Contexts users list, 6 November 2026).
            'void:vocabulary' => array_map(fn ($iri) => ['@id' => $iri], self::VOCABULARIES),
            'rdfs:comment' => self::EXTENSION_NOTE,
            'void:uriSpace' => rtrim($institution->base_iri, '/').'/',
            'void:rootResource' => ['@id' => route('api.records')],
            'dcat:distribution' => [
                $distribution('Records, agents and repositories as OpenRiC JSON-LD (Core Discovery)', route('api.records'), 'application/ld+json'),
                $distribution($export('JSON-LD (?format=jsonld)'), $records, 'application/ld+json'),
                $distribution($export('Turtle (?format=ttl)'), $records, 'text/turtle'),
                $distribution($export('RDF/XML (?format=rdf)'), $records, 'application/rdf+xml'),
                $distribution('OAI-PMH 2.0 harvesting (oai_dc and rico_ld)', route('api.oai'), 'application/xml'),
            ],
            'rdfs:seeAlso' => ['@id' => route('api.changelog')],
        ]);
    }

    /** Released changes to the published Linked Data, newest first, with the pinned standards and the governance pin. */
    public function changelog(Request $request): JsonResponse
    {
        $institution = $request->attributes->get('institution');

        return $this->json([
            'dataset_version' => trim((string) @file_get_contents(base_path('VERSION'))) ?: 'unknown',
            'openric_spec_version' => config('workspace.openric_spec_version'),
            'pinned_standards' => self::PINNED,
            'governance_pin' => 'https://github.com/openric/workspace/blob/main/conformance/governance-pin.md',
            'deprecated_entities' => Deprecation::where(['institution_id' => $institution->id, 'was_public' => true])->count(),
            'changes' => json_decode((string) file_get_contents(resource_path('linked-data-changes.json')), true, 512, JSON_THROW_ON_ERROR),
        ]);
    }

    private function json(array $body): JsonResponse
    {
        return response()->json($body, 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
