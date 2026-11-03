<?php

// OpenRiC Workspace - read records, authority records and repositories from RiC-O, as JSON-LD, Turtle or RDF/XML
// (roadmap phase 11b; the round trip of the Portability profile).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The reverse of App\Api\JsonLd, so what the Workspace exported reads back as it was, and a graph from elsewhere is
// read by the same terms. The RDF is parsed by EasyRdf, as App\Api\Rdf writes it, never by hand, so the three
// formats read the same triples. Heratio's RdfImportService parsed Turtle and RDF/XML itself and read a record's
// title, identifier and description; this reads every term JsonLd writes. Each described entity is an entry. One
// only named at the other end of a property (a creator, a holder, the unit above), with no more than its class and
// name, is a reference, found by its IRI. Every property no entry reads is listed in the report.

namespace App\Exchange\Import;

use App\Api\JsonLd;
use App\Relations\RelationTypes;
use DateTimeInterface;
use EasyRdf\Graph;
use EasyRdf\Literal;
use EasyRdf\Resource;
use InvalidArgumentException;
use Throwable;

class RicReader
{
    private const RICO = 'https://www.ica.org/standards/RiC/ontology#';

    private const X = 'https://openric.org/ns/ext/v1#';

    private const RDF = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';

    private const RDFS = 'http://www.w3.org/2000/01/rdf-schema#';

    private const DATE_TYPE = 'https://openric.org/vocab/date-type/';

    /** RiC-O classes of units of description. */
    private const RECORDS = ['RecordSet', 'Record', 'RecordPart'];

    /** RiC-O agent classes and the agent type (notation) each is; rico:Agent alone says nothing more. */
    private const AGENTS = ['Person' => 'person', 'Family' => 'family', 'CorporateBody' => 'corporate-body', 'Position' => 'position', 'Mechanism' => 'mechanism', 'Agent' => null];

    /** The context entities of the Authority & Context profile (roadmap phase 23b): RiC-O class => [table, name element]. */
    private const CONTEXTS = ['Place' => ['places', 'place-name'], 'Rule' => ['rules', 'rule-name'], 'Mandate' => ['rules', 'rule-name'],
        'Activity' => ['activities', 'activity-name'], 'Production' => ['activities', 'activity-name'], 'Accumulation' => ['activities', 'activity-name']];

    /** What an entry already reads itself, so these are not read again as relations. */
    private const OWN = ['rico:hasCreator', 'rico:hasOrHadHolder', 'rico:isOrWasIncludedIn', 'rico:includesOrIncluded', 'rico:isOrWasPartOf'];

    /** @var array<string, array<string, int>> a unit's IRI => the record sets it is also in, each with its place there (from rico:Proxy) */
    private array $proxied = [];

    /** @var array<string, list<array{0: string, 1: string}>> a RiC-O property's IRI => the relation types and the end the entry is */
    private array $properties = [];

    /** Properties only a repository is written with (ISDIAH), which tell a repository from a corporate body. */
    private const REPOSITORY_ONLY = ['contact', 'openingHours', 'accessibility', 'hasOrHadPolicy'];

    /** A contact point's properties and the ContactPoint field each is. */
    private const CONTACT = ['streetAddress' => 'street_address', 'postalCode' => 'postal_code', 'city' => 'city', 'country' => 'country_code',
        'telephone' => 'telephone', 'email' => 'email'];

    /** The date type of a unit's date and the date property it is; any other type is a date of creation. */
    private const RECORD_DATES = ['creation' => 'creation', 'accumulation' => 'accumulation', 'descriptive' => 'modification'];

    private Report $report;

    /** The entry being read, for what end() finds on it. */
    private ?EntityDraft $draft = null;

    private Graph $graph;

    private string $language;

    /** @var array<string, true> the nodes read, by URI or blank node id */
    private array $nodes = [];

    /** @var array<string, true> the properties of the entry being read that were read */
    private array $read = [];

    /** @return list<UnitDraft|EntityDraft> repositories, then authority records, then units, each after the unit above it */
    public function read(string $content, string $language, Report $report): array
    {
        $this->report = $report;
        $this->language = $language;
        $this->graph = $this->parse($content);

        // What some node points at: a node named only as the other end of a property is a reference.
        $pointedAt = $holders = [];
        foreach ($this->graph->resources() as $node) {
            foreach ($node->propertyUris() as $property) {
                foreach ($node->allResources('<'.$property.'>') as $object) {
                    $pointedAt[$object->getUri()] = true;
                    if ($property === self::RICO.'hasOrHadHolder') {
                        $holders[$object->getUri()] = true;
                    }
                }
            }
        }

        $this->properties = $this->relationProperties();
        $this->proxied = $this->proxies();
        $repositories = $agents = $units = $contexts = [];
        foreach ($this->graph->resources() as $node) {
            $classes = $this->classes($node);
            if ($classes === [] || (isset($pointedAt[$node->getUri()]) && $this->onlyNamed($node))) {
                continue;
            }
            if (array_intersect($classes, self::RECORDS) !== []) {
                $units[$node->getUri()] = $node;
            } elseif (($context = array_values(array_intersect($classes, array_keys(self::CONTEXTS)))) !== []) {
                $contexts[] = $this->context($node, $context[0]);
            } elseif (array_intersect($classes, array_keys(self::AGENTS)) !== []) {
                $repository = isset($holders[$node->getUri()]) || array_filter(self::REPOSITORY_ONLY, fn ($p) => $node->hasProperty('<'.self::X.$p.'>'))
                    || (string) $node->getLiteral('<https://openric.org/ns/v1#localType>') === 'repository';
                $repository ? $repositories[] = $this->repository($node) : $agents[] = $this->agent($node, $classes);
            }
        }
        $drafts = [...$repositories, ...$agents, ...$contexts, ...$this->inOrder(array_map(fn (Resource $n) => $this->unit($n, $units), $units))];

        // A described node that no entry read and nothing points at: a class the import has no place for.
        foreach ($this->graph->resources() as $node) {
            if (! isset($this->nodes[$node->getUri()]) && ! isset($pointedAt[$node->getUri()]) && ($classes = $this->classes($node)) !== []) {
                $this->report->issue($node->getUri(), 'rico:'.$classes[0], $this->label($node), 'not read: the Workspace imports records, authority records, repositories, places, rules and activities', 'left-out');
            }
        }

        return $drafts;
    }

    private function parse(string $content): Graph
    {
        $text = ltrim($content);
        $format = match (true) {
            str_starts_with($text, '{') || str_starts_with($text, '[') => 'jsonld',
            str_starts_with($text, '<?xml') || str_starts_with($text, '<rdf:RDF') => 'rdfxml',
            default => 'turtle',
        };
        $graph = new Graph;
        try {
            $graph->parse($content, $format);
        } catch (Throwable $e) {
            throw new InvalidArgumentException('the file is not RiC-O as '.['jsonld' => 'JSON-LD', 'rdfxml' => 'RDF/XML', 'turtle' => 'Turtle'][$format].': '.$e->getMessage());
        }
        if ($graph->countTriples() === 0) {
            throw new InvalidArgumentException('the file holds no triples');
        }

        return $graph;
    }

    private function unit(Resource $node, array $units): UnitDraft
    {
        $draft = new UnitDraft;
        $this->begin($node);
        $draft->iri = $this->iri($node);
        $draft->key = $node->getUri();
        $draft->location = $draft->iri ?? $node->getUri();
        $draft->stableKey = $draft->iri !== null;
        $this->strings($node, 'rico:title', fn ($lang, $text) => $draft->value($lang, 'title', $text));
        $draft->identifier = $this->first($node, 'rico:identifier');
        if ($type = $this->resource($node, 'rico:hasRecordSetType')) {
            $draft->level = $type->getUri();
        } elseif (($local = $this->first($node, 'openric:localType')) !== null && preg_match('/^[a-z0-9-]+$/', $local)) {
            // A record's level, as JsonLd writes it: a level of description of the Workspace's vocabulary.
            $draft->level = 'https://workspace.openric.org/vocab/level-of-description/'.$local;
        }
        if ($extent = $this->resource($node, 'rico:hasExtent')) {
            $this->nodes[$extent->getUri()] = true;
            $this->strings($extent, 'rico:textualValue', fn ($lang, $text) => $draft->value($lang, 'extent', $text));
        }
        foreach (JsonLd::RECORD_TEXT as $notation => $property) {
            $this->strings($node, $property, fn ($lang, $text) => $draft->value($lang, $notation, $text));
        }
        foreach ($this->ranges($node) as $range) {
            $draft->dates[] = ['property' => self::RECORD_DATES[$range['type']] ?? 'creation', 'normalized' => $range['normalized'],
                'expressed' => $range['expressed'], 'language' => $range['language'] ?? $this->language];
        }
        if ($holder = $this->resource($node, 'rico:hasOrHadHolder')) {
            $draft->repositoryIri = $this->iri($holder);
        }
        foreach ($this->resources($node, 'rico:hasCreator') as $creator) {
            $type = null;
            foreach ($this->classes($creator) as $class) {
                $type ??= self::AGENTS[$class] ?? null;
            }
            $draft->creators[] = ['name' => (string) $this->first($creator, 'rico:name', false), 'type' => $type, 'iri' => $this->iri($creator), 'relation' => 'creation', 'history' => null];
        }
        // Where it is arranged: the record set holding no proxy for it; each other is a membership (roadmap phase 24).
        $sets = $this->resources($node, 'rico:isOrWasIncludedIn');
        $proxied = $this->proxied[$node->getUri()] ?? [];
        $parent = collect($sets)->first(fn (Resource $set) => ! isset($proxied[$set->getUri()]));
        if ($parent) {
            isset($units[$parent->getUri()]) ? $draft->parentKey = $parent->getUri() : $draft->parentIri = $this->iri($parent);
        }
        foreach ($sets as $set) {
            if ($set !== $parent) {
                $draft->memberships[] = ['iri' => $set->getUri(), 'position' => $proxied[$set->getUri()] ?? null];
            }
        }
        // A record set's proxies were read with every unit.
        foreach ($this->resources($node, 'rico:includesProxy') as $proxy) {
            $this->nodes[$proxy->getUri()] = true;
        }
        // The units inside say which unit they are in.
        $this->resources($node, 'rico:includesOrIncluded');
        $draft->relations = $this->relationsOf($node);
        $this->end($node, $draft->location);

        return $draft;
    }

    private function agent(Resource $node, array $classes): EntityDraft
    {
        $draft = $this->entity($node, 'agents', 'agent-authorised-name');
        foreach ($classes as $class) {
            $draft->type ??= self::AGENTS[$class] ?? null;
        }
        $this->strings($node, 'rico:name', fn ($lang, $text) => $this->name($draft, $lang, 'agent-authorised-name', $text));
        $this->strings($node, 'openricx:alternativeForm', fn ($lang, $text) => $this->name($draft, $lang, 'agent-parallel-name', $text));
        $this->strings($node, 'openricx:otherName', fn ($lang, $text) => $this->name($draft, $lang, 'agent-other-name', $text));
        foreach (JsonLd::AGENT_TEXT as $notation => $property) {
            $this->strings($node, $property, fn ($lang, $text) => $draft->value($lang, $notation, $text));
        }
        foreach ($this->ranges($node) as $range) {
            if ($range['type'] === 'existence' && $draft->type === 'person') {
                // A person's birth and death are one range of existence.
                $range['beginning'] !== null && $draft->dates[] = ['property' => 'birth', 'normalized' => $range['beginning'], 'expressed' => null];
                $range['end'] !== null && $draft->dates[] = ['property' => 'death', 'normalized' => $range['end'], 'expressed' => null];
            } else {
                $draft->dates[] = ['property' => 'existence', 'normalized' => $range['normalized'], 'expressed' => $range['expressed']];
            }
        }
        $draft->relations = $this->relationsOf($node);
        $this->end($node, $draft->location);

        return $draft;
    }

    private function repository(Resource $node): EntityDraft
    {
        $draft = $this->entity($node, 'repositories', 'authorised-name');
        $this->first($node, 'openric:localType');
        $this->strings($node, 'rico:name', fn ($lang, $text) => $this->name($draft, $lang, 'authorised-name', $text));
        $this->strings($node, 'openricx:alternativeForm', fn ($lang, $text) => $this->name($draft, $lang, 'parallel-name', $text));
        $this->strings($node, 'openricx:otherName', fn ($lang, $text) => $this->name($draft, $lang, 'other-name', $text));
        foreach (JsonLd::REPOSITORY_TEXT as $notation => $property) {
            $this->strings($node, $property, fn ($lang, $text) => $draft->value($lang, $notation, $text));
        }
        if ($contact = $this->resource($node, 'openricx:contact')) {
            $this->nodes[$contact->getUri()] = true;
            foreach (self::CONTACT as $property => $field) {
                if (($value = $this->first($contact, 'openricx:'.$property, false)) !== null) {
                    $draft->contact[$field] = $value;
                }
            }
        }
        $draft->relations = $this->relationsOf($node);
        $this->end($node, $draft->location);

        return $draft;
    }

    /** A place, rule or activity (Authority & Context 3.1 to 3.4), as JsonLd writes them. */
    private function context(Resource $node, string $class): EntityDraft
    {
        [$table, $nameElement] = self::CONTEXTS[$class];
        $draft = $this->entity($node, $table, $nameElement);
        $prefix = substr($nameElement, 0, -strlen('-name'));
        $this->strings($node, 'rico:name', fn ($lang, $text) => $this->name($draft, $lang, $nameElement, $text));
        if ($table === 'rules') {
            $this->strings($node, 'rico:title', fn ($lang, $text) => $draft->values[$lang][$nameElement] ?? $this->name($draft, $lang, $nameElement, $text));
            $this->strings($node, 'dcterms:source', fn ($lang, $text) => $draft->value($lang, 'rule-sources', $text));
            // A link, or the string a rule's own context makes one, which a harvester's merged graph keeps as a literal.
            $draft->typeIri = $this->resource($node, 'rico:hasOrHadRuleType')?->getUri() ?? $this->first($node, 'rico:hasOrHadRuleType');
            $draft->authorityIri = $this->resource($node, 'owl:sameAs')?->getUri();
        }
        // The description, which a place also writes as rico:generalDescription (the same text).
        $this->strings($node, 'openricx:description', fn ($lang, $text) => $draft->value($lang, $prefix.'-description', $text));
        $this->strings($node, 'rico:generalDescription', fn ($lang, $text) => $draft->values[$lang][$prefix.'-description'] ?? $draft->value($lang, $prefix.'-description', $text));
        if ($table === 'activities') {
            $draft->typeIri = $this->resource($node, 'rico:hasActivityType')?->getUri();
        }
        if ($table === 'places') {
            $this->strings($node, 'openricx:alternativeForm', fn ($lang, $text) => $this->name($draft, $lang, 'place-parallel-name', $text));
            $this->strings($node, 'openricx:otherName', fn ($lang, $text) => $this->name($draft, $lang, 'place-other-name', $text));
            // Its location in words; the profile's openricx:streetAddress repeats it.
            $this->strings($node, 'rico:location', fn ($lang, $text) => $draft->value($lang, 'place-location', $text));
            $this->first($node, 'openricx:streetAddress');
            $draft->typeIri = $this->resource($node, 'rico:hasOrHadPlaceType')?->getUri();
            // The coordinates node, which has the altitude too; the profile's flat latitude and longitude repeat it.
            $coordinates = $this->resource($node, 'rico:hasOrHadCoordinates');
            foreach (['latitude', 'longitude', 'altitude'] as $axis) {
                $flat = $axis === 'altitude' ? null : $this->first($node, 'rico:'.$axis);
                $draft->coordinates[$axis] = ($coordinates ? $this->first($coordinates, 'rico:'.$axis, false) : null) ?? $flat;
            }
            if ($coordinates) {
                $this->nodes[$coordinates->getUri()] = true;
            }
            $draft->parentIri = $this->resource($node, 'rico:isOrWasPartOf')?->getUri();
        }
        // The local type repeats the type's notation.
        $this->first($node, 'openric:localType');
        foreach ([...$this->ranges($node), ...$this->ranges($node, 'rico:isAssociatedWithDate')] as $range) {
            $draft->dates[] = ['property' => $range['beginning'] !== null && $range['end'] === null ? 'beginning' : 'associated', 'normalized' => $range['normalized'], 'expressed' => $range['expressed']];
        }
        $draft->relations = $this->relationsOf($node);
        $this->end($node, $draft->location);

        return $draft;
    }

    /**
     * The entry's relations: each property of the relation types' mapping (relation_types.ric_property from the source,
     * ric_inverse from the target), with what its qualified node (rico:thingIsSourceOfRelation) adds: dates, certainty,
     * evidence and the agent's role.
     */
    private function relationsOf(Resource $node): array
    {
        $qualified = [];
        foreach ($this->resources($node, 'rico:thingIsSourceOfRelation') as $relation) {
            $this->nodes[$relation->getUri()] = true;
            $target = $relation->getResource('<'.self::RICO.'relationHasTarget>')?->getUri();
            if ($target === null) {
                continue;
            }
            $range = $this->ranges($relation)[0] ?? null;
            [$from, $to] = $range === null ? [null, null] : ($range['beginning'] !== null || $range['end'] !== null ? [$range['beginning'], $range['end']]
                : (str_contains((string) $range['normalized'], '/') ? explode('/', (string) $range['normalized'], 2) : [$range['normalized'], null]));
            $role = $relation->getResource('<'.self::X.'relationHasAgentRole>')?->getUri();
            $qualified[$target][] = ['class' => $this->classes($relation)[0] ?? 'Relation', 'from' => $from === '..' ? null : $from, 'to' => $to === '..' ? null : $to,
                'certainty' => ($c = $relation->getLiteral('<'.self::RICO.'relationCertainty>')) ? $this->lexical($c) : null,
                'evidence' => array_map(fn ($r) => $r->getUri(), $relation->allResources('<'.self::RICO.'isEvidencedBy>')),
                'role' => $role !== null ? basename($role) : null];
        }
        $relations = [];
        foreach ($node->propertyUris() as $property) {
            if (! isset($this->properties[$property])) {
                continue;
            }
            // What the entry reads itself (a unit's creators) comes again here only to bring its qualified node.
            if (in_array($this->short($property), self::OWN, true)) {
                $q = array_filter($node->allResources('<'.$property.'>'), fn ($o) => isset($qualified[$o->getUri()]));
                if ($q === []) {
                    continue;
                }
            }
            $this->read[$property] = true;
            foreach ($node->allResources('<'.$property.'>') as $other) {
                $ends = $this->properties[$property];
                $q = $qualified[$other->getUri()] ?? [];
                $match = collect($q)->first(fn ($x) => collect($ends)->contains(fn ($e) => RelationTypes::get($e[0], null)['ric_class'] === 'rico:'.$x['class'])) ?? ($q[0] ?? []);
                $relations[] = ['types' => array_map(fn ($e) => $e[0], $ends), 'end' => $ends[0][1], 'iri' => $other->getUri(), 'label' => '',
                    'from' => $match['from'] ?? null, 'to' => $match['to'] ?? null, 'description' => null, 'certainty' => $match['certainty'] ?? null,
                    'evidence' => $match['evidence'] ?? [], 'role' => $match['role'] ?? null];
            }
        }

        // An activity names who performed it as a participant too (Provenance & Event): that is the same relation, read once.
        $performers = collect($relations)->filter(fn ($r) => in_array('performance', $r['types'], true))->pluck('iri')->all();

        return array_values(array_filter($relations, fn ($r) => ! (in_array('participation', $r['types'], true) && in_array($r['iri'], $performers, true))));
    }

    /** @return array<string, array<string, int>> each unit the graph's proxies stand for, the record sets they stand in, and the place in each, from its sequence */
    private function proxies(): array
    {
        $found = $next = [];
        foreach ($this->graph->allOfType('<'.self::RICO.'Proxy>') as $proxy) {
            $for = $proxy->getResource('<'.self::RICO.'proxyFor>')?->getUri();
            $in = $proxy->getResource('<'.self::RICO.'proxyIn>')?->getUri();
            $this->nodes[$proxy->getUri()] = true;
            if ($for !== null && $in !== null) {
                $found[$proxy->getUri()] = [$for, $in];
                $next[$proxy->getUri()] = $proxy->getResource('<'.self::RICO.'proxyDirectlyPrecedesProxyInSequence>')?->getUri();
            }
        }
        // The first of each set's sequence is the one no other proxy precedes; the rest follow it.
        $preceded = array_flip(array_filter($next));
        $places = [];
        foreach (array_keys($found) as $start) {
            if (isset($preceded[$start])) {
                continue;
            }
            for ([$at, $place] = [$start, 1]; $at !== null && isset($found[$at]) && ! isset($places[$at]); [$at, $place] = [$next[$at] ?? null, $place + 1]) {
                $places[$at] = $place;
            }
        }
        $out = [];
        foreach ($found as $proxy => [$for, $in]) {
            $out[$for][$in] = $places[$proxy] ?? 0;
        }

        return $out;
    }

    /** @return array<string, list<array{0: string, 1: string}>> every bundled relation type's property and inverse, by full IRI */
    private function relationProperties(): array
    {
        $map = [];
        foreach (RelationTypes::all(null) as $key => $type) {
            foreach (['source' => $type['ric_property'] ?? null, 'target' => $type['ric_inverse'] ?? null] as $end => $term) {
                if ($term !== null && ! ($end === 'target' && $term === $type['ric_property'])) {
                    $map[trim($this->expand($term), '<>')][] = [$key, $end];
                }
            }
        }

        return $map;
    }

    private function entity(Resource $node, string $table, string $nameElement): EntityDraft
    {
        $draft = $this->draft = new EntityDraft;
        $this->begin($node);
        $draft->table = $table;
        $draft->nameElement = $nameElement;
        $draft->iri = $this->iri($node);
        $draft->key = $node->getUri();
        $draft->location = $draft->iri ?? $node->getUri();
        $draft->identifier = $this->first($node, 'rico:identifier');

        return $draft;
    }

    /** Names of one type are kept one per line. */
    private function name(EntityDraft $draft, string $language, string $element, string $name): void
    {
        $existing = $draft->values[$language][$element] ?? null;
        $draft->value($language, $element, $existing === null ? $name : $existing."\n".$name);
    }

    /**
     * The dates of openricx:hasDateRangeSet (mapping 7.2): each one's EDTF, its bounds, its date type's notation and
     * the date as written.
     *
     * @return list<array{type: ?string, normalized: ?string, beginning: ?string, end: ?string, expressed: ?string, language: ?string}>
     */
    private function ranges(Resource $node, string $property = 'openricx:hasDateRangeSet'): array
    {
        $ranges = [];
        foreach ($this->resources($node, $property) as $range) {
            $this->nodes[$range->getUri()] = true;
            [$beginning, $end] = [$this->first($range, 'rico:beginningDate', false), $this->first($range, 'rico:endDate', false)];
            $normalized = $this->first($range, 'rico:normalizedDateValue', false)
                ?? ($beginning === null && $end === null ? null : ($beginning === $end ? $beginning : ($beginning ?? '..').'/'.($end ?? '..')));
            $expressed = $range->getLiteral('<'.self::RICO.'expressedDate>');
            $type = $range->getResource('<'.self::RICO.'hasDateType>')?->getUri();
            $ranges[] = ['type' => $type !== null && str_starts_with($type, self::DATE_TYPE) ? substr($type, strlen(self::DATE_TYPE)) : null,
                'normalized' => $normalized, 'beginning' => $beginning, 'end' => $end, 'expressed' => $expressed ? $this->lexical($expressed) : null,
                'language' => $expressed?->getLang() === 'und' ? null : $expressed?->getLang()];
        }

        return $ranges;
    }

    /** Each language string of the property: its language (the import's when it has none or says und) and text. */
    private function strings(Resource $node, string $property, callable $each): void
    {
        foreach ($node->allLiterals($this->expand($property)) as $literal) {
            $this->read[trim($this->expand($property), '<>')] = true;
            $language = $literal->getLang();
            $each($language === null || $language === 'und' ? $this->language : $language, $this->lexical($literal));
        }
    }

    private function first(Resource $node, string $property, bool $mark = true): ?string
    {
        $literal = $node->getLiteral($this->expand($property));
        if ($literal !== null && $mark) {
            $this->read[trim($this->expand($property), '<>')] = true;
        }

        return $literal === null || ($text = $this->lexical($literal)) === '' ? null : $text;
    }

    /** A literal as written: EasyRdf makes an xsd:date a DateTime, which the Workspace keeps as its EDTF text. */
    private function lexical(Literal $literal): string
    {
        $value = $literal->getValue();

        return trim($value instanceof DateTimeInterface ? $value->format(str_ends_with((string) $literal->getDatatypeUri(), '#dateTime') ? DATE_ATOM : 'Y-m-d') : (string) $value);
    }

    private function resource(Resource $node, string $property): ?Resource
    {
        return $this->resources($node, $property)[0] ?? null;
    }

    /** @return list<resource> */
    private function resources(Resource $node, string $property): array
    {
        $found = $node->allResources($this->expand($property));
        if ($found !== []) {
            $this->read[trim($this->expand($property), '<>')] = true;
        }

        return $found;
    }

    private function begin(Resource $node): void
    {
        $this->nodes[$node->getUri()] = true;
        $this->read = [self::RDF.'type' => true];
    }

    /** List each property of the entry no one read, once per property. */
    private function end(Resource $node, string $location): void
    {
        // A name or title as its own appellation repeats a name the shorthand properties gave (rico:name, rico:title and the
        // like), and adds the dates it was used, kept on the draft for the import to put on the name it makes.
        foreach (['rico:hasOrHadAgentName', 'rico:hasOrHadPlaceName', 'rico:hasOrHadTitle'] as $property) {
            foreach ($this->resources($node, $property) as $name) {
                $this->nodes[$name->getUri()] = true;
                $text = $name->getLiteral('<'.self::RICO.'textualValue>');
                [$from, $to] = [$name->getLiteral('<'.self::RICO.'usedFromDate>'), $name->getLiteral('<'.self::RICO.'usedToDate>')];
                if ($text !== null && ($from !== null || $to !== null) && $this->draft !== null) {
                    $this->draft->nameDates[] = ['value' => $this->lexical($text), 'language' => $text->getLang(),
                        'from' => $from ? $this->lexical($from) : null, 'to' => $to ? $this->lexical($to) : null];
                }
            }
        }
        foreach ($node->propertyUris() as $property) {
            if (! isset($this->read[$property])) {
                $value = $node->get('<'.$property.'>');
                $this->report->issue($location, $this->short($property), $value instanceof Literal ? mb_substr($this->lexical($value), 0, 200) : ($value instanceof Resource ? $value->getUri() : null),
                    'not read: the Workspace has no place for it here', 'left-out');
            }
        }
    }

    /** @return list<string> the node's RiC-O class names, without the namespace */
    private function classes(Resource $node): array
    {
        $classes = [];
        foreach ($node->allResources('<'.self::RDF.'type>') as $type) {
            if (str_starts_with($type->getUri(), self::RICO)) {
                $classes[] = substr($type->getUri(), strlen(self::RICO));
            }
        }

        return $classes;
    }

    /** Whether the node says no more than its class and name: a reference to something described elsewhere. */
    private function onlyNamed(Resource $node): bool
    {
        return array_diff($node->propertyUris(), [self::RDF.'type', self::RICO.'name', self::RICO.'title', self::RDFS.'label']) === [];
    }

    private function label(Resource $node): ?string
    {
        return $this->first($node, 'rico:name', false) ?? $this->first($node, 'rico:title', false);
    }

    /** The node's IRI when it is an HTTP IRI, not a blank node. */
    private function iri(Resource $node): ?string
    {
        return $node->isBNode() || ! preg_match('~^https?://~', $node->getUri()) ? null : $node->getUri();
    }

    private function expand(string $curie): string
    {
        [$prefix, $local] = explode(':', $curie, 2);

        return '<'.(JsonLd::CONTEXT[$prefix] ?? throw new InvalidArgumentException("unknown prefix $prefix")).$local.'>';
    }

    private function short(string $iri): string
    {
        foreach (JsonLd::CONTEXT as $prefix => $namespace) {
            if (str_starts_with($iri, $namespace)) {
                return $prefix.':'.substr($iri, strlen($namespace));
            }
        }

        return $iri;
    }

    /**
     * Each unit after the unit above it, when that unit is in the file too.
     *
     * @param  list<UnitDraft>  $units
     * @return list<UnitDraft>
     */
    private function inOrder(array $units): array
    {
        $byKey = [];
        foreach ($units as $unit) {
            $byKey[$unit->key] = $unit;
        }
        $ordered = $placed = [];
        $place = function (UnitDraft $unit, array $seen = []) use (&$place, &$ordered, &$placed, $byKey) {
            if (isset($placed[$unit->key]) || isset($seen[$unit->key])) {
                return;
            }
            if ($unit->parentKey !== null && isset($byKey[$unit->parentKey])) {
                $place($byKey[$unit->parentKey], [...$seen, $unit->key => true]);
            }
            $placed[$unit->key] = true;
            $ordered[] = $unit;
        };
        foreach ($units as $unit) {
            $place($unit);
        }

        return $ordered;
    }
}
