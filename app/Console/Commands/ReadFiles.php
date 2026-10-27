<?php

// OpenRiC Workspace - read the files kept before uploads did: their embedded metadata, and a PDF's text (roadmap phase 5).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Heratio's batchExtract (ahg-pdf-tools and ahg-metadata-extraction) as one command: a job is
// queued for each master whose embedded metadata was never read, and for each PDF whose
// text was never read, so the command can be run again. What they propose comes from
// the account named, since no account is recorded as the one who gave an older file.

namespace App\Console\Commands;

use App\Jobs\ExtractFileText;
use App\Jobs\JobsService;
use App\Jobs\ReadEmbeddedMetadata;
use App\Models\BackgroundJob;
use App\Models\Instantiation;
use App\Models\User;
use Illuminate\Console\Command;

class ReadFiles extends Command
{
    protected $signature = 'workspace:files:read {--as= : the email address of the account the proposals come from}';

    protected $description = 'Queue the embedded metadata of every master never read, and the text of every PDF never read';

    public function handle(JobsService $jobs): int
    {
        $user = User::where('email', (string) $this->option('as'))->first();
        if ($user === null) {
            $this->error('Name the account the proposals come from: --as=<email address>');

            return self::FAILURE;
        }
        $master = fn ($q) => $q->whereHas('use', fn ($q) => $q->where('notation', 'master'));
        $metadata = $text = 0;
        Instantiation::query()->where('institution_id', $user->institution_id)->whereNull('deleted_at')->whereHas('files', $master)
            ->with('files.use')->orderBy('id')
            ->chunkById(200, function ($chunk) use ($jobs, $user, &$metadata, &$text) {
                // A file read already, or waiting to be, has a job of the kind that did not fail; a PDF's may have found no text (a scan).
                $read = fn (string $type) => BackgroundJob::where('subject_table', 'instantiations')->whereIn('subject_id', $chunk->modelKeys())
                    ->whereHas('type', fn ($q) => $q->where('notation', $type))
                    ->whereHas('status', fn ($q) => $q->whereNotIn('notation', ['failed', 'cancelled']))->pluck('subject_id')->all();
                [$readMetadata, $readText] = [$read('embedded-metadata'), $read('file-text')];
                foreach ($chunk as $instantiation) {
                    $file = $instantiation->file('master');
                    if ($file->embedded_metadata === null && ! in_array($instantiation->id, $readMetadata, true)) {
                        $job = $jobs->create($instantiation->institution_id, 'embedded-metadata', __('Embedded metadata of :name', ['name' => $file->original_name]), $user, $instantiation);
                        ReadEmbeddedMetadata::dispatch($job->id, $instantiation->id);
                        $metadata++;
                    }
                    if ($file->mime_type === 'application/pdf' && ! in_array($instantiation->id, $readText, true)) {
                        $job = $jobs->create($instantiation->institution_id, 'file-text', __('Text of :name', ['name' => $file->original_name]), $user, $instantiation);
                        ExtractFileText::dispatch($job->id, $instantiation->id);
                        $text++;
                    }
                }
            });
        $this->line("Queued: the embedded metadata of $metadata files, the text of $text PDFs.");

        return self::SUCCESS;
    }
}
