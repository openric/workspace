<?php

// OpenRiC Workspace - watched folders: what is dropped in a folder on the server, by a scanner, a person or an FTP or
// SFTP account, is taken in unattended (roadmap phase 22a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After PSIS's ahgIngestPlugin (ingest_watch_folder, ingest:watch) and Heratio's ahg-scan (ScanWatchCommand). Each
// folder lies under the installation's intake root (WORKSPACE_INTAKE_ROOT), so a folder named in the Workspace can never
// reach the rest of the server; an FTP or SFTP account chrooted to such a folder is how Heratio's FTP upload is carried
// (Dr Johan Pieterse's choice 2B of 3 November 2026). Every minute each enabled folder is read: a file still changing
// (written in the last minute) waits for the next pass. A CSV, spreadsheet, ZIP or EAD file is imported with the
// folder's settings, committed at once if its dry run refuses nothing and the folder says so, else left for review
// with its report; a BagIt bag (a folder or ZIP with bagit.txt) is checked and its files taken in; any other file
// becomes a record with its stem's siblings and sidecar (Intake). What was taken in moves to .processed/<time>/, what
// failed to .failed/<time>/ with the reason beside it; every file is logged.

namespace App\Ingest;

use App\Http\Controllers\ImportController;
use App\Models\Concept;
use App\Models\Import;
use App\Models\IntakeFile;
use App\Models\WatchFolder;
use App\Support\Archives;
use App\Support\Vocab;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use ZipArchive;

class WatchFolders
{
    private const TABLES = ['csv', 'xlsx', 'xls', 'ods'];

    public function __construct(private Intake $intake, private IngestFile $files) {}

    /** The intake root, or null where the installation has none. */
    public static function root(): ?string
    {
        $root = config('workspace.intake_root');

        return $root && is_dir($root) ? (realpath($root) ?: null) : null;
    }

    /** The folder's place on disk: a folder under the root, never outside it; null if it is not there. */
    public static function resolve(string $relative): ?string
    {
        $root = self::root();
        $relative = trim(str_replace('\\', '/', $relative), '/');
        if ($root === null || $relative === '' || in_array('..', explode('/', $relative), true) || str_contains($relative, "\0")) {
            return null;
        }
        $path = realpath($root.'/'.$relative);

        return $path && is_dir($path) && str_starts_with($path.'/', $root.'/') ? $path : null;
    }

    /** One pass over every enabled folder. @return int files taken in or refused */
    public function runAll(): int
    {
        return WatchFolder::where('is_enabled', true)->get()->sum(fn ($f) => $this->run($f));
    }

    /** One pass over a folder. @return int files taken in or refused */
    public function run(WatchFolder $folder): int
    {
        $dir = self::resolve($folder->path);
        if ($dir === null) {
            $folder->update(['last_scan_at' => now(), 'last_status' => __('The folder is not under the intake root, or is not there.')]);

            return 0;
        }
        $stamp = now()->format('Ymd-His');
        $settled = fn (string $p) => (time() - (int) @filemtime($p)) >= (int) config('workspace.intake_settle_seconds', 60);
        $entries = array_values(array_filter(scandir($dir) ?: [], fn ($e) => $e[0] !== '.'));
        $done = 0;
        // Bags first: a folder holding bagit.txt.
        foreach ($entries as $entry) {
            $path = "$dir/$entry";
            if (is_dir($path) && is_file("$path/bagit.txt") && $settled("$path/bagit.txt")) {
                $done += $this->bagFolder($folder, $dir, $entry, $stamp);
            }
        }
        $files = array_values(array_filter($entries, fn ($e) => is_file("$dir/$e") && $settled("$dir/$e")));
        $sidecars = [];
        $singles = [];
        foreach ($files as $name) {
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($extension === 'xml' && $this->isSidecar("$dir/$name")) {
                $sidecars[pathinfo($name, PATHINFO_FILENAME)] = $name;
            } elseif (in_array($extension, self::TABLES, true) || $extension === 'xml' || ($extension === 'zip' && ! $this->zipIsBag("$dir/$name"))) {
                $done += $this->table($folder, $dir, $name, $stamp);
            } elseif ($extension === 'zip') {
                $done += $this->bagZip($folder, $dir, $name, $stamp);
            } else {
                $singles[Intake::stem($name)][] = $name;
            }
        }
        foreach ($singles as $stem => $names) {
            sort($names, SORT_NATURAL);
            $sidecar = $sidecars[$stem] ?? null;
            unset($sidecars[$stem]);
            $done += $this->single($folder, $dir, $names, $sidecar, $stamp);
        }
        $folder->update(['last_scan_at' => now(), 'last_status' => trans_choice(':count file taken in or refused.|:count files taken in or refused.', $done, ['count' => $done]),
            'files_taken' => $folder->files_taken + $done]);

        return $done;
    }

    /** A table or description file, imported with the folder's settings. */
    private function table(WatchFolder $folder, string $dir, string $name, string $stamp): int
    {
        $path = "$dir/$name";
        try {
            $format = strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'xml' ? 'ead' : $folder->format;
            ['content' => $content, 'bundle' => $bundle] = $format === 'ead' ? ['content' => (string) file_get_contents($path), 'bundle' => null]
                : $this->files->read($folder->institution_id, $path, $name);
            mb_check_encoding($content, 'UTF-8') || throw new InvalidArgumentException(__('the file is not UTF-8'));
            if ($folder->mapping && ColumnMapping::needed($format, $content)) {
                $content = ColumnMapping::apply($format, $content, $folder->mapping->columns);
            }
            ColumnMapping::needed($format, $content) && throw new InvalidArgumentException(__('the file is not in AtoM\'s columns; give the folder a mapping kept for files like it'));
            $import = Import::create(['institution_id' => $folder->institution_id, 'user_id' => $folder->user_id, 'format' => $format,
                'source' => 'watched folder '.$folder->path, 'file_name' => $name, 'file_sha256' => hash_file('sha256', $path), 'content' => $content,
                'language' => $folder->language, 'repository_id' => $folder->repository_id, 'parent_id' => $folder->parent_id,
                'options' => ['agent_type' => null, 'create_terms' => true, 'match_identifier' => false, 'default_level' => $folder->level?->notation, 'bundle' => $bundle]]);
            $controller = app(ImportController::class);
            $controller->queue($import, false);
            $actions = $import->refresh()->report['actions'] ?? null;
            $clean = $actions !== null && ($actions['rejected'] ?? 1) === 0 && ! collect($import->report['issues'] ?? [])->contains('kind', 'rejected');
            if ($folder->auto_commit && $clean) {
                $controller->queue($import, true);
            }
            $outcome = $folder->auto_commit && $clean ? 'imported' : 'awaiting-review';
            $this->log($folder, $name, $path, $outcome, $import->id, null, $outcome === 'imported' ? null : __('Read in a dry run; its report waits for review.'));
            $this->move($dir, [$name], '.processed', $stamp);
        } catch (Throwable $e) {
            $this->fail($folder, $dir, [$name], $stamp, $e);
        }

        return 1;
    }

    /** Files of one stem, with their sidecar if there is one, as one record. */
    private function single(WatchFolder $folder, string $dir, array $names, ?string $sidecar, string $stamp): int
    {
        try {
            $meta = $sidecar ? Intake::sidecar((string) file_get_contents("$dir/$sidecar")) : [];
            $record = $this->intake->record($folder->user, $folder->repository, $folder->parent, $folder->level, $meta,
                array_map(fn ($n) => ['path' => $this->copy("$dir/$n"), 'name' => $n], $names), $folder->language);
            foreach ($names as $name) {
                $this->log($folder, $name, "$dir/$name", 'item-created', null, $record->id, null);
            }
            $this->move($dir, array_filter([...$names, $sidecar]), '.processed', $stamp);
        } catch (Throwable $e) {
            $this->fail($folder, $dir, array_filter([...$names, $sidecar]), $stamp, $e);
        }

        return count($names);
    }

    private function bagFolder(WatchFolder $folder, string $dir, string $entry, string $stamp): int
    {
        try {
            $this->bagFiles($folder, Intake::bag("$dir/$entry"), $entry);
            $this->move($dir, [$entry], '.processed', $stamp);
        } catch (Throwable $e) {
            $this->fail($folder, $dir, [$entry], $stamp, $e);
        }

        return 1;
    }

    private function bagZip(WatchFolder $folder, string $dir, string $name, string $stamp): int
    {
        $work = sys_get_temp_dir().'/bag-'.Str::random(12);
        try {
            $zip = new ZipArchive;
            $zip->open("$dir/$name", ZipArchive::RDONLY) === true || throw new InvalidArgumentException(__('the file is not a ZIP the Workspace can open'));
            Archives::zip($zip);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                in_array('..', explode('/', (string) $zip->getNameIndex($i)), true) && throw new InvalidArgumentException(__('the ZIP names a file outside itself'));
            }
            $zip->extractTo($work);
            $zip->close();
            $root = is_file("$work/bagit.txt") ? $work : (glob("$work/*/bagit.txt")[0] ?? null);
            $root = $root === $work ? $work : ($root ? dirname($root) : throw new InvalidArgumentException(__('the ZIP holds no bag')));
            $this->bagFiles($folder, Intake::bag($root), $name);
            $this->move($dir, [$name], '.processed', $stamp);
        } catch (Throwable $e) {
            $this->fail($folder, $dir, [$name], $stamp, $e);
        } finally {
            exec('rm -rf '.escapeshellarg($work));
        }

        return 1;
    }

    /** A bag's payload: each stem one record, a sidecar inside the bag giving its description. */
    private function bagFiles(WatchFolder $folder, array $files, string $bagName): void
    {
        $sidecars = [];
        $groups = [];
        foreach ($files as $file) {
            if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) === 'xml' && $this->isSidecar($file['path'])) {
                $sidecars[dirname($file['name']).'/'.pathinfo($file['name'], PATHINFO_FILENAME)] = $file['path'];
            } else {
                $groups[dirname($file['name']).'/'.Intake::stem(basename($file['name']))][] = ['path' => $this->copy($file['path']), 'name' => basename($file['name'])];
            }
        }
        foreach ($groups as $key => $group) {
            $meta = isset($sidecars[$key]) ? Intake::sidecar((string) file_get_contents($sidecars[$key])) : [];
            $record = $this->intake->record($folder->user, $folder->repository, $folder->parent, $folder->level, $meta, $group, $folder->language);
            foreach ($group as $file) {
                $this->log($folder, $bagName.'/'.$file['name'], null, 'item-created', null, $record->id, null);
            }
        }
    }

    private function isSidecar(string $path): bool
    {
        return str_contains((string) file_get_contents($path, false, null, 0, 2048), 'heratioScan');
    }

    private function zipIsBag(string $path): bool
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }
        $bag = $zip->locateName('bagit.txt') !== false;
        for ($i = 0; ! $bag && $i < $zip->numFiles; $i++) {
            $bag = substr_count(rtrim((string) $zip->getNameIndex($i), '/'), '/') === 1 && basename((string) $zip->getNameIndex($i)) === 'bagit.txt';
        }
        $zip->close();

        return $bag;
    }

    /** The store takes the file it is given; a copy leaves the folder's file to be moved aside. */
    private function copy(string $path): string
    {
        $copy = tempnam(sys_get_temp_dir(), 'intake');
        copy($path, $copy) || throw new RuntimeException(__('the file could not be read'));

        return $copy;
    }

    private function move(string $dir, array $names, string $to, string $stamp): void
    {
        $target = "$dir/$to/$stamp";
        is_dir($target) || mkdir($target, 0770, true);
        foreach ($names as $name) {
            @rename("$dir/$name", "$target/$name");
        }
    }

    private function fail(WatchFolder $folder, string $dir, array $names, string $stamp, Throwable $e): void
    {
        $message = $e instanceof InvalidArgumentException || $e instanceof RuntimeException ? $e->getMessage() : __('The file could not be taken in.');
        report($e);
        foreach ($names as $name) {
            $this->log($folder, $name, is_file("$dir/$name") ? "$dir/$name" : null, 'failed', null, null, $message);
        }
        $this->move($dir, $names, '.failed', $stamp);
        @file_put_contents("$dir/.failed/$stamp/".$names[0].'.error.txt', $message."\n");
    }

    private function log(WatchFolder $folder, string $name, ?string $path, string $outcome, ?string $importId, ?string $recordId, ?string $message): void
    {
        IntakeFile::create(['institution_id' => $folder->institution_id, 'watch_folder_id' => $folder->id, 'file_name' => mb_substr($name, 0, 500),
            'sha256' => $path && is_file($path) ? hash_file('sha256', $path) : null, 'size_bytes' => $path && is_file($path) ? filesize($path) : null,
            'outcome_id' => Concept::where('iri', Vocab::BASE.'intake-outcome/'.$outcome)->value('id'), 'import_id' => $importId, 'record_resource_id' => $recordId,
            'message' => $message, 'created_at' => now()]);
    }
}
