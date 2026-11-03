<?php

// OpenRiC Workspace - what an import is given beyond a CSV or XML file: a spreadsheet, or a ZIP of a spreadsheet and the
// files its rows name (roadmap phase 22a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After PSIS's ahgIngestPlugin (processSpreadsheetUpload, XlsxConverter, extractZip, matchDigitalObjects): an Excel or
// OpenDocument sheet is read as the CSV of its first sheet; a ZIP holding one spreadsheet or CSV is read as that,
// and the ZIP is kept in the object store, so a row's digitalObjectPath (or a heading mapped to it) finds its file
// inside the ZIP when the import is committed. Changed: PSIS unpacked the ZIP on the server's disk; here a file is read
// from the ZIP only when it is kept, and a name that climbs out of the ZIP is refused.

namespace App\Ingest;

use App\Files\FileStore;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use ZipArchive;

class IngestFile
{
    private const SHEETS = ['xlsx', 'xls', 'ods'];

    public function __construct(private FileStore $store) {}

    /**
     * The CSV to import, and the ZIP kept for its files if it came in one.
     *
     * @return array{content: string, bundle: ?string}
     */
    public function read(string $institutionId, string $path, string $name): array
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($extension, self::SHEETS, true)) {
            return ['content' => $this->sheet($path), 'bundle' => null];
        }
        if (! str_starts_with((string) file_get_contents($path, false, null, 0, 4), "PK\x03\x04")) {
            return ['content' => (string) file_get_contents($path), 'bundle' => null];
        }
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::RDONLY) === true || throw new InvalidArgumentException(__('the file is not a ZIP the Workspace can open'));
        $tables = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            if (! str_starts_with(basename($entry), '.') && in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), ['csv', ...self::SHEETS], true)) {
                $tables[] = $entry;
            }
        }
        count($tables) === 1 || throw new InvalidArgumentException(__('the ZIP must hold exactly one CSV or spreadsheet, with the files its rows name'));
        $table = $tables[0];
        if (strtolower(pathinfo($table, PATHINFO_EXTENSION)) === 'csv') {
            $content = (string) $zip->getFromName($table);
        } else {
            $temp = tempnam(sys_get_temp_dir(), 'sheet').'.'.pathinfo($table, PATHINFO_EXTENSION);
            file_put_contents($temp, (string) $zip->getFromName($table));
            $content = $this->sheet($temp);
            @unlink($temp);
        }
        $zip->close();

        return ['content' => $content, 'bundle' => $this->store->ingest($institutionId, $path)['storage_path']];
    }

    /**
     * A file the bundle holds, written to a temporary path for the store to take; null where the ZIP has no such file.
     * The name is matched as written, then by its file name alone, as PSIS matched by file name.
     */
    public function extract(string $bundle, string $name): ?string
    {
        $name = str_replace('\\', '/', trim($name));
        if ($name === '' || str_contains($name, "\0") || in_array('..', explode('/', $name), true)) {
            return null;
        }
        $zip = new ZipArchive;
        if ($zip->open($this->store->path($bundle), ZipArchive::RDONLY) !== true) {
            return null;
        }
        $index = $zip->locateName(ltrim($name, '/'));
        if ($index === false) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (basename((string) $zip->getNameIndex($i)) === basename($name)) {
                    $index = $i;
                    break;
                }
            }
        }
        $stream = $index === false ? false : $zip->getStream((string) $zip->getNameIndex($index));
        if ($stream === false) {
            $zip->close();

            return null;
        }
        $temp = tempnam(sys_get_temp_dir(), 'bundle');
        $out = fopen($temp, 'w');
        stream_copy_to_stream($stream, $out);
        fclose($out);
        fclose($stream);
        $zip->close();

        return $temp;
    }

    /** The first sheet as CSV (PSIS's XlsxConverter), formulas as their values. */
    private function sheet(string $path): string
    {
        $rows = IOFactory::load($path)->getSheet(0)->toArray(null, true, true, false);
        $out = fopen('php://memory', 'r+');
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($cell) => $cell === null ? '' : (string) $cell, $row), escape: '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
