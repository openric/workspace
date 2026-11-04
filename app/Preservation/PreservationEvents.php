<?php

// OpenRiC Workspace - what is done to each file, kept as PREMIS 3 events (roadmap phase 15a), after Heratio's
// ahg-preservation PreservationService::logEvent: the event, its outcome and detail, and the agent that did it, a person
// or the software and its version. A file's own model events record its ingestion or creation, the digest worked out at
// once and its virus check, and its deletion; fixity checks, format identification and metadata extraction record theirs
// where they happen.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Preservation;

use App\Models\Concept;
use App\Models\DigitalFile;
use App\Models\PreservationEvent;
use App\Models\User;
use App\Support\Vocab;
use InvalidArgumentException;

class PreservationEvents
{
    /** @var array<string, string> concept ids by vocabulary and notation, once looked up */
    private array $concepts = [];

    /** The Workspace as the agent of what it does by itself. */
    public static function workspace(): string
    {
        return 'OpenRiC Workspace '.(trim((string) @file_get_contents(base_path('VERSION'))) ?: 'dev');
    }

    public function record(DigitalFile $file, string $type, string $outcome = 'success', ?string $detail = null, ?string $outcomeDetail = null,
        ?User $person = null, ?string $software = null, ?string $sourceFileId = null): PreservationEvent
    {
        $instantiation = $file->relationLoaded('instantiation') ? $file->instantiation : $file->instantiation()->first();

        return PreservationEvent::create(['institution_id' => $file->institution_id, 'digital_file_id' => $file->id, 'instantiation_id' => $file->instantiation_id,
            'record_resource_id' => $instantiation?->record_resource_id, 'file_label' => $file->original_name,
            'event_type_id' => $this->concept('premis-event-type', $type), 'outcome_id' => $this->concept('premis-event-outcome', $outcome),
            'detail' => $detail, 'outcome_detail' => $outcomeDetail === null ? null : mb_substr($outcomeDetail, 0, 4000),
            'agent_type_id' => $this->concept('premis-agent-type', $person ? 'person' : 'software'),
            'agent_name' => $person ? $person->name : ($software ?? self::workspace()), 'user_id' => $person?->id, 'source_file_id' => $sourceFileId, 'occurred_at' => now()]);
    }

    /** A file kept: its ingestion, or its creation from the master, then its digest and its virus check. */
    public function kept(DigitalFile $file): void
    {
        $file->loadMissing('use', 'instantiation');
        $person = auth()->user();
        if ($file->use?->notation === 'master') {
            $this->record($file, 'ingestion', 'success', __('Received as :name and kept under its SHA-256.', ['name' => $file->original_name]), null, $person);
        } else {
            $master = DigitalFile::where('instantiation_id', $file->instantiation_id)->whereHas('use', fn ($q) => $q->where('notation', 'master'))->first();
            $this->record($file, 'creation', 'success', __('A :use copy made from the master.', ['use' => (string) $file->use?->notation]), null, null, null, $master?->id);
        }
        $this->record($file, 'message-digest-calculation', 'success', 'SHA-256', $file->sha256);
        if ($file->scanned_with) {
            $this->record($file, 'virus-check', 'success', __('No virus found.'), null, null, $file->scanned_with);
        }
    }

    public function removed(DigitalFile $file): void
    {
        $this->record($file, 'deletion', 'success', __('Removed from the object store.'), null, auth()->user());
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return $this->concepts["$vocabulary/$notation"] ??= Concept::where('iri', Vocab::BASE."$vocabulary/$notation")->value('id')
            ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
