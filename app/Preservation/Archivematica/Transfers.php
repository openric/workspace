<?php

// OpenRiC Workspace - units of description sent to Archivematica and DIPs fetched back (roadmap phase 15d, ADR 0022),
// after Heratio's ahg-archivematica TransferService, PollArchivematicaJobs and IngestDipsCommand, against Archivematica's
// documented Dashboard and Storage Service APIs (Dr Johan Pieterse, 4 November 2026: "Build last, against its API").
// A unit's masters, with what it includes, are staged in the transfer source under objects/, with metadata/metadata.csv
// giving the unit's IRI as dc.identifier so the DIP that comes back finds it; the transfer is started, approved once
// Archivematica lists it, and followed through transfer and ingest to its AIP. DIPs the Storage Service holds are
// fetched and taken in by DipIngest, each once.
// Changed from Heratio: no processing configuration of its own is shipped (Archivematica's is Artefactual's); an
// operator's processingMCP.xml in the staging folder is used where there is one, else Archivematica's default.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Preservation\Archivematica;

use App\Description\InstantiationService;
use App\Description\RecordService;
use App\Files\FileStore;
use App\Models\ArchivematicaJob;
use App\Models\ArchivematicaLink;
use App\Models\ArchivematicaSettings;
use App\Models\Concept;
use App\Models\Instantiation;
use App\Models\RecordResource;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class Transfers
{
    /** Archivematica's statuses that end a job (its Dashboard API's status values). */
    private const FAILED = ['FAILED', 'REJECTED'];

    public function __construct(private Client $client, private FileStore $store, private RecordService $records, private DipIngest $dips) {}

    /** Stage the unit's masters, start the transfer and approve it; the job that follows it. */
    public function send(RecordResource $unit, User $actor, ?string $type = null): ArchivematicaJob
    {
        $actor->hasPermission(Vocab::permission('manage-institution')) && $unit->institution_id === $actor->institution_id
            || throw new InvalidArgumentException(__('Only who administers the institution sends its records to Archivematica.'));
        $settings = $this->client->settings();
        $settings->dashboardConfigured() || throw new InvalidArgumentException(__('The Archivematica Dashboard is not set up (its address, user and API key).'));
        (filled($settings->staging_path) && filled($settings->transfer_source_path)) || throw new InvalidArgumentException(__('The Archivematica transfer source is not set up: give its folder as Archivematica reads it and as this server writes it.'));
        $type = $type ?? $settings->transfer_type ?: 'standard';
        $name = 'workspace-'.(Str::slug((string) $unit->referenceCode('-')) ?: $unit->id).'-'.now()->format('YmdHis');
        $job = ArchivematicaJob::create(['institution_id' => $unit->institution_id, 'record_resource_id' => $unit->id, 'direction_id' => $this->concept('archivematica-direction', 'to-archivematica'),
            'status_id' => $this->concept('archivematica-job-status', 'pending'), 'name' => $name, 'user_id' => $actor->id, 'payload' => ['type' => $type]]);
        try {
            $staged = $this->stage($unit, $actor, rtrim($settings->staging_path, '/').'/'.$name);
            $started = $this->client->startTransfer($name, $type, rtrim($settings->transfer_source_path, '/').'/'.$name);
            $directory = basename((string) ($started['path'] ?? '')) ?: $name;
            $directory = $this->awaitUnapproved($directory, $type);
            $uuid = (string) ($this->client->approveTransfer($type, $directory)['uuid'] ?? '');
            $uuid !== '' || throw new RuntimeException(__('Archivematica approved the transfer but gave no UUID.'));
            $job->update(['status_id' => $this->concept('archivematica-job-status', 'processing'), 'transfer_uuid' => $uuid, 'last_polled_at' => now(),
                'payload' => ['type' => $type, 'directory' => $directory, 'files' => $staged, 'start' => $started]]);
        } catch (Throwable $e) {
            $job->update(['status_id' => $this->concept('archivematica-job-status', 'failed'), 'error' => mb_substr($e->getMessage(), 0, 4000)]);
        }

        return $job->fresh();
    }

    /** Follow each transfer under way one step: through transfer and ingest to its AIP. The number looked at. */
    public function poll(): int
    {
        $jobs = ArchivematicaJob::whereHas('status', fn ($q) => $q->where('notation', 'processing'))
            ->whereHas('direction', fn ($q) => $q->where('notation', 'to-archivematica'))->orderBy('last_polled_at')->limit(100)->get();
        foreach ($jobs as $job) {
            try {
                if ($job->sip_uuid === null) {
                    $status = $this->client->transferStatus((string) $job->transfer_uuid);
                    $job->fill(['microservice' => $status['microservice'] ?? $job->microservice, 'sip_uuid' => $status['sip_uuid'] ?? null]);
                    if (in_array($status['status'] ?? '', self::FAILED, true)) {
                        $job->fill(['status_id' => $this->concept('archivematica-job-status', 'failed'), 'error' => __('Archivematica: the transfer :status at :step.', ['status' => mb_strtolower((string) $status['status']), 'step' => $status['microservice'] ?? '?'])]);
                    }
                } else {
                    $status = $this->client->ingestStatus($job->sip_uuid);
                    $job->fill(['microservice' => $status['microservice'] ?? $job->microservice]);
                    if (($status['status'] ?? '') === 'COMPLETE') {
                        // Archivematica names the AIP by its SIP's UUID.
                        $job->fill(['status_id' => $this->concept('archivematica-job-status', 'complete'), 'aip_uuid' => $status['uuid'] ?? $job->sip_uuid]);
                        ArchivematicaLink::firstOrCreate(['aip_uuid' => $job->aip_uuid, 'record_resource_id' => $job->record_resource_id], ['institution_id' => $job->institution_id,
                            'transfer_uuid' => $job->transfer_uuid, 'sip_uuid' => $job->sip_uuid, 'pipeline_uuid' => $this->client->settings()->pipeline_uuid,
                            'status_id' => $this->concept('archivematica-link-status', 'pending')]);
                    } elseif (in_array($status['status'] ?? '', self::FAILED, true)) {
                        $job->fill(['status_id' => $this->concept('archivematica-job-status', 'failed'), 'error' => __('Archivematica: the ingest :status at :step.', ['status' => mb_strtolower((string) $status['status']), 'step' => $status['microservice'] ?? '?'])]);
                    }
                }
            } catch (Throwable $e) {
                // Archivematica not answering is not the transfer failing: it is tried again at the next poll.
                $job->fill(['error' => mb_substr($e->getMessage(), 0, 4000)]);
            }
            $job->fill(['last_polled_at' => now()])->save();
        }

        return $jobs->count();
    }

    /** Fetch the DIPs the Storage Service holds and has not given yet, and take each in. The links made. */
    public function fetchDips(): array
    {
        $settings = $this->client->settings();
        if (! $settings->fetch_dips || ! $settings->storageConfigured()) {
            return [];
        }
        $links = [];
        foreach ($this->client->packages('DIP', ['status' => 'UPLOADED']) as $package) {
            $uuid = strtolower((string) ($package['uuid'] ?? ''));
            if ($uuid === '' || ArchivematicaLink::where('dip_uuid', $uuid)->whereHas('status', fn ($q) => $q->whereIn('notation', ['linked', 'unmatched']))->exists()) {
                continue;
            }
            $file = sys_get_temp_dir().'/wsdip-'.bin2hex(random_bytes(6));
            try {
                $this->client->download($uuid, $file);
                $links[] = $link = $this->dips->fromArchive($file, null, $uuid, null);
                // A DIP that found no unit belongs to no institution: its link says so, and there is no job to show.
                $link->institution_id && ArchivematicaJob::create(['institution_id' => $link->institution_id, 'record_resource_id' => $link->record_resource_id,
                    'direction_id' => $this->concept('archivematica-direction', 'from-archivematica'), 'status_id' => $this->concept('archivematica-job-status', $link->status->notation === 'linked' ? 'complete' : 'failed'),
                    'name' => 'DIP '.$uuid, 'aip_uuid' => $link->aip_uuid, 'error' => $link->note, 'last_polled_at' => now()]);
            } catch (Throwable $e) {
                $links[] = ArchivematicaLink::updateOrCreate(['dip_uuid' => $uuid], ['status_id' => $this->concept('archivematica-link-status', 'failed'), 'note' => mb_substr($e->getMessage(), 0, 4000)]);
            } finally {
                @unlink($file);
            }
        }

        return $links;
    }

    /** @return array{dashboard: string, storage: string} whether each answers, in words */
    public function ping(): array
    {
        $out = [];
        foreach (['dashboard' => fn () => count($this->client->unapproved()).' '.__('transfers waiting for approval'), 'storage' => fn () => count($this->client->packages('DIP', [], 1)).' '.__('DIP seen')] as $service => $try) {
            try {
                $out[$service] = __('answers').': '.$try();
            } catch (Throwable $e) {
                $out[$service] = $e->getMessage();
            }
        }

        return $out;
    }

    /** The unit's masters, and those of what it includes, copied into the transfer folder; the number copied. */
    private function stage(RecordResource $unit, User $actor, string $folder): int
    {
        is_dir($folder.'/objects') || mkdir($folder.'/objects', 0775, true) || throw new RuntimeException(__('The transfer folder :folder cannot be written to.', ['folder' => $folder]));
        $staged = 0;
        foreach ([$unit, ...array_column(RecordService::flatten($this->records->hierarchy($unit, $actor), $unit->id), 0)] as $u) {
            $sub = Str::slug((string) $u->referenceCode('-')) ?: $u->id;
            foreach (Instantiation::where('record_resource_id', $u->id)->whereNull('deleted_at')->with('files.use')->get() as $instantiation) {
                if ($master = $instantiation->files->first(fn ($f) => $f->use->notation === 'master')) {
                    $target = $folder.'/objects/'.$sub.'/'.InstantiationService::logicalName($master->original_name);
                    is_dir(dirname($target)) || mkdir(dirname($target), 0775, true);
                    copy($this->store->path($master->storage_path), $target) || throw new RuntimeException(__('The file :name could not be staged.', ['name' => $master->original_name]));
                    $staged++;
                }
            }
        }
        $staged > 0 || throw new InvalidArgumentException(__('The unit has no master to send.'));
        mkdir($folder.'/metadata', 0775, true);
        $csv = fn ($v) => '"'.str_replace('"', '""', (string) $v).'"';
        file_put_contents($folder.'/metadata/metadata.csv', "filename,dc.identifier,dc.title\nobjects,".$csv($unit->iri).','.$csv(EntityKinds::label($unit))."\n");
        $own = rtrim((string) ArchivematicaSettings::current()->staging_path, '/').'/processingMCP.xml';
        is_file($own) && copy($own, $folder.'/processingMCP.xml');

        return $staged;
    }

    /** Archivematica copies a transfer in before it can be approved: wait until it lists it. */
    private function awaitUnapproved(string $directory, string $type, int $tries = 12): string
    {
        for ($i = 0; $i < $tries; $i++) {
            foreach ($this->client->unapproved() as $row) {
                if (($row['directory'] ?? '') === $directory || str_starts_with((string) ($row['directory'] ?? ''), $directory)) {
                    return (string) $row['directory'];
                }
            }
            usleep(1_000_000);
        }

        return $directory;
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE."$vocabulary/$notation")->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
