<?php

// OpenRiC Workspace - move the files kept before OCFL into the OCFL storage root (roadmap phase 15b, ADR 0022).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Until 0.0.115 a file was kept at <institution>/<aa>/<bb>/<sha256>. Dr Johan Pieterse, 4 November 2026: "this is not
// live data. so one store", so OCFL replaces that layout outright, with no mirror. Each instantiation's files and caption
// tracks become the first version of its own object, named by its IRI, as an upload now makes them; any other kept file
// (an agreement, a permit, an email, a transfer package) an object of its own named by its institution and checksum.
// A file is moved only when its checksum is still the one recorded, and the old layout is removed only once every object
// written verifies and no row names an old path. It can be run again: a row already in OCFL is left alone.

namespace App\Console\Commands;

use App\Description\InstantiationService;
use App\Files\FileStore;
use App\Files\Ocfl\StorageRoot;
use App\Models\Instantiation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateToOcfl extends Command
{
    protected $signature = 'workspace:ocfl:migrate {--keep-old : leave the old layout in place}';

    protected $description = 'Move the files kept before OCFL into OCFL objects, then remove the old layout';

    private const OCFL_PATH = '#^[0-9a-f]{3}/[0-9a-f]{3}/[0-9a-f]{3}/[0-9a-f]{64}/#';

    private const OLD_PATH = '#^[0-9a-f-]{36}/[0-9a-f]{2}/[0-9a-f]{2}/[0-9a-f]{64}$#';

    public function handle(FileStore $store): int
    {
        $ocfl = $store->ocfl();
        $written = $problems = [];
        $moved = 0;

        // Each instantiation's files and captions, as one version of its own object.
        $ids = DB::table('digital_files')->whereRaw("storage_path !~ '^[0-9a-f]{3}/'")->distinct()->pluck('instantiation_id')
            ->merge(DB::table('caption_tracks')->whereRaw("storage_path !~ '^[0-9a-f]{3}/'")->distinct()->pluck('instantiation_id'))->unique();
        foreach (Instantiation::whereIn('id', $ids)->orderBy('id')->get() as $instantiation) {
            $add = $rows = [];
            foreach (DB::table('digital_files')->join('concepts', 'concepts.id', '=', 'digital_files.use_id')->where('instantiation_id', $instantiation->id)
                ->select('digital_files.*', 'concepts.notation as use')->orderBy('digital_files.created_at')->get() as $f) {
                $logical = match (true) {
                    $f->use === 'master' => 'files/master/'.InstantiationService::logicalName($f->original_name),
                    $f->mime_type === FileStore::MODEL => 'files/reference/model.glb',
                    default => "files/{$f->use}/{$f->use}.".(['audio/mpeg' => 'mp3', 'video/mp4' => 'mp4', 'image/png' => 'png', 'image/jpeg' => 'jpg'][$f->mime_type] ?? 'bin'),
                };
                $this->take($store, 'digital_files', $f, $logical, $add, $rows, $problems);
            }
            foreach (DB::table('caption_tracks')->where('instantiation_id', $instantiation->id)->get() as $t) {
                $this->take($store, 'caption_tracks', $t, "captions/{$t->language}-{$t->kind}.vtt", $add, $rows, $problems);
            }
            $moved += $this->commit($ocfl, $instantiation->iri, $add, $rows, $written);
        }

        // Every other kept file, an object of its own.
        foreach (DB::table('information_schema.columns')->where('table_schema', 'public')->whereIn('column_name', ['storage_path', 'package_path'])
            ->whereNotIn('table_name', ['digital_files', 'caption_tracks'])->orderBy('table_name')->get(['table_name', 'column_name']) as $c) {
            $sha = $c->column_name === 'package_path' ? 'package_sha256' : 'sha256';
            foreach (DB::table($c->table_name)->whereNotNull($c->column_name)->whereRaw("{$c->column_name} !~ '^[0-9a-f]{3}/'")->get() as $row) {
                $old = $row->{$c->column_name};
                if (! preg_match(self::OLD_PATH, $old)) {
                    $problems[] = "{$c->table_name} {$row->id}: $old is in neither layout";

                    continue;
                }
                [$institution, , , $digest] = explode('/', $old);
                $add = $rows = [];
                $this->take($store, $c->table_name, (object) ['id' => $row->id, 'storage_path' => $old, 'sha256' => $row->{$sha} ?? $digest], 'file', $add, $rows, $problems, $c->column_name);
                $base = rtrim((string) DB::table('institutions')->where('id', $institution)->value('base_iri'), '/');
                $moved += $this->commit($ocfl, $base.'/file/'.$digest, $add, $rows, $written);
            }
        }

        foreach (array_unique($written) as $id) {
            array_push($problems, ...$ocfl->verify($id));
        }
        $this->line("$moved files moved into ".count(array_unique($written)).' OCFL objects.');
        foreach ($problems as $problem) {
            $this->error($problem);
        }
        if ($problems !== []) {
            $this->error('The old layout is left in place.');

            return self::FAILURE;
        }
        if (! $this->option('keep-old')) {
            $removed = 0;
            foreach (glob($store->root().'/*', GLOB_ONLYDIR) ?: [] as $dir) {
                // Only the old layout's folders, named by an institution's id; OCFL's are three hexadecimal digits.
                if (preg_match('#/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$#', $dir)) {
                    exec('rm -rf '.escapeshellarg($dir));
                    $removed++;
                }
            }
            $this->line("The old layout removed ($removed institution folders).");
        }

        return self::SUCCESS;
    }

    /** A row's file, added to the version being made when it is still what was recorded. */
    private function take(FileStore $store, string $table, object $row, string $logical, array &$add, array &$rows, array &$problems, string $column = 'storage_path'): void
    {
        if (preg_match(self::OCFL_PATH, $row->storage_path)) {
            return;
        }
        $path = $store->path($row->storage_path);
        if (! is_file($path) || hash_file('sha256', $path) !== $row->sha256) {
            $problems[] = "$table {$row->id}: {$row->storage_path} is missing or not what was recorded";

            return;
        }
        // Two rows of an object may want one logical path (a copy made again): the later is kept under its own name.
        for ($name = $logical, $n = 2; isset($add[$name]) && $add[$name] !== $path; $n++) {
            $name = preg_replace('#(\.[^./]+)?$#', "-$n$1", $logical, 1);
        }
        $add[$name] = $path;
        $rows[] = [$table, $row->id, $name, $column];
    }

    /** One version of the object with what was taken, and each row pointed at its content file. */
    private function commit(StorageRoot $ocfl, string $id, array $add, array $rows, array &$written): int
    {
        if ($add === []) {
            return 0;
        }
        $content = $ocfl->commit($id, $add, [], 'Moved into OCFL by the OpenRiC Workspace');
        DB::transaction(function () use ($rows, $content) {
            // The one change of place the file guard allows (migration 2026_11_04_000300), for this transaction only.
            DB::statement("SET LOCAL workspace.moving_files = 'on'");
            foreach ($rows as [$table, $rowId, $name, $column]) {
                DB::table($table)->where('id', $rowId)->update([$column => $content[$name]]);
            }
        });
        $written[] = $id;

        return count($rows);
    }
}
