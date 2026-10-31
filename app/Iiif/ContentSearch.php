<?php

// OpenRiC Workspace - IIIF Content Search 2.0 (roadmap phase 14b, ADR 0014): find words in a manifest's text, and
// complete a word as it is typed, so Mirador's search panel works on the Workspace's records.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's IiifContentSearchService. Heratio searches OCR text with word positions; the Workspace keeps an
// instantiation's text as its transcript (typed, or proposed from a PDF's text layer and reviewed; phase 5), with no
// positions. So each hit targets its whole canvas, as Heratio does when it has no word positions, and a
// contextualizing annotation carries the words around it (a TextQuoteSelector), which the specification provides for.
// Only published transcripts of canvases a visitor may receive are searched. Autocomplete answers a TermPage, as the
// specification has it (Heratio answers an AnnotationCollection).

namespace App\Iiif;

use App\Models\RecordResource;

class ContentSearch
{
    public const CONTEXT = 'http://iiif.io/api/search/2/context.json';

    private const MAX_HITS = 200;

    private const CONTEXT_CHARS = 60;

    /** Whether the record has a published transcript on a canvas a visitor may receive. */
    public function searchable(Presentation $presentation, RecordResource $unit): bool
    {
        foreach ($this->texts($presentation, $unit) as $texts) {
            if ($texts !== []) {
                return true;
            }
        }

        return false;
    }

    /** The hits for a query, as an AnnotationPage: each hit on its canvas, with the words around it. */
    public function search(Presentation $presentation, RecordResource $unit, string $query): array
    {
        $manifest = route('iiif.manifest', $unit->id);
        $page = ['@context' => [self::CONTEXT, Presentation::CONTEXT], 'id' => route('iiif.search', [$unit->id, 'q' => $query]), 'type' => 'AnnotationPage', 'items' => []];
        $term = trim($query);
        if (mb_strlen($term) < 2) {
            return $page;
        }
        $contexts = [];
        foreach ($this->texts($presentation, $unit) as $canvas => $texts) {
            foreach ($texts as [$text, $language]) {
                for ($at = mb_stripos($text, $term); $at !== false && count($page['items']) < self::MAX_HITS; $at = mb_stripos($text, $term, $at + mb_strlen($term))) {
                    $n = count($page['items']) + 1;
                    $exact = mb_substr($text, $at, mb_strlen($term));
                    $hit = $manifest.'/search/hit/'.$n;
                    $page['items'][] = ['id' => $hit, 'type' => 'Annotation', 'motivation' => 'highlighting',
                        'body' => ['type' => 'TextualBody', 'value' => $exact, 'format' => 'text/plain', 'language' => $language],
                        'target' => ['type' => 'SpecificResource', 'source' => ['id' => $canvas, 'type' => 'Canvas', 'partOf' => [['id' => $manifest, 'type' => 'Manifest']]]]];
                    $start = max(0, $at - self::CONTEXT_CHARS);
                    $contexts[] = ['id' => $hit.'/context', 'type' => 'Annotation', 'motivation' => 'contextualizing',
                        'target' => ['type' => 'SpecificResource', 'source' => ['id' => $hit, 'type' => 'Annotation'],
                            'selector' => [['type' => 'TextQuoteSelector', 'prefix' => mb_substr($text, $start, $at - $start), 'exact' => $exact,
                                'suffix' => mb_substr($text, $at + mb_strlen($term), self::CONTEXT_CHARS)]]]];
                }
            }
        }
        if ($contexts !== []) {
            $page['annotations'] = [['type' => 'AnnotationPage', 'items' => $contexts]];
        }

        return $page;
    }

    /** Words in the record's text that begin with what has been typed, most frequent first. */
    public function autocomplete(Presentation $presentation, RecordResource $unit, string $query): array
    {
        $prefix = mb_strtolower(trim($query));
        $counts = [];
        if (mb_strlen($prefix) >= 2) {
            foreach ($this->texts($presentation, $unit) as $texts) {
                foreach ($texts as [$text]) {
                    preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'-]*/u', mb_strtolower($text), $words);
                    foreach ($words[0] as $word) {
                        if (str_starts_with($word, $prefix)) {
                            $counts[$word] = ($counts[$word] ?? 0) + 1;
                        }
                    }
                }
            }
        }
        arsort($counts);
        $items = [];
        foreach (array_slice($counts, 0, 20, true) as $word => $total) {
            $items[] = ['value' => (string) $word, 'total' => $total, 'service' => [['id' => route('iiif.search', [$unit->id, 'q' => $word]), 'type' => 'SearchService2']]];
        }

        return ['@context' => self::CONTEXT, 'id' => route('iiif.autocomplete', [$unit->id, 'q' => $query]), 'type' => 'TermPage', 'items' => $items];
    }

    /** @return array<string, list<array{0: string, 1: string}>> canvas id => each published transcript and its language */
    private function texts(Presentation $presentation, RecordResource $unit): array
    {
        $out = [];
        foreach ($presentation->openCanvases($unit) as $canvas => $instantiation) {
            if ($instantiation->availability->notation !== 'online') {
                continue;
            }
            $out[$canvas] = $presentation->view()->values($instantiation, 'instantiation-transcript')
                ->map(fn ($v) => [(string) $v->value, $v->language ?: 'none'])->values()->all();
        }

        return $out;
    }
}
