<?php

// OpenRiC Workspace - read units of description from CSV in AtoM's ISAD(G) columns.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The columns are those App\Exchange\DescriptionCsv writes: AtoM's
// archival description template and the four Workspace columns, so a file
// exported from AtoM, Heratio or the Workspace reads back. A row whose
// legacyId was already read is a translation: its values are written in its
// culture. Every column the import does not read is listed in the report,
// once, and so is every value it cannot take as given.

namespace App\Exchange\Import;

use App\Description\Bcp47;
use App\Description\Elements;
use App\Description\IsoCodes;
use App\Exchange\DescriptionCsv;

class CsvReader
{
    /** AtoM's event types and the date property and creator relation each becomes. */
    private const EVENTS = ['creation' => 'creation', 'accumulation' => 'accumulation'];

    /** Columns that hold several entries, separated by |. */
    private const SPLIT = '|';

    /**
     * Columns AtoM's own export adds to its import template, and what becomes of them; an empty
     * reason means the column is read with another.
     */
    private const ATOM_EXPORT = [
        'slug' => 'AtoM\'s web address name for a unit is not kept: the Workspace mints a persistent IRI, and legacyId keys the unit to its source',
        'digitalObjectChecksum' => '',
        'eventDescriptions' => 'a note on an AtoM event is not kept: a creator is a relation and a date is a date; not read',
        'eventPlaces' => 'the place of an AtoM event is not kept: a creator is a relation, not an activity with a place; not read',
    ];

    /**
     * The rows of a CSV file as column => cell, with the line each starts on. A cell loses the control
     * characters XML cannot carry (each removal is reported), and AtoM's NULL, which stands for
     * nothing in a list of several, reads as empty.
     *
     * @param  list<string>  $needs  columns of which the header must have at least one
     * @return \Generator<int, array{0: int, 1: array<string, string>}>
     */
    public static function rows(string $content, array $needs, string $what, Report $report): \Generator
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        $header = fgetcsv($stream, escape: '');
        if (! is_array($header) || ! array_intersect($needs, $header = array_map('trim', $header))) {
            fclose($stream);
            throw new \InvalidArgumentException('the file has no header row with a '.implode(' or ', $needs).' column, so it is not CSV in AtoM\'s columns for '.$what);
        }
        $line = 1;
        try {
            while (($row = fgetcsv($stream, escape: '')) !== false) {
                $line++;
                if ($row === [null] || implode('', $row) === '') {
                    continue;
                }
                $cells = [];
                foreach ($header as $i => $column) {
                    $cell = (string) ($row[$i] ?? '');
                    $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $cell, -1, $removed);
                    if ($removed > 0) {
                        $report->issue("row $line", $column, null, $removed.' control '.($removed === 1 ? 'character' : 'characters').' removed: XML, and so EAD and RDF, cannot carry them', 'note');
                    }
                    $cells[$column] = self::nothing($clean) ? '' : trim($clean);
                }
                yield [$line, $cells];
            }
        } finally {
            fclose($stream);
        }
    }

    /** A cell that holds nothing but AtoM's NULL, alone or in a list: NULL, NULL|NULL. */
    private static function nothing(string $cell): bool
    {
        return trim(str_replace(['NULL', self::SPLIT], '', $cell)) === '' && trim($cell) !== '' && str_contains($cell, 'NULL');
    }

    /** @return list<UnitDraft> in the order of the file */
    public function read(string $content, string $language, Report $report): array
    {
        $report->once('publicationStatus', 'imported values are drafts until someone publishes them, whatever the file says', 'note');

        /** @var array<string, UnitDraft> $drafts */
        $drafts = [];
        foreach (self::rows($content, ['title', 'legacyId'], 'archival descriptions', $report) as [$line, $cells]) {
            // A column the import does not read is listed once, where it holds something.
            foreach ($cells as $column => $cell) {
                if ($cell === '') {
                    continue;
                }
                if (isset(self::ATOM_EXPORT[$column])) {
                    if (self::ATOM_EXPORT[$column] !== '') {
                        $report->once($column, self::ATOM_EXPORT[$column], 'note');
                    }
                } elseif (! array_key_exists($column, DescriptionCsv::COLUMNS)) {
                    $report->once($column, 'not a column of AtoM\'s ISAD(G) template or the Workspace; not read');
                } elseif (DescriptionCsv::COLUMNS[$column] === null && ! in_array($column, ['qubitParentSlug', 'digitalObjectURI', 'physicalObjectName', 'physicalObjectLocation', 'physicalObjectType'], true)) {
                    $report->once($column, $this->why($column));
                }
            }
            $location = "row $line";
            $rowLanguage = $cells['culture'] ?? '' ?: ($cells['languageOfDescription'] ?? '' ?: $language);
            if (! Bcp47::valid($rowLanguage)) {
                $report->issue($location, 'culture', $rowLanguage, "not a BCP 47 language tag; read as $language", 'note');
                $rowLanguage = $language;
            }
            $key = ($cells['legacyId'] ?? '') ?: (($cells['persistentIri'] ?? '') ?: null);
            if ($key !== null && isset($drafts[$key])) {
                $this->values($drafts[$key], $cells, $rowLanguage, $report);

                continue;
            }
            $draft = new UnitDraft;
            $draft->location = $location;
            $draft->key = $key ?? $location;
            $draft->stableKey = $key !== null;
            if ($key === null) {
                $report->issue($location, 'legacyId', null, 'no legacyId: importing the file again makes this unit again', 'note');
            }
            $draft->parentKey = ($cells['parentId'] ?? '') ?: null;
            $draft->parentIri = ($cells['parentIri'] ?? '') ?: null;
            $draft->iri = ($cells['persistentIri'] ?? '') ?: null;
            if (($cells['qubitParentSlug'] ?? '') !== '') {
                $report->issue($location, 'qubitParentSlug', $cells['qubitParentSlug'], 'an AtoM slug names no unit here; use parentId or parentIri', 'left-out');
            }
            $draft->identifier = ($cells['identifier'] ?? '') ?: null;
            $draft->level = ($cells['levelOfDescription'] ?? '') ?: null;
            $draft->repositoryName = ($cells['repository'] ?? '') ?: null;
            $draft->descriptionIdentifier = ($cells['descriptionIdentifier'] ?? '') ?: null;
            $draft->status = ($cells['descriptionStatus'] ?? '') ?: null;
            $draft->detail = ($cells['levelOfDetail'] ?? '') ?: null;
            $this->values($draft, $cells, $rowLanguage, $report);
            foreach ($this->split($cells['subjectAccessPoints'] ?? '') as $name) {
                $draft->subjects[] = ['name' => $name, 'iri' => null];
            }
            foreach ($this->split($cells['placeAccessPoints'] ?? '') as $name) {
                $draft->places[] = ['name' => $name, 'iri' => null];
            }
            $types = $this->split($cells['physicalObjectType'] ?? '', false);
            $locations = $this->split($cells['physicalObjectLocation'] ?? '', false);
            foreach ($this->split($cells['physicalObjectName'] ?? '', false) as $i => $name) {
                if ($name !== '') {
                    $draft->containers[] = ['name' => $name, 'type' => ($types[$i] ?? '') ?: null, 'location' => ($locations[$i] ?? '') ?: null];
                }
            }
            foreach ($this->split($cells['genreAccessPoints'] ?? '') as $name) {
                $draft->genres[] = ['name' => $name, 'iri' => null];
            }
            foreach ($this->split($cells['nameAccessPoints'] ?? '') as $name) {
                $draft->names[] = ['name' => $name, 'type' => null, 'iri' => null];
            }
            $values = $this->split($cells['alternativeIdentifiers'] ?? '', false);
            $labels = $this->split($cells['alternativeIdentifierLabels'] ?? '', false);
            foreach ($values as $i => $value) {
                if ($value !== '') {
                    $draft->identifiers[] = ['type' => $labels[$i] ?? 'other', 'value' => $value];
                }
            }
            $this->events($draft, $cells, $rowLanguage, $report);
            if (($cells['digitalObjectURI'] ?? '') !== '') {
                $draft->files[] = ['uri' => $cells['digitalObjectURI'], 'sha256' => strtolower($cells['digitalObjectChecksum'] ?? '') ?: null];
            }
            $drafts[$draft->key] = $draft;
        }

        return array_values($drafts);
    }

    private function values(UnitDraft $draft, array $cells, string $language, Report $report): void
    {
        foreach (DescriptionCsv::COLUMNS as $column => $source) {
            if ($source === null || str_starts_with($source, ':') || ! isset($cells[$column])) {
                continue;
            }
            // AtoM's language and script codes, separated by |: the ISO codes the Workspace knows are kept.
            if ($kind = Elements::codeKind($source)) {
                $codes = $this->split($cells[$column]);
                foreach (array_filter($codes, fn ($c) => ! IsoCodes::valid($kind, $c)) as $code) {
                    $report->issue($draft->location, $column, $code, 'not an ISO '.($kind === 'script' ? '15924' : '639').' code; left out', 'left-out');
                }
                $cells[$column] = implode(' ', array_filter($codes, fn ($c) => IsoCodes::valid($kind, $c)));
            }
            $draft->value($language, $source, $cells[$column]);
        }
        // The institution identifier is computed on export (as written, else the repository's); on import it is as written.
        if (($cells['institutionIdentifier'] ?? '') !== '') {
            $draft->value($language, 'institution-identifier', $cells['institutionIdentifier']);
        }
    }

    /** AtoM's events: each index of the event columns is one event, a date with or without an actor. */
    private function events(UnitDraft $draft, array $cells, string $language, Report $report): void
    {
        $columns = ['eventTypes', 'eventDates', 'eventStartDates', 'eventEndDates', 'eventActors', 'eventActorHistories'];
        $lists = array_map(fn ($c) => $this->split($cells[$c] ?? '', false), array_combine($columns, $columns));
        $count = max(array_map('count', $lists));
        for ($i = 0; $i < $count; $i++) {
            $type = mb_strtolower(trim($lists['eventTypes'][$i] ?? '')) ?: 'creation';
            $kind = self::EVENTS[$type] ?? 'associated';
            if (! isset(self::EVENTS[$type])) {
                $report->issue($draft->location, 'eventTypes', $type, 'an event type the Workspace has no date property for; its date is kept as an associated date', 'note');
            }
            $start = $this->day($lists['eventStartDates'][$i] ?? '', $draft, $report);
            $end = $this->day($lists['eventEndDates'][$i] ?? '', $draft, $report);
            $written = trim($lists['eventDates'][$i] ?? '');
            $normalized = self::interval($start, $end);
            if ($normalized !== null || $written !== '') {
                $draft->dates[] = ['property' => $kind, 'normalized' => $normalized, 'expressed' => $written ?: null, 'language' => $language];
            }
            $actor = trim($lists['eventActors'][$i] ?? '');
            if ($actor !== '') {
                if ($kind === 'associated') {
                    $report->issue($draft->location, 'eventActors', $actor, "an actor of a $type event is not a creator; not linked", 'left-out');
                } else {
                    $draft->creators[] = ['name' => $actor, 'type' => null, 'iri' => null, 'relation' => $kind, 'history' => trim($lists['eventActorHistories'][$i] ?? '') ?: null];
                }
            }
        }
    }

    /** An AtoM start or end date (YYYY, YYYY-MM or YYYY-MM-DD), or null. */
    private function day(string $value, UnitDraft $draft, Report $report): ?string
    {
        $value = trim($value);
        if ($value === '' || $value === 'NULL') {
            return null;
        }
        if (preg_match('/^\d{4}(-\d{2}(-\d{2})?)?$/', $value)) {
            return $value;
        }
        $report->issue($draft->location, 'eventStartDates or eventEndDates', $value, 'not a date as YYYY, YYYY-MM or YYYY-MM-DD; left out', 'left-out');

        return null;
    }

    /** The EDTF interval of a start and end day, a year where it covers whole years: 1902-01-01 and 1950-12-31 are 1902/1950. */
    public static function interval(?string $start, ?string $end): ?string
    {
        $start = $start !== null && str_ends_with($start, '-01-01') ? substr($start, 0, 4) : $start;
        $end = $end !== null && str_ends_with($end, '-12-31') ? substr($end, 0, 4) : $end;

        return match (true) {
            $start === null && $end === null => null,
            $end === null || $start === $end => $start ?? $end,
            $start === null => '../'.$end,
            default => "$start/$end",
        };
    }

    /** @return list<string> */
    private function split(string $value, bool $dropEmpty = true): array
    {
        if (trim($value) === '') {
            return [];
        }
        // AtoM writes NULL for an entry that is empty, so the lists of one event stay in step.
        $parts = array_map(fn ($p) => trim($p) === 'NULL' ? '' : trim($p), explode(self::SPLIT, $value));

        return $dropEmpty ? array_values(array_filter($parts, fn ($p) => $p !== '')) : $parts;
    }

    private function why(string $column): string
    {
        return match ($column) {
            'accessionNumber' => 'accessions are not kept yet; not read',
            'digitalObjectPath' => 'a path on the AtoM server is not fetched; export with digitalObjectURI to bring the file',
            default => 'not read',
        };
    }
}
