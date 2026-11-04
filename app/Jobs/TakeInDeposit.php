<?php

// OpenRiC Workspace - take in a DIP deposited by SWORD (roadmap phase 15d), in the background as AtoM's qtSwordPluginWorker
// does: its files kept with the unit it was deposited to, the deposit recorded as a job from Archivematica.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Jobs;

use App\Models\ArchivematicaJob;
use App\Models\Concept;
use App\Models\RecordResource;
use App\Models\User;
use App\Preservation\Archivematica\DipIngest;
use App\Support\Vocab;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class TakeInDeposit implements ShouldQueue
{
    use Queueable;

    public $timeout = 7200;

    /** @param  array{archive?: string, path?: string}  $source */
    public function __construct(public string $depositId, public string $recordId, public string $userId, public array $source) {}

    public function handle(DipIngest $dips): void
    {
        $record = RecordResource::find($this->recordId);
        $user = User::find($this->userId);
        $concept = fn (string $v, string $n) => Concept::where('iri', Vocab::BASE."$v/$n")->value('id');
        if ($record === null || $user === null) {
            return;
        }
        $job = ArchivematicaJob::create(['institution_id' => $record->institution_id, 'record_resource_id' => $record->id, 'direction_id' => $concept('archivematica-direction', 'from-archivematica'),
            'status_id' => $concept('archivematica-job-status', 'processing'), 'name' => 'SWORD deposit '.$this->depositId, 'user_id' => $user->id, 'payload' => ['sword' => $this->depositId]]);
        try {
            $path = $this->source['path'] ?? null;
            $link = $path !== null && is_dir($path) ? $dips->fromFolder($path, $record, null, $user) : $dips->fromArchive($path ?? $this->source['archive'], $record, null, $user);
            $job->update(['status_id' => $concept('archivematica-job-status', $link->status->notation === 'linked' ? 'complete' : 'failed'), 'aip_uuid' => $link->aip_uuid, 'error' => $link->note]);
        } catch (Throwable $e) {
            $job->update(['status_id' => $concept('archivematica-job-status', 'failed'), 'error' => mb_substr($e->getMessage(), 0, 4000)]);
        } finally {
            isset($this->source['archive']) && @unlink($this->source['archive']);
        }
    }
}
