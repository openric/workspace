<?php

// OpenRiC Workspace - the same triples as Turtle or RDF/XML (Export-Only 3.3).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Every format carries the same triple set as the JSON-LD. The Workspace's JSON-LD uses a small part of the language: one
// context of prefixes (and terms whose strings are IRIs), @id, @type, value objects with @language or @type, nested nodes
// and @graph. Those are read into the graph directly, as JSON-LD's own algorithm reads them (#7): the general processor
// (ml/json-ld, through EasyRdf) took about 3 ms a triple, two minutes for a fonds of 10,000 items. A document with anything
// else is handed to the general processor as before; RdfTest holds both to the same graph.

namespace App\Api;

use EasyRdf\Graph;
use EasyRdf\Literal;
use EasyRdf\RdfNamespace;
use UnexpectedValueException;

final class Rdf
{
    private const XSD = 'http://www.w3.org/2001/XMLSchema#';

    private const RDF_TYPE = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';

    /** Documents read by the general processor since the process began, for RdfTest to see the direct path taken. */
    public static int $fallbacks = 0;

    /** @var array<string, string> prefix => namespace */
    private array $prefixes = [];

    /** @var array<string, true> expanded properties whose strings are IRIs */
    private array $iris = [];

    private function __construct(private Graph $graph) {}

    public static function graph(array $jsonLd): Graph
    {
        foreach (JsonLd::CONTEXT as $prefix => $namespace) {
            RdfNamespace::set($prefix, $namespace);
        }
        try {
            $reader = new self(new Graph);
            $reader->document($jsonLd);

            return $reader->graph;
        } catch (UnexpectedValueException) {
            // Not the Workspace's part of JSON-LD: the general processor, as for every document before #7.
            self::$fallbacks++;
            $graph = new Graph;
            $graph->parse(json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'jsonld');

            return $graph;
        }
    }

    /** @param  string  $format  turtle, rdfxml or ntriples */
    public static function serialise(array $jsonLd, string $format): string
    {
        return self::graph($jsonLd)->serialise($format);
    }

    /** A document: its context, then its node, or each node of its @graph. */
    private function document(array $doc): void
    {
        foreach ((array) ($doc['@context'] ?? []) as $term => $definition) {
            if (is_string($definition) && ! str_contains($term, ':')) {
                $this->prefixes[$term] = $definition;
            } elseif ($definition === ['@type' => '@id']) {
                $this->iris[$term] = true; // expanded once every prefix is known, below
            } else {
                throw new UnexpectedValueException('context');
            }
        }
        $this->iris = array_fill_keys(array_map(fn ($t) => $this->expand($t), array_keys($this->iris)), true);
        unset($doc['@context']);
        if (array_key_exists('@graph', $doc)) {
            if (count($doc) !== 1 || ! array_is_list($doc['@graph'])) {
                throw new UnexpectedValueException('graph');
            }
            foreach ($doc['@graph'] as $node) {
                $this->node($node);
            }

            return;
        }
        $this->node($doc);
    }

    /** A node object: its subject (its @id, or a blank node), its types and each property's values. */
    private function node(array $node): string
    {
        if (array_is_list($node)) {
            throw new UnexpectedValueException('node');
        }
        $subject = isset($node['@id']) ? $this->expand((string) $node['@id']) : $this->graph->newBNodeId();
        foreach ($node as $key => $value) {
            if ($key === '@id') {
                continue;
            }
            if ($key === '@type') {
                foreach ((array) $value as $type) {
                    $this->graph->addResource($subject, self::RDF_TYPE, $this->expand((string) $type));
                }

                continue;
            }
            if (str_starts_with($key, '@')) {
                throw new UnexpectedValueException($key);
            }
            $property = $this->expandOrNull($key);
            if ($property === null) {
                continue; // a key that is no IRI is dropped, as JSON-LD drops it
            }
            foreach (is_array($value) && array_is_list($value) ? $value : [$value] as $v) {
                $this->value($subject, $property, $v);
            }
        }

        return $subject;
    }

    private function value(string $subject, string $property, mixed $v): void
    {
        match (true) {
            $v === null => null,
            is_string($v) && isset($this->iris[$property]) => $this->graph->addResource($subject, $property, $this->expand($v)),
            is_string($v) => $this->graph->addLiteral($subject, $property, new Literal($v, null, self::XSD.'string')),
            is_bool($v) => $this->graph->addLiteral($subject, $property, new Literal($v ? 'true' : 'false', null, self::XSD.'boolean')),
            is_int($v) => $this->graph->addLiteral($subject, $property, new Literal((string) $v, null, self::XSD.'integer')),
            is_array($v) && array_key_exists('@value', $v) => $this->literal($subject, $property, $v),
            is_array($v) && ! array_is_list($v) && array_intersect(array_keys($v), ['@list', '@set', '@reverse', '@graph']) === [] => $this->graph->addResource($subject, $property, $this->node($v)),
            default => throw new UnexpectedValueException('value'), // a double, a list, a nested list: the general processor
        };
    }

    /** @param  array{'@value': mixed, '@language'?: string, '@type'?: string}  $v */
    private function literal(string $subject, string $property, array $v): void
    {
        if ($v['@value'] === null) {
            return;
        }
        if (array_diff(array_keys($v), ['@value', '@language', '@type']) !== [] || ! is_string($v['@value']) || (isset($v['@language']) && isset($v['@type']))) {
            throw new UnexpectedValueException('literal');
        }
        $this->graph->addLiteral($subject, $property, isset($v['@language']) ? new Literal($v['@value'], (string) $v['@language'])
            : new Literal($v['@value'], null, isset($v['@type']) ? $this->expand((string) $v['@type']) : self::XSD.'string'));
    }

    private function expand(string $term): string
    {
        return $this->expandOrNull($term) ?? throw new UnexpectedValueException('term '.$term);
    }

    /** A compact IRI with a known prefix, an absolute IRI, or a blank node label as it is; null for anything else. */
    private function expandOrNull(string $term): ?string
    {
        if (str_starts_with($term, '_:')) {
            return $term;
        }
        $at = strpos($term, ':');
        if ($at === false) {
            return null;
        }
        $prefix = substr($term, 0, $at);
        if (isset($this->prefixes[$prefix]) && ! str_starts_with(substr($term, $at + 1), '//')) {
            return $this->prefixes[$prefix].substr($term, $at + 1);
        }

        return preg_match('/^[a-z][a-z0-9+.-]*:/i', $term) ? $term : null;
    }
}
