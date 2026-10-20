<?php

// OpenRiC Workspace - the same triples as Turtle or RDF/XML (Export-Only 3.3).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The other serialisations are made from the JSON-LD by an RDF library
// (EasyRdf, with ml/json-ld processing the JSON-LD), never written by hand,
// so every format carries the same triple set.

namespace App\Api;

use EasyRdf\Graph;
use EasyRdf\RdfNamespace;

final class Rdf
{
    public static function graph(array $jsonLd): Graph
    {
        foreach (JsonLd::CONTEXT as $prefix => $namespace) {
            RdfNamespace::set($prefix, $namespace);
        }
        $graph = new Graph;
        $graph->parse(json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'jsonld');

        return $graph;
    }

    /** @param  string  $format  turtle, rdfxml or ntriples */
    public static function serialise(array $jsonLd, string $format): string
    {
        return self::graph($jsonLd)->serialise($format);
    }
}
