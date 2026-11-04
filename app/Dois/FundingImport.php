<?php

// OpenRiC Workspace - funding references for units of description taken in from a CSV (roadmap phase 20c), after
// Heratio's DoiFundingImportCommand: each row names its unit (by persistent IRI, id or reference code) and its funder,
// with the funder's identifier and its scheme and the award; a row whose unit cannot be found, or that repeats one
// already there, is passed over and said why.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Dois;

use App\Models\RecordFunding;
use App\Models\RecordResource;
use InvalidArgumentException;

class FundingImport
{
    public const COLUMNS = ['record', 'funder_name', 'funder_identifier', 'funder_identifier_type', 'award_number', 'award_uri', 'award_title'];

    public function __construct(private RecordDois $dois) {}

    /** @return array{added: int, skipped: int, problems: list<string>} */
    public function run(string $path): array
    {
        $handle = is_readable($path) ? fopen($path, 'r') : false;
        $handle || throw new InvalidArgumentException(__('The file cannot be read.'));
        $header = array_map(fn ($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\u{FEFF}")), (array) fgetcsv($handle));
        in_array('record', $header, true) && in_array('funder_name', $header, true) || throw new InvalidArgumentException(__('The file needs a record and a funder_name column.'));
        $added = $skipped = 0;
        $problems = [];
        for ($line = 2; ($row = fgetcsv($handle)) !== false; $line++) {
            $values = array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), ''));
            $unit = $this->unit(trim((string) $values['record']));
            $name = trim((string) $values['funder_name']);
            if (! $unit || $name === '') {
                $problems[] = __('Line :line: :why', ['line' => $line, 'why' => $unit ? __('no funder_name') : __('no unit of description :record', ['record' => $values['record']])]);
                $skipped++;

                continue;
            }
            $fields = ['funder_name' => $name, 'funder_identifier' => trim((string) ($values['funder_identifier'] ?? '')) ?: null, 'award_number' => trim((string) ($values['award_number'] ?? '')) ?: null];
            if (RecordFunding::where(['record_resource_id' => $unit->id] + $fields)->exists()) {
                $skipped++;

                continue;
            }
            $scheme = strtolower(str_replace(' ', '-', trim((string) ($values['funder_identifier_type'] ?? ''))));
            RecordFunding::create($fields + ['record_resource_id' => $unit->id, 'award_uri' => trim((string) ($values['award_uri'] ?? '')) ?: null,
                'award_title' => trim((string) ($values['award_title'] ?? '')) ?: null,
                'funder_identifier_type_id' => $scheme !== '' ? rescue(fn () => $this->dois->concept('funder-identifier-type', $scheme), null, false) : null]);
            $added++;
        }
        fclose($handle);

        return ['added' => $added, 'skipped' => $skipped, 'problems' => $problems];
    }

    /** A unit by its persistent IRI, its id, or its reference code. */
    private function unit(string $ref): ?RecordResource
    {
        if ($ref === '') {
            return null;
        }
        $id = preg_match('/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i', $ref, $m) ? strtolower($m[1]) : null;
        if ($id && ($unit = RecordResource::whereNull('deleted_at')->find($id))) {
            return $unit;
        }

        // ponytail: a reference code is worked out unit by unit, so this reads every unit; an import of thousands wants an index of codes.
        return RecordResource::whereNull('deleted_at')->get()->first(fn (RecordResource $u) => $u->referenceCode() === $ref);
    }
}
