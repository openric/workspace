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
use App\Exchange\DescriptionCsv;

class CsvReader
{
    /** AtoM's event types and the date property and creator relation each becomes. */
    private const EVENTS = ['creation' => 'creation', 'accumulation' => 'accumulation'];

    /** Columns that hold several entries, separated by |. */
    private const SPLIT = '|';

    /** @return list<UnitDraft> in the order of the file */
    public function read(string $content, string $language, Report $report): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        $header = fgetcsv($stream, escape: '');
        if (! is_array($header) || ! array_intersect(['title', 'legacyId'], $header = array_map('trim', $header))) {
            fclose($stream);
            throw new \InvalidArgumentException('the file has no header row with a title or legacyId column, so it is not CSV in AtoM\'s columns');
        }
        $report->once('publicationStatus', 'imported values are drafts until someone publishes them, whatever the file says', 'note');

        /** @var array<string, UnitDraft> $drafts */
        $drafts = [];
        $line = 1;
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $line++;
            if ($row === [null] || implode('', $row) === '') {
                continue;
            }
            $cells = [];
            foreach ($header as $i => $column) {
                $cells[$column] = trim((string) ($row[$i] ?? ''));
            }
            // A column the import does not read is listed once, where it holds something.
            foreach ($cells as $column => $cell) {
                if ($cell !== '' && ! array_key_exists($column, DescriptionCsv::COLUMNS)) {
                    $report->once($column, 'not a column of AtoM\'s ISAD(G) template or the Workspace; not read');
                } elseif ($cell !== '' && DescriptionCsv::COLUMNS[$column] === null && $column !== 'qubitParentSlug') {
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
                $this->values($drafts[$key], $cells, $rowLanguage);

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
            $this->values($draft, $cells, $rowLanguage);
            foreach ($this->split($cells['subjectAccessPoints'] ?? '') as $name) {
                $draft->subjects[] = ['name' => $name, 'iri' => null];
            }
            foreach ($this->split($cells['placeAccessPoints'] ?? '') as $name) {
                $draft->places[] = ['name' => $name, 'iri' => null];
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
            $drafts[$draft->key] = $draft;
        }
        fclose($stream);

        return array_values($drafts);
    }

    private function values(UnitDraft $draft, array $cells, string $language): void
    {
        foreach (DescriptionCsv::COLUMNS as $column => $source) {
            if ($source !== null && ! str_starts_with($source, ':') && isset($cells[$column])) {
                $draft->value($language, $source, $cells[$column]);
            }
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
        $parts = array_map('trim', explode(self::SPLIT, $value));

        return $dropEmpty ? array_values(array_filter($parts, fn ($p) => $p !== '')) : $parts;
    }

    private function why(string $column): string
    {
        return match ($column) {
            'accessionNumber' => 'accessions are not kept yet; not read',
            'digitalObjectPath', 'digitalObjectURI' => 'digital objects are not kept yet; not read',
            'physicalObjectName', 'physicalObjectLocation', 'physicalObjectType' => 'physical storage is not kept yet; not read',
            'language', 'script', 'scriptOfDescription' => 'language and script codes are not kept yet; the narrative languageNote is read',
            'genreAccessPoints' => 'documentary form types are not linked to units yet; not read',
            default => 'not read',
        };
    }
}
