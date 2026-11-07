<?php

// OpenRiC Workspace - the Europeana export (roadmap phase 16d), after Heratio's ahg-federation EuropeanaExportService:
// the institution's published units of description, or those changed since a time, each as an EDM file, with a sitemap
// of them, in one ZIP bundle kept in an OCFL object of its own, for an aggregator to take in; each run kept.
// Changed from Heratio: only what the public reads goes in, as every export says it; the bundle is kept, not left in a folder.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Federation;

use App\Description\RecordService;
use App\Exchange\Edm;
use App\Exchange\ExportLoss;
use App\Exchange\ExportView;
use App\Files\FileStore;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

class Europeana
{
    public function __construct(private Edm $edm, private FileStore $store, private ExportLoss $loss) {}

    public function run(Institution $institution, ?string $since = null, ?User $actor = null): object
    {
        $id = (string) Str::uuid7();
        $status = fn (string $n) => Concept::where('iri', Vocab::BASE."harvest-status/$n")->value('id');
        DB::table('europeana_exports')->insert(['id' => $id, 'institution_id' => $institution->id, 'status_id' => $status('running'),
            'since' => $since ? date('c', strtotime($since)) : null, 'created_by_id' => $actor?->id, 'started_at' => now()]);
        $work = sys_get_temp_dir().'/wse-'.bin2hex(random_bytes(6));
        mkdir($work.'/records', 0700, true);
        try {
            $view = new ExportView(null, false, null, $institution);
            $ids = DB::table('search_entries')->where(['institution_id' => $institution->id, 'public' => true, 'subject_table' => 'record_resources'])
                ->when($since && strtotime($since), fn ($q) => $q->where('updated_at', '>=', date('c', strtotime($since))))->orderBy('subject_id')->pluck('subject_id');
            $files = [];
            $lost = [];
            foreach ($ids->chunk(200) as $chunk) {
                foreach (RecordResource::whereIn('id', $chunk)->whereNull('deleted_at')->with(RecordService::DESCRIBED)->get() as $unit) {
                    if (! $view->readable($unit)) {
                        continue;
                    }
                    file_put_contents($work.'/records/'.$unit->id.'.xml', $xml = $this->edm->export($unit, $view, $institution));
                    $files[] = $unit->id;
                    $lost = [...$lost, ...$this->loss->ofEach($xml, [$unit], $view)];
                }
            }
            // A sitemap of the files, as Heratio writes one beside them.
            $sitemap = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
                .implode('', array_map(fn ($f) => '  <url><loc>records/'.$f.'.xml</loc></url>'."\n", $files)).'</urlset>'."\n";
            file_put_contents($work.'/sitemap.xml', $sitemap);
            $zip = new ZipArchive;
            $bundle = $work.'/europeana.zip';
            $zip->open($bundle, ZipArchive::CREATE) === true || throw new RuntimeException(__('The package file could not be written.'));
            $zip->addFile($work.'/sitemap.xml', 'sitemap.xml');
            // What EDM leaves out of each record, beside the files (roadmap phase 23).
            $zip->addFromString('left-out.csv', ExportLoss::csv($lost));
            foreach ($files as $f) {
                $zip->addFile($work.'/records/'.$f.'.xml', 'records/'.$f.'.xml');
            }
            $zip->close();
            $kept = $this->store->into($institution->base_iri.'/europeana/'.$id)->keepMade($institution->id, $bundle, 'europeana-'.now()->format('Ymd-His').'.zip');
            DB::table('europeana_exports')->where('id', $id)->update(['status_id' => $status('success'), 'record_count' => count($files), 'storage_path' => $kept['storage_path'],
                'bundle_size' => $kept['size_bytes'], 'bundle_checksum' => $kept['sha256'], 'finished_at' => now()]);
        } catch (Throwable $e) {
            DB::table('europeana_exports')->where('id', $id)->update(['status_id' => $status('failed'), 'error' => mb_substr($e->getMessage(), 0, 2000), 'finished_at' => now()]);
        } finally {
            exec('rm -rf '.escapeshellarg($work));
        }

        return DB::table('europeana_exports')->find($id);
    }
}
