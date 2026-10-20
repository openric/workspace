<?php

// OpenRiC Workspace - load one SKOS concept scheme into the vocabulary tables.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Importing is idempotent: concepts are matched by IRI, so running it again
// with the same source changes nothing, and a newer version of the source
// updates labels and links in place. A concept the source no longer lists is
// reported, never deleted (OpenRiC vocabularies are strictly additive, and the
// database refuses deletion anyway).

namespace App\Vocabulary;

use App\Models\Concept;
use App\Models\ConceptLabel;
use App\Models\Vocabulary;
use EasyRdf\Graph;
use EasyRdf\Literal;
use EasyRdf\Resource;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SkosImporter
{
    private const SKOS = 'http://www.w3.org/2004/02/skos/core#';

    public function import(string $turtle, ?string $sourceUrl = null): ImportResult
    {
        $graph = new Graph;
        $graph->parse($turtle, 'turtle', $sourceUrl);

        $schemes = $graph->allOfType('skos:ConceptScheme');
        if (count($schemes) !== 1) {
            throw new InvalidArgumentException('expected exactly one skos:ConceptScheme, found '.count($schemes));
        }
        $scheme = $schemes[0];
        $concepts = array_values(array_filter(
            $graph->allOfType('skos:Concept'),
            fn (Resource $c) => $c->hasProperty('skos:inScheme', $scheme),
        ));
        if ($concepts === []) {
            throw new InvalidArgumentException('the scheme '.$scheme->getUri().' has no concepts');
        }

        return DB::transaction(function () use ($scheme, $concepts, $sourceUrl) {
            $vocabulary = Vocabulary::updateOrCreate(
                ['iri' => $scheme->getUri()],
                ['title' => $this->title($scheme), 'source_url' => $sourceUrl, 'imported_at' => now()],
            );

            $result = new ImportResult($vocabulary);
            $byIri = [];
            $outcome = [];
            foreach ($concepts as $resource) {
                $concept = Concept::firstOrNew(['iri' => $resource->getUri()]);
                if ($concept->exists && $concept->vocabulary_id !== $vocabulary->id) {
                    throw new InvalidArgumentException($resource->getUri().' already belongs to another vocabulary');
                }
                $concept->vocabulary_id = $vocabulary->id;
                $concept->notation = $this->notation($resource, $scheme->getUri());
                $concept->deprecated_at = $this->isDeprecated($resource) ? ($concept->deprecated_at ?? now()) : null;
                $outcome[$resource->getUri()] = $concept->exists ? ($concept->isDirty() ? 'updated' : 'unchanged') : 'created';
                $concept->save();
                $byIri[$concept->iri] = $concept;
            }

            // Second pass: links between concepts, now that every one exists.
            foreach ($concepts as $resource) {
                $concept = $byIri[$resource->getUri()];
                $concept->broader_id = $this->linked($resource, 'skos:broader', $byIri);
                $concept->replaced_by_id = $this->linked($resource, 'dcterms:isReplacedBy', $byIri);
                // A change made only in this pass (a new broader concept, say) still counts as an update.
                if ($concept->isDirty() && $outcome[$concept->iri] === 'unchanged') {
                    $outcome[$concept->iri] = 'updated';
                }
                $concept->save();
                $this->labels($concept, $resource);
            }

            foreach ($outcome as $kind) {
                $result->count($kind);
            }
            $result->missing = Concept::where('vocabulary_id', $vocabulary->id)
                ->whereNotIn('iri', array_keys($byIri))->pluck('iri')->all();

            return $result;
        });
    }

    private function title(Resource $scheme): string
    {
        foreach (['dcterms:title', 'skos:prefLabel'] as $property) {
            $literal = $scheme->getLiteral($property, 'en') ?? $scheme->getLiteral($property);
            if ($literal) {
                return (string) $literal->getValue();
            }
        }

        return $scheme->getUri();
    }

    private function notation(Resource $concept, string $schemeIri): string
    {
        if ($literal = $concept->getLiteral('skos:notation')) {
            return (string) $literal->getValue();
        }
        $iri = $concept->getUri();
        $local = str_starts_with($iri, $schemeIri) ? substr($iri, strlen($schemeIri)) : '';

        return $local !== '' ? ltrim($local, '#/') : basename(parse_url($iri, PHP_URL_PATH) ?: $iri);
    }

    private function isDeprecated(Resource $concept): bool
    {
        $literal = $concept->getLiteral('owl:deprecated');

        return $literal !== null && filter_var($literal->getValue(), FILTER_VALIDATE_BOOLEAN);
    }

    private function linked(Resource $concept, string $property, array $byIri): ?string
    {
        $target = $concept->getResource($property);
        if ($target === null) {
            return null;
        }

        return ($byIri[$target->getUri()] ?? Concept::where('iri', $target->getUri())->first())?->id;
    }

    private function labels(Concept $concept, Resource $resource): void
    {
        $rows = [];
        foreach (['pref_label' => 'skos:prefLabel', 'definition' => 'skos:definition', 'scope_note' => 'skos:scopeNote'] as $column => $property) {
            foreach ($resource->allLiterals($property) as $literal) {
                // An untagged literal is "und", the BCP 47 tag for an undetermined language.
                $rows[$this->language($literal)][$column] = (string) $literal->getValue();
            }
        }
        $rows = array_filter($rows, fn (array $row) => isset($row['pref_label']));

        ConceptLabel::where('concept_id', $concept->id)->whereNotIn('language', array_keys($rows))->delete();
        foreach ($rows as $language => $row) {
            ConceptLabel::updateOrCreate(
                ['concept_id' => $concept->id, 'language' => $language],
                ['pref_label' => $row['pref_label'], 'definition' => $row['definition'] ?? null, 'scope_note' => $row['scope_note'] ?? null],
            );
        }
    }

    private function language(Literal $literal): string
    {
        return $literal->getLang() ?: 'und';
    }
}
