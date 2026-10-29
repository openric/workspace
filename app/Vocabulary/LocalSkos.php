<?php

// OpenRiC Workspace - SKOS export and import of an institution's own vocabulary.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Heratio exports its taxonomies as SKOS in RDF/XML, Turtle, N-Triples and
// JSON-LD and imports SKOS files; so does the Workspace. A concept is typed
// both skos:Concept and the RiC-O class of its vocabulary's kind (config
// workspace.scheme_kinds). Notes map to SKOS (definition, scopeNote,
// historyNote, note for AtoM's display note) and to dcterms:source for AtoM's
// source note. A deprecated concept is exported with owl:deprecated and
// dcterms:isReplacedBy, as the bundled vocabularies are.
//
// Importing never takes over another vocabulary's IRIs: every imported
// concept gets the institution's IRI, and the IRI it had becomes an exact
// match, by which importing the same file again updates instead of
// duplicating. A file exported from this vocabulary is matched by its own IRIs.

namespace App\Vocabulary;

use App\Models\Concept;
use App\Models\ConceptMatch;
use App\Models\User;
use App\Models\Vocabulary;
use App\Relations\RelationTypes;
use App\Support\Vocab;
use EasyRdf\Graph;
use EasyRdf\Literal;
use EasyRdf\Resource;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LocalSkos
{
    /** format => [EasyRdf format name, media type, file extension] */
    public const FORMATS = [
        'turtle' => ['turtle', 'text/turtle', 'ttl'],
        'rdfxml' => ['rdfxml', 'application/rdf+xml', 'rdf'],
        'ntriples' => ['ntriples', 'application/n-triples', 'nt'],
        'jsonld' => ['jsonld', 'application/ld+json', 'jsonld'],
    ];

    /** Formats a file may be imported from; JSON-LD needs a library the Workspace does not carry. */
    public const IMPORT_FORMATS = ['turtle', 'rdfxml', 'ntriples'];

    private const SKOS = 'http://www.w3.org/2004/02/skos/core#';

    private const DCT = 'http://purl.org/dc/terms/';

    private const MATCHES = ['exact' => 'exactMatch', 'close' => 'closeMatch', 'broad' => 'broadMatch', 'narrow' => 'narrowMatch', 'related' => 'relatedMatch'];

    /** The SKOS property of each note column. */
    private const NOTE_PROPERTIES = ['definition' => 'skos:definition', 'scope_note' => 'skos:scopeNote', 'history_note' => 'skos:historyNote',
        'display_note' => 'skos:note', 'source_note' => 'dcterms:source'];

    public function __construct(private ConceptService $concepts) {}

    public function export(Vocabulary $vocabulary, string $format): string
    {
        $format = self::FORMATS[$format] ?? throw new InvalidArgumentException("$format is not an export format");
        $graph = $this->graph($vocabulary);

        return $format[0] === 'jsonld' ? $this->jsonLd($graph) : $graph->serialise($format[0]);
    }

    public function graph(Vocabulary $vocabulary): Graph
    {
        $graph = new Graph;
        $scheme = $graph->resource($vocabulary->iri, 'skos:ConceptScheme');
        $scheme->addLiteral('dcterms:title', new Literal($vocabulary->title, $vocabulary->language));
        if ($vocabulary->description) {
            $scheme->addLiteral('dcterms:description', new Literal($vocabulary->description, $vocabulary->language));
        }
        $ricClass = config('workspace.scheme_kinds.'.$vocabulary->kind->notation);
        $concepts = $vocabulary->concepts()->with('labels', 'altLabels', 'matches.matchType', 'broader', 'replacedBy')->get();
        foreach ($concepts as $concept) {
            $node = $graph->resource($concept->iri, 'skos:Concept');
            if ($ricClass) {
                $node->addType($ricClass);
            }
            $node->addResource('skos:inScheme', $scheme);
            // An institution's own relation type narrows a bundled one.
            if ($base = RelationTypes::get($concept->id, $vocabulary->institution_id)) {
                $node->addResource('skos:broadMatch', $graph->resource(Concept::find($base['base_concept_id'])->iri));
            }
            if ($concept->notation !== null) {
                $node->addLiteral('skos:notation', $concept->notation);
            }
            foreach ($concept->labels as $label) {
                $node->addLiteral('skos:prefLabel', new Literal($label->pref_label, $label->language));
                foreach (self::NOTE_PROPERTIES as $column => $property) {
                    if ($label->{$column} !== null) {
                        $node->addLiteral($property, new Literal($label->{$column}, $label->language));
                    }
                }
            }
            foreach ($concept->altLabels as $alt) {
                $node->addLiteral($alt->is_hidden ? 'skos:hiddenLabel' : 'skos:altLabel', new Literal($alt->label, $alt->language));
            }
            if ($concept->broader) {
                $node->addResource('skos:broader', $concept->broader->iri);
                $graph->resource($concept->broader->iri)->addResource('skos:narrower', $node);
            } elseif ($concept->deprecated_at === null) {
                $node->addResource('skos:topConceptOf', $scheme);
                $scheme->addResource('skos:hasTopConcept', $node);
            }
            foreach ($concept->related() as $related) {
                $node->addResource('skos:related', $related->iri);
            }
            foreach ($concept->matches as $match) {
                $node->addResource('skos:'.self::MATCHES[$match->matchType->notation], $match->target_iri);
            }
            if ($concept->deprecated_at !== null) {
                $node->addLiteral('owl:deprecated', true);
                if ($concept->replacedBy) {
                    $node->addResource('dcterms:isReplacedBy', $concept->replacedBy->iri);
                }
            }
        }

        return $graph;
    }

    /**
     * Import every skos:Concept of a SKOS file into the vocabulary; the account is the actor.
     *
     * @return array{created: int, updated: int}
     */
    public function import(Vocabulary $vocabulary, string $content, string $format, ?User $actor = null): array
    {
        if (! in_array($format, self::IMPORT_FORMATS, true)) {
            throw new InvalidArgumentException(__('import a SKOS file in Turtle, RDF/XML or N-Triples'));
        }
        $graph = new Graph;
        try {
            $graph->parse($content, self::FORMATS[$format][0]);
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('the file could not be read as '.$format.': '.$e->getMessage());
        }
        $resources = $graph->allOfType('skos:Concept');
        if ($resources === []) {
            throw new InvalidArgumentException(__('the file has no skos:Concept'));
        }

        return DB::transaction(function () use ($vocabulary, $resources, $actor) {
            $count = ['created' => 0, 'updated' => 0];
            $byIri = [];
            foreach ($resources as $resource) {
                $byLanguage = $this->byLanguage($resource);
                if ($byLanguage === []) {
                    continue;
                }
                $notation = $resource->getLiteral('skos:notation')?->getValue();
                $concept = $this->existing($vocabulary, $resource->getUri());
                $count[$concept === null ? 'created' : 'updated']++;
                foreach ($byLanguage as $language => $fields) {
                    $fields['notation'] = $notation ?? $concept?->notation;
                    if ($concept === null) {
                        $concept = $this->concepts->create($vocabulary, ['language' => $language] + $fields, $actor);
                    } else {
                        $this->concepts->update($concept, $language, ['broader' => $concept->broader_id] + $fields, $actor);
                    }
                }
                $byIri[$resource->getUri()] = [$concept->fresh(), $resource];
            }
            // Links, now that every concept exists.
            foreach ($byIri as $iri => [$concept, $resource]) {
                if ($concept->iri !== $iri && ! ConceptMatch::where(['concept_id' => $concept->id, 'target_iri' => $iri])->exists()) {
                    $this->concepts->addMatch($concept, 'exact', $iri, null, null, $actor);
                }
                $broader = $resource->getResource('skos:broader');
                if ($broader && isset($byIri[$broader->getUri()]) && $concept->broader_id !== $byIri[$broader->getUri()][0]->id) {
                    $concept->update(['broader_id' => $byIri[$broader->getUri()][0]->id]);
                }
                foreach ($resource->allResources('skos:related') as $related) {
                    if (isset($byIri[$related->getUri()])) {
                        $this->concepts->relate($concept, $byIri[$related->getUri()][0], $actor);
                    }
                }
                foreach (self::MATCHES as $type => $property) {
                    foreach ($resource->allResources('skos:'.$property) as $target) {
                        if (! isset($byIri[$target->getUri()]) && ! ConceptMatch::where(['concept_id' => $concept->id, 'target_iri' => $target->getUri()])->exists()) {
                            $this->concepts->addMatch($concept, $type, $target->getUri(), null, null, $actor);
                        }
                    }
                }
            }

            return $count;
        });
    }

    /** The concept of this vocabulary the file's concept is: by its own IRI, or by an exact match to the file's IRI. */
    private function existing(Vocabulary $vocabulary, string $iri): ?Concept
    {
        return Concept::where(['vocabulary_id' => $vocabulary->id, 'iri' => $iri])->first()
            ?? Concept::where('vocabulary_id', $vocabulary->id)->whereHas('matches', fn ($q) => $q->where('target_iri', $iri)
                ->whereHas('matchType', fn ($q) => $q->where('iri', Vocab::MATCH_TYPES.'exact')))->first();
    }

    /** @return array<string, array<string, string>> language => the fields ConceptService takes, for each language with a preferred label */
    private function byLanguage(Resource $resource): array
    {
        $out = [];
        $put = function (string $property, string $field, bool $lines = false) use ($resource, &$out) {
            foreach ($resource->allLiterals($property) as $literal) {
                $language = $literal->getLang() ?: 'und';
                $value = trim((string) $literal->getValue());
                $out[$language][$field] = $lines && isset($out[$language][$field]) ? $out[$language][$field]."\n".$value : $value;
            }
        };
        $put('skos:prefLabel', 'label');
        foreach (self::NOTE_PROPERTIES as $column => $property) {
            $put($property, $column);
        }
        $put('skos:altLabel', 'alt_labels', true);
        $put('skos:hiddenLabel', 'hidden_labels', true);
        $out = array_filter($out, fn (array $fields) => isset($fields['label']));
        foreach ($out as &$fields) {
            $fields += ['alt_labels' => '', 'hidden_labels' => ''];
        }

        return $out;
    }

    /** Expanded JSON-LD: one node object for each subject, which any JSON-LD processor reads. */
    private function jsonLd(Graph $graph): string
    {
        $nodes = [];
        foreach ($graph->toRdfPhp() as $subject => $properties) {
            $node = ['@id' => $subject];
            foreach ($properties as $property => $values) {
                $key = $property === 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type' ? '@type' : $property;
                foreach ($values as $value) {
                    $node[$key][] = $key === '@type' ? $value['value'] : match ($value['type']) {
                        'uri', 'bnode' => ['@id' => $value['value']],
                        default => array_filter(['@value' => $value['value'], '@language' => $value['lang'] ?? null, '@type' => $value['datatype'] ?? null]),
                    };
                }
            }
            $nodes[] = $node;
        }

        return json_encode($nodes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }
}
