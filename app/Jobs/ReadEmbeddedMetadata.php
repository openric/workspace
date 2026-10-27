<?php

// OpenRiC Workspace - the embedded metadata of an uploaded file, kept on its master and proposed for its record (roadmap phase 5).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Heratio's extractAndApplyOnUpload, with review between: what a description can use
// (its creator, date, title, description and copyright) is proposed for the record by the
// account that gave the file, through the same service as proposals sent through the API
// (ADR 0007), which skips what is waiting or accepted already. Keywords have no proposal
// kind yet, so they stay in the embedded metadata shown with the file.

namespace App\Jobs;

use App\Description\InstantiationService;
use App\Files\EmbeddedMetadata;
use App\Models\BackgroundJob;
use App\Models\Instantiation;
use App\Proposals\ProposalService;

class ReadEmbeddedMetadata extends TrackedJob
{
    /** What proposes the values, as the proposals' origin label. */
    public const LABEL = 'embedded-metadata';

    /** Normalised values and the ISAD(G) elements they are proposed for. */
    private const FIELDS = ['title' => 'title', 'description' => 'scope-and-content', 'copyright' => 'conditions-governing-reproduction'];

    public function __construct(string $jobId, public string $instantiationId)
    {
        parent::__construct($jobId);
    }

    protected function run(BackgroundJob $job): array
    {
        $instantiation = Instantiation::findOrFail($this->instantiationId);
        $master = $instantiation->file('master') ?? throw new \RuntimeException('the instantiation has no master');
        $reader = app(EmbeddedMetadata::class);
        $raw = $reader->extract(app(InstantiationService::class)->path($master));
        $master->update(['embedded_metadata' => $raw]);
        $values = $reader->normalise($raw);
        $from = fn (string $field) => ['evidence' => 'Embedded in '.$master->original_name.' as '.$values[$field]['tag'].'.'];

        $entities = [];
        if (isset($values['creator'])) {
            foreach (array_slice(array_filter(array_map('trim', explode(';', $values['creator']['value']))), 0, 10) as $name) {
                $entities[] = ['type' => 'PERSON', 'value' => mb_substr($name, 0, 500)] + $from('creator');
            }
        }
        if (isset($values['date'])) {
            $entities[] = ['type' => 'DATE', 'value' => $values['date']['value']] + $from('date');
        }
        $fields = [];
        foreach (self::FIELDS as $field => $element) {
            if (isset($values[$field])) {
                $fields[] = ['field' => $element, 'text' => mb_substr($values[$field]['value'], 0, $element === 'title' ? 1024 : 20000)] + $from($field);
            }
        }
        if (($entities === [] && $fields === []) || $job->user === null) {
            return ['tags' => count($raw), 'proposed' => 0];
        }
        $answer = app(ProposalService::class)->propose($instantiation->record, $job->user, null, ['source' => self::LABEL, 'entities' => $entities, 'fields' => $fields])['body'];

        return ['tags' => count($raw), 'proposed' => count($answer['queued']) + count($answer['fields_queued'])];
    }
}
