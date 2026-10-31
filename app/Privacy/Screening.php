<?php

// OpenRiC Workspace - screening a unit of description for personal information before the public receives its files
// (roadmap phase 18b, ADR 0016).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's FileScreeningGate and its scan of a record (PiiScanService::scanAndPersist and
// scanEmbeddedMetadata). A scan reads the record's names and description values, its files' embedded metadata and
// the transcript of each file, and keeps what it found. Where a repository or a record (with everything inside it)
// must be screened, the record's files are withheld from who may not change it until its latest scan clears them:
// a person released them, or redacted what was found, or a scan that read every file's text found nothing and nobody
// has withheld them since. The description is not affected; its fields are redacted one by one (Redaction).

namespace App\Privacy;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\PiiScanReport;
use App\Models\PrivacyJurisdiction;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\ScreeningScope;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Screening
{
    public const STATUSES = Vocab::BASE.'pii-scan-status/';

    public const COVERAGE = Vocab::BASE.'pii-scan-coverage/';

    /** Statuses that release the files though the scan found something. */
    private const RELEASED = ['redacted', 'accepted-risk'];

    private const MAX_FINDINGS = 500;

    /** @var array<string, bool> by record id */
    private array $required = [];

    /**
     * The text of the record a scan reads: field label => text.
     *
     * @return array{0: array<string, string>, 1: bool} the texts, and whether every file's text was among them
     */
    public function texts(RecordResource $unit): array
    {
        $texts = [];
        foreach ($unit->names()->with('nameType')->get() as $name) {
            $field = Redaction::NAME_FIELDS[$name->nameType->notation] ?? $name->nameType->notation;
            $texts["$field ($name->language)"] = (string) $name->value;
        }
        foreach ($unit->descriptionValues()->with('element')->get() as $value) {
            $texts["{$value->element->notation} ($value->language)"] = (string) $value->value;
        }
        $everyFile = true;
        foreach ($unit->instantiations()->with('files', 'descriptionValues.element', 'names')->get() as $instantiation) {
            $label = $instantiation->names->first()?->value ?? $instantiation->id;
            $transcripts = $instantiation->descriptionValues->filter(fn ($v) => $v->element->notation === 'instantiation-transcript');
            if ($instantiation->files->isNotEmpty() && $transcripts->isEmpty()) {
                $everyFile = false;
            }
            foreach ($transcripts as $t) {
                $texts[__('transcript of :name', ['name' => $label])." ($t->language)"] = (string) $t->value;
            }
            foreach ($instantiation->files as $file) {
                foreach ((array) ($file->embedded_metadata ?? []) as $tag => $value) {
                    $value = is_array($value) ? implode(' ', array_filter(array_map(fn ($v) => is_scalar($v) ? (string) $v : null, $value))) : (is_scalar($value) ? (string) $value : '');
                    if (trim($value) !== '') {
                        $texts[__('embedded metadata of :file', ['file' => $file->original_name])." ($tag)"] = $value;
                    }
                }
            }
        }

        return [array_filter($texts, fn ($t) => trim($t) !== ''), $everyFile];
    }

    public function scan(RecordResource $unit, ?string $jurisdiction, User $actor): PiiScanReport
    {
        $law = PrivacyJurisdiction::where('code', $jurisdiction ?: 'popia')->where('is_active', true)->first() ?? throw new InvalidArgumentException(__('Choose a jurisdiction from the list.'));
        $started = now();
        [$texts, $everyFile] = $this->texts($unit);
        $scanner = new PiiScanner($law->code);
        $findings = [];
        $complete = true;
        foreach ($texts as $field => $text) {
            foreach ($scanner->scan($text) as $hit) {
                $findings[] = ['field' => $field, 'type' => $hit['type'], 'value' => $hit['value'], 'confidence' => $hit['confidence']];
            }
            $complete = $complete && $scanner->lastScanWasComplete();
        }
        $byType = array_count_values(array_column($findings, 'type'));

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => PiiScanReport::create([
            'institution_id' => $unit->institution_id, 'record_resource_id' => $unit->id, 'jurisdiction_id' => $law->id,
            'started_at' => $started, 'finished_at' => $complete ? now() : null,
            'hits_total' => count($findings), 'hits_by_type' => $byType, 'findings' => array_slice($findings, 0, self::MAX_FINDINGS),
            'coverage_id' => $this->concept(self::COVERAGE, $everyFile ? 'files' : 'metadata'),
            'status_id' => $this->concept(self::STATUSES, 'pending'), 'scanned_by_id' => $actor->id,
            // A pattern the scanner could not evaluate leaves the scan unfinished, so it never clears the files.
            'notes' => $complete ? null : __('A pattern could not be evaluated; the scan is not complete.'),
        ])));
    }

    public function latest(RecordResource $unit): ?PiiScanReport
    {
        return PiiScanReport::where('record_resource_id', $unit->id)->with('status', 'coverage')->orderByDesc('started_at')->orderByDesc('id')->first();
    }

    public function requiresScreening(RecordResource $unit): bool
    {
        return $this->required[$unit->id] ??= ScreeningScope::where('institution_id', $unit->institution_id)
            ->where(fn ($q) => $q->where('repository_id', $unit->repository_id)
                ->orWhereIn('record_resource_id', [$unit->id, ...array_map(fn ($a) => $a->id, $unit->ancestors())]))->exists();
    }

    public function cleared(RecordResource $unit): bool
    {
        $latest = $this->latest($unit);
        if ($latest === null) {
            return false;
        }
        if (in_array($latest->status->notation, self::RELEASED, true)) {
            return true;
        }

        return $latest->finished_at !== null && $latest->hits_total === 0 && $latest->status->notation === 'pending' && $latest->coverage->notation === 'files';
    }

    /** Are the record's files withheld from the account, or with none the public? Who may change the record is never kept from them. */
    public function withholds(?User $user, RecordResource $unit): bool
    {
        return ! ($user !== null && $user->institution_id === $unit->institution_id && $user->may('update', 'description', $unit))
            && $this->requiresScreening($unit) && ! $this->cleared($unit);
    }

    /**
     * Release the files (risk accepted), mark what was found redacted, or withhold them, on the latest scan, with the
     * ground. Releasing needs a scan, so nothing is released unread; withholding with no scan on file records one.
     */
    public function decide(RecordResource $unit, string $decision, ?string $ground, ?string $note, User $actor): PiiScanReport
    {
        $status = ['release' => 'accepted-risk', 'redacted' => 'redacted', 'withhold' => 'withheld'][$decision] ?? throw new InvalidArgumentException(__('Choose release, redacted or withhold.'));
        $law = PrivacyJurisdiction::where('code', $ground ?: 'popia')->where('is_active', true)->first() ?? throw new InvalidArgumentException(__('Choose the ground from the list.'));
        if ($status !== 'withheld' && trim((string) $note) === '') {
            throw new InvalidArgumentException(__('Say why the files may be released.'));
        }
        $latest = $this->latest($unit);
        if ($latest === null && $status !== 'withheld') {
            throw new InvalidArgumentException(__('A record must be scanned before its files can be released.'));
        }

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($unit, $latest, $status, $law, $note, $actor) {
            $decided = ['status_id' => $this->concept(self::STATUSES, $status), 'jurisdiction_id' => $law->id, 'notes' => $note, 'decided_by_id' => $actor->id, 'decided_at' => now()];
            if ($latest !== null) {
                $latest->update($decided);

                return $latest;
            }

            return PiiScanReport::create([...$decided, 'institution_id' => $unit->institution_id, 'record_resource_id' => $unit->id, 'started_at' => now(), 'finished_at' => now(),
                'coverage_id' => $this->concept(self::COVERAGE, 'metadata'), 'scanned_by_id' => $actor->id]);
        }));
    }

    /** Screen a repository's records, or a record and everything inside it. */
    public function require(array $data, User $actor): ScreeningScope
    {
        $repository = ($data['repository_id'] ?? '') ?: null;
        $record = ($data['record_resource_id'] ?? '') ?: null;
        if (($repository === null) === ($record === null)) {
            throw new InvalidArgumentException(__('Choose a repository or a record, not both.'));
        }
        $owner = $repository ? Repository::find($repository) : RecordResource::find($record);
        if ($owner === null || $owner->institution_id !== $actor->institution_id || $owner->deleted_at !== null) {
            throw new InvalidArgumentException(__('Choose a repository or a record of the institution.'));
        }
        if (ScreeningScope::where($repository ? 'repository_id' : 'record_resource_id', $owner->id)->exists()) {
            throw new InvalidArgumentException(__('It is already screened.'));
        }

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => ScreeningScope::create(['institution_id' => $actor->institution_id,
            'repository_id' => $repository, 'record_resource_id' => $record, 'note' => ($data['note'] ?? '') ?: null, 'created_by_id' => $actor->id])));
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', $vocabulary.$notation)->value('id');
    }
}
