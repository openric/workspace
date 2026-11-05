<?php

/**
 * GraphGroundingService - Heratio
 *
 * GraphRAG disambiguation layer (#1320).
 * Turns a resolved RiC entity IRI into an authoritative "grounding pack" -
 * type, label, dates, key properties, relations and provenance, read straight
 * from the Fuseki graph (the source-of-truth projection) via SparqlQueryService.
 * KM / an agent injects this into its grounding prompt so it resolves entities
 * to authority facts instead of guessing (vectors for retrieval + ontology
 * graph for disambiguation).
 * Read-only. Never invents: a fact appears only if it is in the graph.
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * This file is part of Heratio, released under the GNU Affero General Public License, version 3 or later.
 *
 * OpenRiC Workspace - copied from Heratio, packages/ahg-semantic-search/src/Services/GraphGroundingService.php at commit
 * bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md, roadmap phase 17d1, the OpenRiC
 * Graph-Grounding profile). Changed: a pack is read from the entity's public RiC-O JSON-LD, the projection Fuseki is fed
 * from, so it holds only what a visitor may see; entities are found by the public search (by words, and by meaning
 * where the institution searches by meaning) rather than a SPARQL label match. groundingText() is Heratio's.
 */

declare(strict_types=1);

namespace App\Api;

use App\Search\SearchService;

class Grounding
{
    /** The JSON-LD writer for each kind the search finds. */
    private const WRITERS = ['record_resources' => ['records', 'record'], 'agents' => ['agents', 'agent'], 'repositories' => ['repositories', 'repository'],
        'places' => ['places', 'place'], 'activities' => ['activities', 'activity']];

    public function __construct(private PublicCatalogue $catalogue) {}

    /**
     * Resolve a free-text query to the public entities it names and their grounding packs, with a ready-to-inject block.
     *
     * @return array{query: string, entities: list<array>, grounding_text: string}
     */
    public function groundQuery(string $query, int $maxEntities = 5): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['query' => $query, 'entities' => [], 'grounding_text' => ''];
        }
        $found = app(SearchService::class)->search($this->catalogue->institution, $query, true, [], 1, $maxEntities * 3, ['mode' => 'both'])['results'];
        $packs = [];
        foreach ($found as $hit) {
            if (count($packs) >= $maxEntities) {
                break;
            }
            if (($pack = $this->groundEntity($hit['table'], $hit['id'])) !== null) {
                $packs[] = $pack;
            }
        }

        return ['query' => $query, 'entities' => $packs, 'grounding_text' => $this->groundingText($packs)];
    }

    /**
     * Build the grounding pack for one public entity.
     *
     * @return array{iri: string, types: array, label: ?string, dates: array, properties: array, relations: array, provenance: array}|null
     */
    public function groundEntity(string $table, string $id): ?array
    {
        [$kind, $writer] = self::WRITERS[$table] ?? [null, null];
        $entity = $kind ? $this->catalogue->find($kind, $id) : null;
        if ($entity === null || ($table === 'activities' && $entity->is_function)) {
            return null;
        }
        $doc = (new JsonLd($this->catalogue->view))->{$writer}($entity, false);
        [$label, $dates, $props, $relations, $provenance] = [null, [], [], [], []];
        foreach ($doc as $property => $value) {
            if (in_array($property, ['@id', '@type', '@context'], true)) {
                continue;
            }
            foreach (is_array($value) && array_is_list($value) ? $value : [$value] as $v) {
                $text = is_array($v) ? ($v['@value'] ?? null) : $v;
                if (is_array($v) && isset($v['@id'])) {
                    $relations[] = $this->localName($property).' -> '.(self::text($v['rico:name'] ?? $v['rico:title'] ?? $v['rdfs:label'] ?? null) ?? $v['@id']);
                    if (str_starts_with($property, 'prov:')) {
                        $provenance[] = $this->localName($property).': '.$v['@id'];
                    }
                } elseif (is_array($v) && str_starts_with($property, 'prov:')) {
                    $provenance[] = $this->localName($property).': '.json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } elseif (is_scalar($text) && (string) $text !== '') {
                    if ($this->propMatches($property, ['name', 'title', 'label'])) {
                        $label ??= (string) $text;
                    } elseif ($this->propMatches($property, ['date', 'beginning', 'end', 'created'])) {
                        $dates[] = $this->localName($property).': '.$text;
                    } else {
                        $props[$this->localName($property)] = (string) $text;
                    }
                } elseif (is_array($v) && $this->propMatches($property, ['date'])) {
                    $dates[] = $this->localName($property).': '.trim(implode(' ', array_filter(array_map(fn ($x) => is_array($x) ? ($x['@value'] ?? null) : (is_scalar($x) ? $x : null), $v))));
                }
            }
        }

        return ['iri' => (string) $doc['@id'], 'types' => array_values(array_unique((array) ($doc['@type'] ?? []))), 'label' => $label,
            'dates' => array_values(array_unique($dates)), 'properties' => $props, 'relations' => array_values(array_unique($relations)), 'provenance' => array_values(array_unique($provenance))];
    }

    /**
     * Render one or more grounding packs into a compact, prompt-ready block.
     * The framing tells the model these are authoritative facts to ground on.
     */
    public function groundingText(array $packs): string
    {
        $packs = array_values(array_filter($packs));
        if ($packs === []) {
            return '';
        }
        $out = ["Authoritative facts from the archive's knowledge graph (use these to disambiguate; do not contradict or invent beyond them):"];
        foreach ($packs as $p) {
            $line = '- '.($p['label'] ?? $p['iri']);
            if (! empty($p['types'])) {
                $line .= ' ('.implode('/', $p['types']).')';
            }
            $line .= ' ['.$p['iri'].']';
            if (! empty($p['dates'])) {
                $line .= '; '.implode(', ', $p['dates']);
            }
            if (! empty($p['relations'])) {
                $line .= '; relations: '.implode(', ', array_slice($p['relations'], 0, 8));
            }
            if (! empty($p['provenance'])) {
                $line .= '; provenance: '.implode(', ', $p['provenance']);
            }
            $out[] = $line;
        }

        return implode("\n", $out);
    }

    /** A literal's text, whether plain, a value object or a list of them (language-tagged names). */
    private static function text(mixed $value): ?string
    {
        return match (true) {
            is_scalar($value) => (string) $value,
            is_array($value) && isset($value['@value']) => (string) $value['@value'],
            is_array($value) && array_is_list($value) && $value !== [] => self::text($value[0]),
            default => null,
        };
    }

    private function propMatches(string $prop, array $needles): bool
    {
        $local = mb_strtolower($this->localName($prop));
        foreach ($needles as $needle) {
            if (str_contains($local, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function localName(string $iri): string
    {
        $pos = max((int) strrpos($iri, '#'), (int) strrpos($iri, '/'), (int) strrpos($iri, ':'));

        return $pos > 0 ? substr($iri, $pos + 1) : $iri;
    }
}
