<?php

// OpenRiC Workspace - Sparnatural's configuration, made from the OpenRiC SHACL shapes the Workspace's RiC-O conforms to
// (roadmap phase 16e, ADR 0023), in the Sparnatural SHACL configuration profile: each RiC-O class a shape targets is an
// entity to explore; each property a shape constrains to another such class is a link between entities; each literal it
// constrains is a property searched as text, or as a date where its datatype is one; the name or title of an entity is
// its label. The links between entities are also those the Workspace's relation types say, each by its RiC-O property
// between the RiC-O classes of its kinds, named as the relation type is. Made once and cached; nothing in it is written
// by hand, so it follows the pinned shapes and the relation types.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Federation;

use App\Relations\RelationTypes;
use EasyRdf\Graph;
use EasyRdf\RdfNamespace;
use EasyRdf\Resource;
use Illuminate\Support\Facades\Cache;

class SparnaturalConfig
{
    public const SHAPES = 'shapes/openric-0.56.0';

    private const NS = ['sh' => 'http://www.w3.org/ns/shacl#', 'dash' => 'http://datashapes.org/dash#', 'volipi' => 'http://data.sparna.fr/ontologies/volipi#',
        'core' => 'http://data.sparna.fr/ontologies/sparnatural-config-core#', 'rico' => 'https://www.ica.org/standards/RiC/ontology#', 'openricx' => 'https://openric.org/ns/ext/v1#'];

    /** The properties that name an entity, as Sparnatural shows it. */
    private const LABELS = ['https://www.ica.org/standards/RiC/ontology#title', 'https://www.ica.org/standards/RiC/ontology#name', 'http://www.w3.org/2000/01/rdf-schema#label'];

    /** The RiC-O classes of each kind of entity a relation type joins. */
    private const KIND_CLASSES = ['record' => ['Record', 'RecordSet', 'RecordPart'], 'agent' => ['Person', 'Family', 'CorporateBody'], 'repository' => ['CorporateBody'],
        'place' => ['Place'], 'activity' => ['Activity'], 'rule' => ['Rule'], 'instantiation' => ['Instantiation'],
        // The kinds of the relations between people and groups (membership, family, leadership, positions): without them a
        // reader could not ask who was a member of a society. Accessions, agreements and containers are not in the store.
        'person' => ['Person'], 'group' => ['CorporateBody', 'Family'], 'position' => ['Position']];

    private const DATES = ['http://www.w3.org/2001/XMLSchema#date', 'http://www.w3.org/2001/XMLSchema#dateTime', 'http://www.w3.org/2001/XMLSchema#gYear'];

    public function turtle(): string
    {
        // Made again when the shapes change or a release does, since a release can change how it is made.
        return Cache::remember('sparnatural:config:'.md5(filemtime(resource_path(self::SHAPES)).'|'.trim((string) @file_get_contents(base_path('VERSION')))), 86400,
            fn () => $this->build()->serialise('turtle'));
    }

    public function build(): Graph
    {
        foreach (self::NS as $prefix => $ns) {
            RdfNamespace::set($prefix, $ns);
        }
        $shapes = new Graph;
        foreach (glob(resource_path(self::SHAPES.'/*.ttl')) as $file) {
            $shapes->parse((string) file_get_contents($file), 'turtle');
        }
        // Each RiC-O or OpenRiC class a shape targets, with the property shapes of every shape that targets it.
        $classes = [];
        foreach ($shapes->allOfType('sh:NodeShape') as $shape) {
            foreach ($shape->allResources('sh:targetClass') as $class) {
                $iri = $class->getUri();
                if (str_starts_with($iri, self::NS['rico']) || (str_starts_with($iri, self::NS['openricx']) && ! str_ends_with($iri, 'DateRange'))) {
                    $classes[$iri] = [...($classes[$iri] ?? []), ...$shape->allResources('sh:property')];
                }
            }
        }
        // The classes the relation types join are entities too, each named by its name or title.
        $links = [];
        foreach (rescue(fn () => RelationTypes::all(null), [], false) as $type) {
            $property = (string) ($type['ric_property'] ?? '');
            if ($property === '' || ! empty($type['deprecated'])) {
                continue;
            }
            $property = str_starts_with($property, 'rico:') ? self::NS['rico'].substr($property, 5) : (str_starts_with($property, 'openricx:') ? self::NS['openricx'].substr($property, 9) : $property);
            foreach ($type['pairs'] as [$source, $target]) {
                foreach (self::KIND_CLASSES[$source] ?? [] as $from) {
                    foreach (self::KIND_CLASSES[$target] ?? [] as $to) {
                        $links[self::NS['rico'].$from][] = [$property, self::NS['rico'].$to, (string) $type['forward']];
                        $classes[self::NS['rico'].$from] ??= [];
                        $classes[self::NS['rico'].$to] ??= [];
                    }
                }
            }
        }
        ksort($classes);
        $out = new Graph;
        $order = 0;
        foreach ($classes as $class => $properties) {
            $entity = $out->resource('https://workspace.openric.org/sparnatural#'.self::local($class), 'sh:NodeShape');
            $entity->add('sh:targetClass', $out->resource($class));
            $entity->addLiteral('rdfs:label', self::words(self::local($class)), 'en');
            $entity->addLiteral('sh:order', ++$order);
            $seen = [];
            foreach ($properties as $property) {
                $path = $property->getResource('sh:path');
                if (! $path instanceof Resource || $path->isBNode() || isset($seen[$path->getUri()])) {
                    continue;
                }
                $target = $property->getResource('sh:class')?->getUri()
                    ?? $property->getResource('sh:node')?->getResource('sh:targetClass')?->getUri();
                $datatype = $property->getResource('sh:datatype')?->getUri();
                $isLabel = in_array($path->getUri(), self::LABELS, true);
                if (($target === null || ! isset($classes[$target])) && $datatype === null && ! $isLabel) {
                    continue;
                }
                $seen[$path->getUri()] = true;
                $shape = $out->newBNode();
                $shape->add('sh:path', $out->resource($path->getUri()));
                $shape->addLiteral('sh:name', self::words(self::local($path->getUri())), 'en');
                if ($target !== null && isset($classes[$target])) {
                    $shape->add('sh:node', $out->resource('https://workspace.openric.org/sparnatural#'.self::local($target)));
                } else {
                    $shape->add('sh:datatype', $out->resource($datatype ?? 'http://www.w3.org/2001/XMLSchema#string'));
                    $shape->add('dash:searchWidget', $out->resource(self::NS['core'].(in_array($datatype, self::DATES, true) ? 'TimeProperty-Date' : 'SearchProperty')));
                    $isLabel && $shape->add('dash:propertyRole', $out->resource(self::NS['dash'].'LabelRole'));
                }
                $entity->add('sh:property', $shape);
            }
            if (! array_intersect(array_keys($seen), self::LABELS)) {
                // Every entity has its name or title to be shown and searched by.
                $label = $out->newBNode();
                $label->add('sh:path', $out->resource(in_array(self::local($class), ['Record', 'RecordSet', 'RecordPart', 'Instantiation'], true) ? self::LABELS[0] : self::LABELS[1]));
                $label->addLiteral('sh:name', in_array(self::local($class), ['Record', 'RecordSet', 'RecordPart', 'Instantiation'], true) ? 'title' : 'name', 'en');
                $label->add('sh:datatype', $out->resource('http://www.w3.org/2001/XMLSchema#string'));
                $label->add('dash:searchWidget', $out->resource(self::NS['core'].'SearchProperty'));
                $label->add('dash:propertyRole', $out->resource(self::NS['dash'].'LabelRole'));
                $entity->add('sh:property', $label);
            }
            foreach ($links[$class] ?? [] as [$path, $target, $name]) {
                if (isset($seen[$path.' '.$target])) {
                    continue;
                }
                $seen[$path.' '.$target] = true;
                $link = $out->newBNode();
                $link->add('sh:path', $out->resource($path));
                $link->addLiteral('sh:name', $name, 'en');
                $link->add('sh:node', $out->resource('https://workspace.openric.org/sparnatural#'.self::local($target)));
                $entity->add('sh:property', $link);
            }
        }

        return $out;
    }

    private static function local(string $iri): string
    {
        return (string) preg_replace('/^.*[#\/]/', '', $iri);
    }

    /** CorporateBody to "corporate body", hasOrHadHolder to "has or had holder". */
    private static function words(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/(?<!^)([A-Z])/', ' $1', $name)));
    }
}
