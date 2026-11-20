<?php

// OpenRiC Workspace - take in names found in units' text as proposals for review (roadmap phase 27), for an explorer
// whose names were read elsewhere (a model run outside the Workspace, or another system's export): a CSV of record,
// type, value and, optionally, confidence. The record is the unit's IRI, id or local identifier; the type spaCy's label
// (PERSON, ORG and NORP become people and organisations; GPE, LOC and FAC places). A name the unit already has waiting
// or accepted is skipped, so a file can be taken in again.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Console\Commands;

use App\Models\Identifier;
use App\Models\RecordResource;
use App\Models\User;
use App\Proposals\ProposalException;
use App\Proposals\ProposalService;
use Illuminate\Console\Command;

class ExplorerNames extends Command
{
    protected $signature = 'workspace:explorer:names {file : a CSV with the headings record, type, value and optionally confidence}
        {--as= : the email address of the account that proposes them} {--source=text : a short label of where the names came from}';

    protected $description = 'Take in names found in units\' text as proposals for review, for the record set explorer';

    /** spaCy's labels => the proposal types. */
    private const TYPES = ['PERSON' => 'PERSON', 'ORG' => 'ORG', 'NORP' => 'ORG', 'GPE' => 'GPE', 'LOC' => 'GPE', 'FAC' => 'GPE'];

    public function handle(ProposalService $proposals): int
    {
        $user = User::where('email', (string) $this->option('as'))->first();
        if (! $user) {
            $this->error(__('No account has that email address: give --as.'));

            return self::FAILURE;
        }
        $handle = @fopen((string) $this->argument('file'), 'r');
        if (! $handle) {
            $this->error(__('The file cannot be read.'));

            return self::FAILURE;
        }
        $head = array_map(fn ($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\u{FEFF}")), fgetcsv($handle, escape: '') ?: []);
        $byRecord = [];
        $skipped = 0;
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $r = array_combine($head, array_pad($row, count($head), ''));
            $type = self::TYPES[strtoupper(trim($r['type'] ?? ''))] ?? null;
            if (! $type || trim($r['value'] ?? '') === '' || trim($r['record'] ?? '') === '') {
                $skipped++;

                continue;
            }
            $byRecord[trim($r['record'])][] = ['type' => $type, 'value' => trim($r['value'])] + (is_numeric($r['confidence'] ?? null) ? ['confidence' => (float) $r['confidence']] : []);
        }
        fclose($handle);
        $queued = $existing = 0;
        foreach ($byRecord as $key => $names) {
            $record = $this->record($user, $key);
            if (! $record || ! $user->may('update', 'description', $record)) {
                $this->warn(__('Not taken in: no unit :key that this account may describe.', ['key' => $key]));
                $skipped += count($names);

                continue;
            }
            foreach (array_chunk($names, 50) as $part) {
                try {
                    $body = $proposals->propose($record, $user, null, ['entities' => $part, 'source' => (string) $this->option('source')])['body'];
                    $queued += count($body['queued']);
                    $existing += $body['skipped_existing'];
                } catch (ProposalException $e) {
                    $this->warn($key.': '.$e->getMessage());
                    $skipped += count($part);
                }
            }
        }
        $this->info(__(':queued names proposed, :existing already there, :skipped not taken in.', ['queued' => $queued, 'existing' => $existing, 'skipped' => $skipped]));

        return self::SUCCESS;
    }

    private function record(User $user, string $key): ?RecordResource
    {
        $base = RecordResource::where('institution_id', $user->institution_id)->whereNull('deleted_at');
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $key)) {
            return (clone $base)->find($key);
        }

        return (clone $base)->where('iri', $key)->first()
            ?? (clone $base)->whereIn('id', Identifier::where(['subject_table' => 'record_resources', 'value' => $key])->select('subject_id'))->first();
    }
}
