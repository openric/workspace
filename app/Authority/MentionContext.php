<?php

/**
 * ContextDerivationService - Service for Heratio
 *
 * Pure-PHP analyzer. Given a source text + a mention value + the co-occurring
 * NER entities for the same object, derives the neighbourhood-context packet
 * stored in ahg_mention_context: offsets, surrounding text, co-occurring
 * entities filtered by paragraph proximity, nearby dates/places, and
 * role-language tokens within range.
 *
 * No DB calls. No I/O. Single public method: derive().
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * This file is part of Heratio, released under the GNU Affero General Public License, version 3 or later.
 *
 * OpenRiC Workspace - copied from Heratio, packages/ahg-authority-resolution/src/Services/ContextDerivationService.php
 * at commit bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md, roadmap phase 17b2). Changed:
 * offsets are in characters (mb_* functions), as spaCy's are, rather than bytes, so no window is cut mid-character;
 * the Workspace's code style.
 */

declare(strict_types=1);

namespace App\Authority;

class MentionContext
{
    private const SURROUNDING_TEXT_WINDOW = 150;

    private const ROLE_LANGUAGE_CHAR_WINDOW = 120;

    private const PLACE_TYPES = ['GPE', 'LOC', 'PLACE'];

    private const DATE_TYPES = ['DATE'];

    /**
     * Derive the full context packet for a mention.
     *
     * @param  list<array{value:string,type:string}>  $otherEntities
     * @param  array<string,list<string>>  $roleLanguageTokens  e.g. ['kinship' => ['son of', 'daughter of'], ...]
     * @param  array{start:int,end:int}|null  $knownOffset  exact character offsets from the NER answer (entities_v2)
     * @return array{offset_start: ?int, offset_end: ?int, paragraph_start: ?int, paragraph_end: ?int, text_before: ?string, text_after: ?string,
     *   co_occurring: list<array>, nearby_dates: list<array>, nearby_places: list<array>, role_tokens: list<array>, occurrences: int}
     */
    public function derive(string $sourceText, string $mentionValue, array $otherEntities, array $roleLanguageTokens, ?array $knownOffset = null): array
    {
        $occurrences = $knownOffset !== null && isset($knownOffset['start'], $knownOffset['end'])
            ? [[(int) $knownOffset['start'], (int) $knownOffset['end']]]
            : $this->findAllOccurrences($sourceText, $mentionValue);
        if ($occurrences === []) {
            return $this->emptyContext();
        }
        // The first occurrence; a repeated name is flagged by its count for the reviewer.
        [$start, $end] = $occurrences[0];
        $paragraph = $this->findEnclosingParagraph($sourceText, $start);
        $coOccurring = $this->findEntitiesInParagraph($paragraph, $otherEntities, $start);

        return [
            'offset_start' => $start, 'offset_end' => $end, 'paragraph_start' => $paragraph['start'], 'paragraph_end' => $paragraph['end'],
            'text_before' => mb_substr($sourceText, max(0, $start - self::SURROUNDING_TEXT_WINDOW), $start - max(0, $start - self::SURROUNDING_TEXT_WINDOW)),
            'text_after' => mb_substr($sourceText, $end, self::SURROUNDING_TEXT_WINDOW),
            'co_occurring' => $this->excludeTypes($coOccurring, [...self::DATE_TYPES, ...self::PLACE_TYPES]),
            'nearby_dates' => $this->partitionByTypes($coOccurring, self::DATE_TYPES),
            'nearby_places' => $this->partitionByTypes($coOccurring, self::PLACE_TYPES),
            'role_tokens' => $this->findRoleLanguage($paragraph, $start, $roleLanguageTokens),
            'occurrences' => count($occurrences),
        ];
    }

    /** @return list<array{0:int,1:int}> */
    private function findAllOccurrences(string $haystack, string $needle): array
    {
        if ($needle === '' || $haystack === '') {
            return [];
        }
        $found = [];
        $offset = 0;
        $needleLen = mb_strlen($needle);
        while (($pos = mb_stripos($haystack, $needle, $offset)) !== false) {
            $found[] = [$pos, $pos + $needleLen];
            $offset = $pos + $needleLen;
        }

        return $found;
    }

    /** @return array{start:int,end:int,text:string} */
    private function findEnclosingParagraph(string $sourceText, int $offset): array
    {
        $before = mb_substr($sourceText, 0, $offset);
        $after = mb_substr($sourceText, $offset);
        // Paragraph break = two consecutive newlines (with optional whitespace).
        $start = 0;
        if (preg_match_all('/\n\s*\n/u', $before, $matches, PREG_OFFSET_CAPTURE)) {
            $last = end($matches[0]);
            $start = mb_strlen(substr($before, 0, $last[1])) + mb_strlen($last[0]);
        }
        $end = mb_strlen($sourceText);
        if (preg_match('/\n\s*\n/u', $after, $matches, PREG_OFFSET_CAPTURE)) {
            $end = $offset + mb_strlen(substr($after, 0, $matches[0][1]));
        }

        return ['start' => $start, 'end' => $end, 'text' => mb_substr($sourceText, $start, $end - $start)];
    }

    /**
     * @param  array{start:int,end:int,text:string}  $paragraph
     * @param  list<array{value:string,type:string}>  $others
     * @return list<array>
     */
    private function findEntitiesInParagraph(array $paragraph, array $others, int $mentionAbsOffset): array
    {
        $hits = [];
        $mentionInPara = $mentionAbsOffset - $paragraph['start'];
        foreach ($others as $other) {
            if (($other['value'] ?? '') === '') {
                continue;
            }
            $pos = mb_stripos($paragraph['text'], $other['value']);
            if ($pos === false) {
                continue;
            }
            $hits[] = ['value' => $other['value'], 'type' => $other['type'], 'offset_start' => $paragraph['start'] + $pos, 'distance_chars' => abs($pos - $mentionInPara)];
        }

        return $hits;
    }

    /** @return list<array> */
    private function partitionByTypes(array $entities, array $types): array
    {
        return array_values(array_filter($entities, fn ($e) => in_array($e['type'] ?? '', $types, true)));
    }

    /** @return list<array> */
    private function excludeTypes(array $entities, array $types): array
    {
        return array_values(array_filter($entities, fn ($e) => ! in_array($e['type'] ?? '', $types, true)));
    }

    /**
     * @param  array{start:int,end:int,text:string}  $paragraph
     * @param  array<string,list<string>>  $tokenList
     * @return list<array{token:string,kind:string,position_offset:int,distance_chars:int}>
     */
    private function findRoleLanguage(array $paragraph, int $mentionAbsOffset, array $tokenList): array
    {
        $hits = [];
        $mentionInPara = $mentionAbsOffset - $paragraph['start'];
        foreach ($tokenList as $kind => $tokens) {
            foreach ((array) $tokens as $token) {
                if (! is_string($token) || $token === '') {
                    continue;
                }
                $offset = 0;
                while (($pos = mb_stripos($paragraph['text'], $token, $offset)) !== false) {
                    $distance = abs($pos - $mentionInPara);
                    if ($distance <= self::ROLE_LANGUAGE_CHAR_WINDOW) {
                        $hits[] = ['token' => $token, 'kind' => (string) $kind, 'position_offset' => $paragraph['start'] + $pos, 'distance_chars' => $distance];
                    }
                    $offset = $pos + mb_strlen($token);
                }
            }
        }

        return $hits;
    }

    private function emptyContext(): array
    {
        return ['offset_start' => null, 'offset_end' => null, 'paragraph_start' => null, 'paragraph_end' => null, 'text_before' => null, 'text_after' => null,
            'co_occurring' => [], 'nearby_dates' => [], 'nearby_places' => [], 'role_tokens' => [], 'occurrences' => 0];
    }
}
