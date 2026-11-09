<?php

// OpenRiC Workspace - the capacity test's helpers (roadmap R1 hardening): the addresses k6 visits, chosen from the
// capacity dataset (the widest fonds, the deepest unit, published units and authority records, units staff may change,
// search words), and a spreadsheet import of so many rows, timed, through the import the screens use. Only in a database
// whose name ends in _capacity.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Console\Commands;

use App\Exchange\Import\ImportService;
use App\Models\Agent;
use App\Models\Import;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CapacityProbe extends Command
{
    protected $signature = 'workspace:capacity:probe
        {action : targets (write the addresses k6 visits as JSON) or import (time a spreadsheet import)}
        {--out= : Where targets are written}
        {--rows=1000 : Rows in the spreadsheet import}';

    protected $description = 'The capacity test\'s helpers: the targets k6 visits, and a timed spreadsheet import';

    public function handle(): int
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        if (! str_ends_with($database, '_capacity')) {
            $this->error("Refused: this runs on a capacity-test database, whose name ends in _capacity; this one is $database.");

            return self::FAILURE;
        }

        return match ($this->argument('action')) {
            'targets' => $this->targets(),
            'import' => $this->import(),
            default => tap(self::INVALID, fn () => $this->error('The action is targets or import.')),
        };
    }

    private function targets(): int
    {
        $published = fn (string $table) => DB::table('names')->where('subject_table', $table)
            ->whereIn('state_id', DB::table('concepts')->where('iri', Vocab::valueState('published'))->select('id'))->select('subject_id');
        // The seed gives each unit a local identifier: CAP-W for the widest fonds, CAP-D/1/... for the deepest branch.
        $identified = fn (string $op, string $value) => DB::table('identifiers')->where('subject_table', 'record_resources')->where('value', $op, $value)->select('subject_id');
        $wide = DB::table('identifiers')->where(['subject_table' => 'record_resources', 'value' => 'CAP-W'])->value('subject_id');
        $deep = DB::table('identifiers')->where(['subject_table' => 'record_resources', 'value' => 'CAP-D/1/1/1/1/1/1/1'])->value('subject_id');
        $items = RecordResource::whereIn('id', $published('record_resources'))->whereIn('id', $identified('like', 'CAP-%/%/%/%'))
            ->inRandomOrder()->limit(200)->pluck('id');
        $targets = [
            'wide_fonds' => $wide, 'deep_unit' => $deep, 'records' => $items,
            'agents' => Agent::whereIn('id', $published('agents'))->inRandomOrder()->limit(50)->pluck('id'),
            'editable' => RecordResource::whereIn('id', $identified('like', 'CAP-%/%/%/%'))->inRandomOrder()->limit(100)->pluck('id'),
            'words' => ['minutes', 'water supply', 'register entry', 'petitions', 'roads and bridges', 'Botha', 'Riverside council', 'drought relief 1933', 'schools', 'Kloofdal'],
            'counts' => ['units' => RecordResource::count(), 'agents' => Agent::count(), 'places' => DB::table('places')->count(), 'activities' => DB::table('activities')->count(),
                'audit_rows' => DB::table('audit_log')->count(), 'instantiations' => DB::table('instantiations')->count()],
        ];
        if (! $wide || ! $deep || $items->isEmpty()) {
            $this->error('The capacity dataset is not loaded: run workspace:capacity:seed first.');

            return self::FAILURE;
        }
        file_put_contents((string) ($this->option('out') ?: 'php://stdout'), json_encode($targets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        return self::SUCCESS;
    }

    /** A spreadsheet of so many items under a new file, dry run and then committed, as the import screen does. */
    private function import(): int
    {
        $user = User::where('email', 'archivist@capacity.invalid')->firstOrFail();
        $institution = Institution::findOrFail($user->institution_id);
        $rows = max(1, (int) $this->option('rows'));
        $stamp = now()->format('YmdHis');
        $csv = "legacyId,parentId,title,levelOfDescription,identifier,scopeAndContent\n";
        $csv .= "F$stamp,,Imported file $stamp (synthetic),file,IMP-$stamp,A file imported for the capacity test.\n";
        for ($i = 1; $i <= $rows; $i++) {
            $csv .= "I$stamp-$i,F$stamp,Imported item $i of $stamp (synthetic),item,IMP-$stamp/$i,An imported item about the water supply.\n";
        }
        auth()->login($user);
        $import = Import::create(['institution_id' => $institution->id, 'user_id' => $user->id, 'format' => 'csv', 'source' => 'capacity test', 'file_name' => "capacity-$stamp.csv",
            'file_sha256' => hash('sha256', $csv), 'content' => $csv, 'language' => 'en',
            'repository_id' => DB::table('repositories')->where('institution_id', $institution->id)->orderBy('created_at')->value('id'), 'options' => []]);
        $service = app(ImportService::class);
        $timings = [];
        foreach (['dry run' => false, 'commit' => true] as $label => $commit) {
            $started = microtime(true);
            $report = $service->run($import->fresh(), $commit);
            $timings[$label] = round(microtime(true) - $started, 1);
            $this->line(sprintf('%s: %.1f s', $label, $timings[$label]));
        }
        $this->line(json_encode(['rows' => $rows, 'seconds' => $timings, 'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576), 'units' => count($report->units), 'issues' => count($report->issues)]));

        return self::SUCCESS;
    }
}
