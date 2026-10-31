<?php

// OpenRiC Workspace - IIIF validation of a record (roadmap phase 14b, ADR 0014), after PSIS's IiifValidationService
// (validateManifest, checkDerivatives, getValidationHistory, batchValidate, getDashboardStats): what a record's
// manifest will have, checked before it is relied on, each check kept with its outcome so staff see what to put right.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Changed: the checks read the record as staff see it, so a draft is checked before it is published, and being
// published is a check of its own; PSIS's image service check is not carried, as there is no image server (ADR 0014),
// and checks of sizes and durations are added, as a canvas needs them. Checks and outcomes are vocabulary rows.

namespace App\Iiif;

use App\Api\PublicCatalogue;
use App\Audit\AuditLog;
use App\Files\FileStore;
use App\Models\Concept;
use App\Models\DigitalFile;
use App\Models\IiifValidationResult;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use App\Rights\RightsService;
use App\Support\Vocab;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Validation
{
    public const CHECKS = Vocab::BASE.'iiif-validation-check/';

    public const STATUSES = Vocab::BASE.'iiif-validation-status/';

    private const PAINTABLE = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * Check a record and keep the results.
     *
     * @return list<array{check: string, status: string, details: string}>
     */
    public function validate(RecordResource $unit, ?User $actor = null): array
    {
        $results = $this->checks($unit);
        $run = (string) Str::uuid7();
        $concepts = Concept::where('iri', 'like', Vocab::BASE.'iiif-validation-%')->pluck('id', 'iri');
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($unit, $results, $run, $concepts, $actor) {
            foreach ($results as $r) {
                IiifValidationResult::create(['institution_id' => $unit->institution_id, 'record_resource_id' => $unit->id, 'check_id' => $concepts[self::CHECKS.$r['check']],
                    'status_id' => $concepts[self::STATUSES.$r['status']], 'details' => $r['details'], 'run_id' => $run, 'validated_by_id' => $actor?->id, 'validated_at' => now()]);
            }
        }));

        return $results;
    }

    /**
     * Check every record of the institution with a file, as PSIS's batch does.
     *
     * @return array{total: int, passed: int, warning: int, failed: int}
     */
    public function validateAll(Institution $institution, ?User $actor = null): array
    {
        $summary = ['total' => 0, 'passed' => 0, 'warning' => 0, 'failed' => 0];
        $units = RecordResource::where('institution_id', $institution->id)->whereNull('deleted_at')
            ->whereHas('instantiations', fn ($q) => $q->whereNull('deleted_at')->whereHas('files'))->get();
        foreach ($units as $unit) {
            $statuses = array_column($this->validate($unit, $actor), 'status');
            $summary['total']++;
            $summary[in_array('failed', $statuses, true) ? 'failed' : (in_array('warning', $statuses, true) ? 'warning' : 'passed')]++;
        }

        return $summary;
    }

    /**
     * The latest run of each record: its worst outcome, and the records whose latest run failed, newest first.
     *
     * @return array{records: int, passed: int, warning: int, failed: int, failures: Collection}
     */
    public function dashboard(Institution $institution): array
    {
        $latest = IiifValidationResult::where('institution_id', $institution->id)
            ->whereIn('run_id', IiifValidationResult::where('institution_id', $institution->id)->selectRaw('DISTINCT ON (record_resource_id) run_id')
                ->orderBy('record_resource_id')->orderByDesc('validated_at'))
            ->with('status', 'check', 'record')->orderByDesc('validated_at')->get();
        $worst = $latest->groupBy('record_resource_id')->map(fn ($rows) => $rows->contains(fn ($r) => $r->status->notation === 'failed') ? 'failed'
            : ($rows->contains(fn ($r) => $r->status->notation === 'warning') ? 'warning' : 'passed'));

        return ['records' => $worst->count(), 'passed' => $worst->filter(fn ($s) => $s === 'passed')->count(), 'warning' => $worst->filter(fn ($s) => $s === 'warning')->count(),
            'failed' => $worst->filter(fn ($s) => $s === 'failed')->count(), 'failures' => $latest->filter(fn ($r) => $r->status->notation === 'failed')->take(20)->values()];
    }

    /** The record's runs, newest first, each with its results. */
    public function history(RecordResource $unit, int $runs = 10): Collection
    {
        return IiifValidationResult::where('record_resource_id', $unit->id)->with('status', 'check', 'record')->orderByDesc('validated_at')->get()
            ->groupBy('run_id')->take($runs);
    }

    /** @return list<array{check: string, status: string, details: string}> */
    private function checks(RecordResource $unit): array
    {
        $files = DigitalFile::whereIn('instantiation_id', $unit->instantiations()->whereNull('deleted_at')->select('id'))->with('use')->get();
        $painted = $files->filter(fn ($f) => in_array($f->mime_type, self::PAINTABLE, true) && $f->use->notation !== 'thumbnail'
            || str_starts_with($f->mime_type, 'audio/') || str_starts_with($f->mime_type, 'video/'));
        $images = $painted->filter(fn ($f) => str_starts_with($f->mime_type, 'image/'));
        $timed = $painted->reject(fn ($f) => str_starts_with($f->mime_type, 'image/'));
        $store = app(FileStore::class);
        $missing = $painted->filter(fn ($f) => ! is_file($store->path($f->storage_path)));
        $noSize = $images->filter(fn ($f) => ! ($f->width && $f->height) && ! @getimagesize($store->path($f->storage_path)));
        $noDuration = $timed->filter(fn ($f) => ! collect((array) ($f->embedded_metadata ?? []))->keys()->contains(fn ($k) => str_ends_with((string) $k, 'Duration')));
        $rights = app(RightsService::class)->of($unit)->filter(fn ($r) => $r->inForce())
            ->contains(fn ($r) => collect([$r->licence?->iri, $r->statement?->iri])->filter()->contains(fn ($iri) => preg_match('~^https?://(creativecommons\.org/(licenses|publicdomain)/|rightsstatements\.org/vocab/)~', $iri)));
        $holder = $unit->repository ?? collect($unit->ancestors())->first()?->repository;
        $public = (new PublicCatalogue(Institution::findOrFail($unit->institution_id)))->find('records', $unit->id) !== null;
        $check = fn (string $check, string $status, string $details) => ['check' => $check, 'status' => $status, 'details' => $details];

        return [
            $unit->namesOfType('authorised')->isNotEmpty() ? $check('label-present', 'passed', 'The title is the manifest\'s label.')
                : $check('label-present', 'failed', 'No title: a manifest needs a label.'),
            $painted->isNotEmpty() ? $check('has-canvases', 'passed', $painted->count().' file(s) a canvas can paint.')
                : $check('has-canvases', 'failed', 'No image a browser shows, and no sound or video: there is nothing to paint.'),
            $missing->isEmpty() ? $check('files-present', 'passed', 'Every file is in the file store.')
                : $check('files-present', 'failed', 'Not in the file store: '.$missing->pluck('original_name')->join(', ').'.'),
            $noSize->isEmpty() ? $check('dimensions', 'passed', 'Every image has its width and height.')
                : $check('dimensions', 'failed', 'Width and height unknown: '.$noSize->pluck('original_name')->join(', ').'.'),
            $noDuration->isEmpty() ? $check('durations', 'passed', $timed->isEmpty() ? 'No sound or video.' : 'Every sound and video has its duration.')
                : $check('durations', 'warning', 'Left out of the manifest, as their length is not known: '.$noDuration->pluck('original_name')->join(', ').'.'),
            $files->contains(fn ($f) => $f->use->notation === 'thumbnail') ? $check('thumbnail-present', 'passed', 'A thumbnail exists.')
                : $check('thumbnail-present', 'warning', 'No thumbnail: viewers show none in their lists.'),
            $rights ? $check('rights-present', 'passed', 'A licence or rights statement in force gives the manifest\'s rights.')
                : $check('rights-present', 'warning', 'No Creative Commons licence or RightsStatements.org statement in force: the manifest has no rights.'),
            $holder ? $check('required-statement', 'passed', 'Held by '.$holder->described('authorised-name', ['en', ...Vocab::languages()]).'.')
                : $check('required-statement', 'warning', 'No repository holds it: the manifest names the institution instead.'),
            $public ? $check('published', 'passed', 'The public can read it, so its manifest is served.')
                : $check('published', 'warning', 'Not yet published: its manifest is served once it is.'),
        ];
    }
}
