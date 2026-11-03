<?php

// OpenRiC Workspace - an import as a BagIt bag: what came in, and what became of it (roadmap phase 22a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After PSIS's ahgIngestPlugin, which packaged an ingest as an OAIS submission information package. Here the package is
// a BagIt 1.0 bag (RFC 8493), the form an OAIS SIP is usually exchanged in: data/source holds the file as it was
// uploaded (the ZIP, when it came in one), data/files the master files the import kept, each under its record's
// identifier, and data/entries.csv each entry of the file with what became of it and its persistent IRI. Every
// payload file is in manifest-sha256.txt, and the tag files in tagmanifest-sha256.txt.

namespace App\Ingest;

use App\Exchange\Import\ImportService;
use App\Files\FileStore;
use App\Models\Import;
use App\Models\Instantiation;
use App\Models\RecordResource;
use Illuminate\Support\Facades\DB;
use ZipArchive;

class ImportBag
{
    public function __construct(private FileStore $store) {}

    /** The bag as a ZIP in a temporary file, and the name to give it. @return array{0: string, 1: string} */
    public function build(Import $import): array
    {
        $name = 'import-'.substr($import->id, 0, 8);
        $zipPath = tempnam(sys_get_temp_dir(), 'bag');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::OVERWRITE);
        [$manifest, $octets] = [[], 0];
        $add = function (string $inBag, string $bytes = '', ?string $file = null) use ($zip, $name, &$manifest, &$octets) {
            $file ? $zip->addFile($file, "$name/$inBag") : $zip->addFromString("$name/$inBag", $bytes);
            $manifest[$inBag] = $file ? hash_file('sha256', $file) : hash('sha256', $bytes);
            $octets += $file ? (int) filesize($file) : strlen($bytes);
        };
        $source = $import->options['bundle'] ?? null;
        $source ? $add('data/source/'.self::safe($import->file_name), '', $this->store->path($source)) : $add('data/source/'.self::safe($import->file_name), $import->content);

        $units = collect($import->report['units'] ?? []);
        $table = ImportService::TABLES[$import->format] ?? 'record_resources';
        $records = $table === 'record_resources' ? RecordResource::whereIn('id', $units->pluck('unit_id')->filter()->all())->get()->keyBy('id') : collect();
        $rows = fopen('php://memory', 'r+');
        fputcsv($rows, ['location', 'key', 'title', 'action', 'iri', 'message'], escape: '');
        foreach ($units as $unit) {
            $iri = $unit['unit_id'] ? (string) DB::table($table)->where('id', $unit['unit_id'])->value('iri') : '';
            fputcsv($rows, [$unit['location'], $unit['key'], $unit['title'], $unit['action'], $iri, $unit['message'] ?? ''], escape: '');
        }
        rewind($rows);
        $add('data/entries.csv', (string) stream_get_contents($rows));
        fclose($rows);
        // The master files kept by the import: those added to its records while it was committed.
        if ($import->committed_at && $records->isNotEmpty()) {
            $carriers = Instantiation::whereIn('record_resource_id', $records->keys())->whereBetween('created_at', [$import->created_at, $import->committed_at])
                ->with('files.use')->get();
            foreach ($carriers as $carrier) {
                $master = $carrier->files->first(fn ($f) => $f->use->notation === 'master');
                if ($master && is_file($path = $this->store->path($master->storage_path))) {
                    $record = $records[$carrier->record_resource_id];
                    $add('data/files/'.self::safe((string) ($record->referenceCode() ?? $record->id)).'/'.self::safe($master->original_name), '', $path);
                }
            }
        }
        $payload = '';
        foreach ($manifest as $file => $sha) {
            $payload .= "$sha  $file\n";
        }
        $tags = ['bagit.txt' => "BagIt-Version: 1.0\nTag-File-Character-Encoding: UTF-8\n",
            'bag-info.txt' => 'Source-Organization: '.$import->institution?->name."\n".'Bagging-Date: '.now()->toDateString()."\n"
                .'External-Identifier: '.$import->id."\n".'External-Description: '.$import->file_name.' ('.$import->format.'), imported '.($import->committed_at?->toIso8601String() ?? 'not yet')."\n"
                .'Payload-Oxum: '.$octets.'.'.count($manifest)."\n",
            'manifest-sha256.txt' => $payload];
        $tagManifest = '';
        foreach ($tags as $file => $bytes) {
            $zip->addFromString("$name/$file", $bytes);
            $tagManifest .= hash('sha256', $bytes)."  $file\n";
        }
        $zip->addFromString("$name/tagmanifest-sha256.txt", $tagManifest);
        $zip->close();

        return [$zipPath, "$name.zip"];
    }

    private static function safe(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'file';
    }
}
