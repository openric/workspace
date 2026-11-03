<?php

// OpenRiC Workspace - the columns of a spreadsheet mapped to the AtoM CSV columns an import reads (roadmap phase 22a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After PSIS's ahgIngestPlugin (IngestService::autoMapColumns, saveMappings, applyTransform): each heading of the file
// is offered the column it most likely is, with how sure the match is (exact: the column's own name or a known
// alias; close: the same once case, spaces, hyphens and underscores are set aside; none), and may be mapped to
// another, left out, given a value for empty cells, and transformed. The mapped file is the AtoM CSV the import
// already reads, so mapping adds no second way of importing. Mappings can be kept by name and used again.

namespace App\Ingest;

use App\Exchange\DescriptionCsv;
use App\Exchange\Import\AtomEntityReader;
use InvalidArgumentException;

class ColumnMapping
{
    /** The transforms a mapped column may have (PSIS's applyTransform). */
    public const TRANSFORMS = ['trim', 'uppercase', 'lowercase', 'titlecase', 'date-iso', 'strip-html'];

    /** Headings other systems use, and the AtoM column each is (PSIS's aliases). */
    private const ALIASES = [
        'legacy_id' => 'legacyId', 'legacyid' => 'legacyId', 'parent_id' => 'parentId', 'parentid' => 'parentId', 'parent_slug' => 'qubitParentSlug',
        'level_of_description' => 'levelOfDescription', 'levelofdescription' => 'levelOfDescription', 'level' => 'levelOfDescription',
        'extent_and_medium' => 'extentAndMedium', 'extent' => 'extentAndMedium', 'scope_and_content' => 'scopeAndContent', 'scope' => 'scopeAndContent',
        'description' => 'scopeAndContent', 'archival_history' => 'archivalHistory', 'custodial_history' => 'archivalHistory',
        'access_conditions' => 'accessConditions', 'conditions_of_access' => 'accessConditions', 'reproduction_conditions' => 'reproductionConditions',
        'conditions_of_reproduction' => 'reproductionConditions', 'finding_aids' => 'findingAids', 'publication_status' => 'publicationStatus',
        'digital_object_path' => 'digitalObjectPath', 'digital_object_uri' => 'digitalObjectURI', 'digital_object' => 'digitalObjectPath',
        'filename' => 'digitalObjectPath', 'file_path' => 'digitalObjectPath', 'file' => 'digitalObjectPath',
        'subject_access_points' => 'subjectAccessPoints', 'subjects' => 'subjectAccessPoints', 'place_access_points' => 'placeAccessPoints',
        'places' => 'placeAccessPoints', 'name_access_points' => 'nameAccessPoints', 'names' => 'nameAccessPoints',
        'genre_access_points' => 'genreAccessPoints', 'genres' => 'genreAccessPoints', 'creator' => 'eventActors', 'creators' => 'eventActors',
        'date' => 'eventDates', 'dates' => 'eventDates', 'creation_date' => 'eventDates', 'start_date' => 'eventStartDates', 'end_date' => 'eventEndDates',
        'accession_number' => 'accessionNumber', 'physical_location' => 'physicalObjectLocation', 'storage_location' => 'physicalObjectLocation',
        'ref_code' => 'identifier', 'reference_code' => 'identifier', 'ref' => 'identifier', 'reference' => 'identifier', 'name' => 'authorizedFormOfName',
        'authorised_name' => 'authorizedFormOfName', 'authorized_name' => 'authorizedFormOfName', 'history' => 'history', 'notes' => 'generalNote', 'note' => 'generalNote',
    ];

    /** @return list<string> the columns the format's reader takes */
    public static function targets(string $format): array
    {
        return match ($format) {
            'csv' => array_keys(DescriptionCsv::COLUMNS),
            'authority-csv' => array_values(array_unique([...array_keys(AtomEntityReader::AUTHORITY), ...AtomEntityReader::READ['authority']])),
            'repository-csv' => array_values(array_unique([...array_keys(AtomEntityReader::REPOSITORY), ...AtomEntityReader::READ['repository']])),
            default => [],
        };
    }

    /** @return list<string> the file's headings, as written */
    public static function headings(string $content): array
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $content));
        rewind($stream);
        $header = fgetcsv($stream, escape: '');
        fclose($stream);

        return is_array($header) ? array_values(array_filter(array_map(fn ($h) => trim((string) $h), $header), fn ($h) => $h !== '')) : [];
    }

    /** The columns the format's reader needs one of, as the readers ask (CsvReader::rows). */
    private const NEEDS = ['csv' => ['title', 'legacyId'], 'authority-csv' => ['authorizedFormOfName'], 'repository-csv' => ['authorizedFormOfName']];

    /**
     * Whether the file must be mapped before it can be read: it has none of the columns its reader needs, so it is not
     * in AtoM's columns. A file in AtoM's columns goes straight to its dry run, whose report lists any column it does
     * not read; it may still be mapped from its report.
     */
    public static function needed(string $format, string $content): bool
    {
        return isset(self::NEEDS[$format]) && ! array_intersect(self::NEEDS[$format], self::headings($content));
    }

    /** @return array<string, array{target: ?string, confidence: string}> each heading's likely column */
    public static function suggest(string $format, array $headings): array
    {
        $targets = self::targets($format);
        $byLower = array_combine(array_map('strtolower', $targets), $targets);
        $bare = fn (string $s) => str_replace([' ', '-', '_', '.'], '', strtolower(trim($s)));
        $byBare = [];
        foreach ($targets as $t) {
            $byBare[$bare($t)] = $t;
        }
        $aliasBare = [];
        foreach (self::ALIASES as $alias => $t) {
            $aliasBare[$bare($alias)] = $t;
        }
        $out = [];
        foreach ($headings as $heading) {
            $lower = strtolower(trim($heading));
            $alias = self::ALIASES[str_replace([' ', '-'], '_', $lower)] ?? null;
            [$target, $confidence] = match (true) {
                in_array($heading, $targets, true) => [$heading, 'exact'],
                $alias !== null && in_array($alias, $targets, true) => [$alias, 'exact'],
                isset($byLower[$lower]) => [$byLower[$lower], 'close'],
                isset($byBare[$bare($heading)]) => [$byBare[$bare($heading)], 'close'],
                isset($aliasBare[$bare($heading)]) && in_array($aliasBare[$bare($heading)], $targets, true) => [$aliasBare[$bare($heading)], 'close'],
                default => [null, 'none'],
            };
            $out[$heading] = ['target' => $target, 'confidence' => $confidence];
        }

        return $out;
    }

    /**
     * The file rewritten in the format's columns: each mapped heading renamed, a left-out one dropped, empty cells given
     * the column's default, values transformed. Two headings mapped to one column are joined with AtoM's "|".
     *
     * @param  array<string, array{target?: ?string, default?: ?string, transform?: ?string}>  $mapping
     */
    public static function apply(string $format, string $content, array $mapping): string
    {
        $targets = self::targets($format);
        $in = fopen('php://memory', 'r+');
        fwrite($in, preg_replace('/^\xEF\xBB\xBF/', '', $content));
        rewind($in);
        $header = array_map(fn ($h) => trim((string) $h), fgetcsv($in, escape: '') ?: []);
        $columns = [];
        foreach ($header as $i => $heading) {
            $target = $mapping[$heading]['target'] ?? null;
            if ($target === null || $target === '') {
                continue;
            }
            in_array($target, $targets, true) || throw new InvalidArgumentException(__(':column is not a column this import reads.', ['column' => $target]));
            $transform = $mapping[$heading]['transform'] ?? null;
            ($transform === null || $transform === '' || in_array($transform, self::TRANSFORMS, true)) || throw new InvalidArgumentException(__(':value is not in the list', ['value' => $transform]));
            $columns[$target][] = ['index' => $i, 'default' => (string) ($mapping[$heading]['default'] ?? ''), 'transform' => $transform ?: null];
        }
        $columns !== [] || throw new InvalidArgumentException(__('Map at least one column.'));
        $out = fopen('php://memory', 'r+');
        fputcsv($out, array_keys($columns), escape: '');
        while (($row = fgetcsv($in, escape: '')) !== false) {
            if ($row === [null]) {
                continue;
            }
            $cells = [];
            foreach ($columns as $parts) {
                $values = array_filter(array_map(function ($p) use ($row) {
                    $value = trim((string) ($row[$p['index']] ?? ''));

                    return self::transform($value === '' ? $p['default'] : $value, $p['transform']);
                }, $parts), fn ($v) => $v !== '');
                $cells[] = implode('|', $values);
            }
            fputcsv($out, $cells, escape: '');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($in);
        fclose($out);

        return $csv;
    }

    private static function transform(string $value, ?string $transform): string
    {
        return match ($transform) {
            'trim' => trim($value),
            'uppercase' => mb_strtoupper($value),
            'lowercase' => mb_strtolower($value),
            'titlecase' => mb_convert_case(mb_strtolower($value), MB_CASE_TITLE),
            'date-iso' => ($t = strtotime($value)) ? date('Y-m-d', $t) : $value,
            'strip-html' => trim(html_entity_decode(strip_tags($value))),
            default => $value,
        };
    }
}
