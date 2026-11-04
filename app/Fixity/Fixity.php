<?php

// OpenRiC Workspace - fixity on a schedule (roadmap phase 12a, ADR 0021).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ahg-integrity FixityService: every check of a file kept in the ledger with what was expected and what
// was found, a file that fails kept open (Heratio's dead letter) until it passes again or someone closes it with a
// reason, runs within their limits of files and time and left partial for the next run, the longest unchecked files
// first, and whoever the schedule names told of what failed. Changed: a check says whether the file is missing or
// changed, not only that it failed; and the manual check on a file's page and the workspace:fixity command go through
// the same ledger, so each file has one history.

namespace App\Fixity;

use App\Audit\AuditLog;
use App\Files\FileStore;
use App\Mail\FixityMail;
use App\Mail\Outbox;
use App\Models\Concept;
use App\Models\DigitalFile;
use App\Models\FixityCheck;
use App\Models\FixityFailure;
use App\Models\FixityRun;
use App\Models\FixitySchedule;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use App\Preservation\PreservationEvents;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Fixity
{
    public function __construct(private FileStore $store) {}

    /** Check one file: the outcome's notation (pass, mismatch, missing), kept in the ledger and on the file. */
    public function check(DigitalFile $file, ?FixityRun $run = null, ?User $actor = null): string
    {
        $started = hrtime(true);
        $path = $this->store->path($file->storage_path);
        [$size, $sha] = is_file($path) ? [filesize($path), hash_file('sha256', $path)] : [null, null];
        $outcome = $size === null ? 'missing' : ($size === $file->size_bytes && $sha === $file->sha256 ? 'pass' : 'mismatch');
        $concept = $this->term('fixity-outcome', $outcome);
        DB::transaction(function () use ($file, $run, $actor, $outcome, $size, $sha, $concept, $started) {
            FixityCheck::create(['institution_id' => $file->institution_id, 'run_id' => $run?->id, 'digital_file_id' => $file->id, 'outcome_id' => $concept->id,
                'expected_sha256' => $file->sha256, 'computed_sha256' => $sha, 'expected_size' => $file->size_bytes, 'found_size' => $size,
                'duration_ms' => (int) ((hrtime(true) - $started) / 1e6), 'user_id' => $actor?->id, 'checked_at' => now()]);
            AuditLog::actingAs($actor, fn () => $file->update($outcome === 'pass' ? ['fixity_checked_at' => now(), 'fixity_failed_at' => null] : ['fixity_failed_at' => now()]));
            $open = FixityFailure::where('digital_file_id', $file->id)->whereHas('status', fn ($q) => $q->whereIn('notation', FixityFailure::OPEN))->first();
            if ($outcome === 'pass') {
                $open?->update(['status_id' => $this->term('fixity-failure-status', 'resolved')->id, 'resolved_at' => now(),
                    'resolution_notes' => trim($open->resolution_notes."\n".__('Passed again on :date.', ['date' => now()->toDateString()]))]);
            } elseif ($open) {
                $open->update(['outcome_id' => $concept->id, 'consecutive_failures' => $open->consecutive_failures + 1, 'last_failed_at' => now(), 'last_run_id' => $run?->id]);
            } else {
                FixityFailure::create(['institution_id' => $file->institution_id, 'digital_file_id' => $file->id, 'outcome_id' => $concept->id,
                    'status_id' => $this->term('fixity-failure-status', 'open')->id, 'first_failed_at' => now(), 'last_failed_at' => now(), 'last_run_id' => $run?->id]);
            }
        });
        // The check as a PREMIS fixity check (phase 15a).
        app(PreservationEvents::class)->record($file, 'fixity-check', $outcome === 'pass' ? 'success' : 'failure', 'SHA-256',
            $outcome === 'pass' ? $file->sha256 : __('Expected :expected, found :found.', ['expected' => $file->sha256, 'found' => $sha ?? __('no file')]), $actor);
        if ($run) {
            $run->increment('files_checked');
            $run->increment(['pass' => 'files_passed', 'mismatch' => 'files_changed', 'missing' => 'files_missing'][$outcome]);
            $run->increment('bytes_checked', (int) ($size ?? 0));
        }

        return $outcome;
    }

    /** The files of an institution a schedule covers, the longest unchecked first. */
    public function files(string $institutionId, ?FixitySchedule $schedule = null, int $staleDays = 0): Builder
    {
        $scope = $schedule?->scope->notation ?? 'all';

        return DigitalFile::where('institution_id', $institutionId)
            ->whereHas('instantiation', fn ($q) => $q->whereNull('deleted_at')->whereHas('record', function ($r) use ($scope, $schedule) {
                $r->whereNull('deleted_at');
                // A repository's records are those it holds and everything inside them, as a unit inherits its holder.
                $held = fn () => RecordResource::where('repository_id', $schedule->repository_id)->get()->flatMap(fn ($top) => [$top->id, ...$top->descendantIds()])->unique()->all();
                match ($scope) {
                    'repository' => $r->whereIn('id', $held()),
                    'record-set' => $r->whereIn('id', [$schedule->record_resource_id, ...$schedule->record->descendantIds()]),
                    default => null,
                };
            }))
            // Last checked is the later of the last pass and the last failure, so a failing file does not hold the head of the queue.
            ->when($staleDays > 0, fn ($q) => $q->whereRaw('GREATEST(fixity_checked_at, fixity_failed_at) IS NULL OR GREATEST(fixity_checked_at, fixity_failed_at) < ?', [now()->subDays($staleDays)]))
            ->orderByRaw('GREATEST(fixity_checked_at, fixity_failed_at) NULLS FIRST')->orderBy('id');
    }

    /** One run over what a schedule covers (or all the institution's files), within its limits. */
    public function run(Institution $institution, ?FixitySchedule $schedule, string $trigger, ?User $actor = null, ?int $staleDays = null, ?int $limit = null): FixityRun
    {
        $run = FixityRun::create(['institution_id' => $institution->id, 'schedule_id' => $schedule?->id, 'status_id' => $this->term('fixity-run-status', 'running')->id,
            'trigger_id' => $this->term('fixity-trigger', $trigger)->id, 'user_id' => $actor?->id, 'started_at' => now()]);
        $limit ??= $schedule?->batch_size;
        $deadline = now()->addMinutes($schedule?->max_runtime_minutes ?? 24 * 60);
        $status = 'completed';
        try {
            $query = $this->files($institution->id, $schedule, $staleDays ?? $schedule?->stale_days ?? 0);
            $total = (clone $query)->count();
            // A cursor keeps the order (longest unchecked first) and the limit; lazyById would page by id and drop both.
            foreach ($query->limit($limit ?? PHP_INT_MAX)->cursor() as $file) {
                if (now()->greaterThan($deadline)) {
                    break;
                }
                $this->check($file, $run, $actor);
            }
            $run->refresh();
            $status = $run->files_checked < $total ? 'partial' : 'completed';
        } catch (\Throwable $e) {
            [$status, $error] = ['failed', mb_substr($e->getMessage(), 0, 2000)];
        }
        $run->update(['status_id' => $this->term('fixity-run-status', $status)->id, 'completed_at' => now(), 'error_message' => $error ?? null]);
        if ($schedule) {
            $schedule->update(['last_run_at' => now(), 'next_run_at' => $this->next($schedule)]);
            if (($run->files_changed + $run->files_missing) > 0 && $schedule->notifyUser) {
                app(Outbox::class)->send($schedule->notifyUser, new FixityMail($schedule->notifyUser, $run->fresh()));
            }
        }

        return $run->fresh();
    }

    /** Run the schedules that are due (the scheduler calls this). @return int how many ran */
    public function runDue(): int
    {
        $due = FixitySchedule::where('is_enabled', true)->where(fn ($q) => $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', now()))->with('scope', 'frequency', 'record', 'notifyUser')->get();
        foreach ($due as $schedule) {
            $this->run(Institution::find($schedule->institution_id), $schedule, 'scheduler');
        }

        return $due->count();
    }

    public function saveSchedule(Institution $institution, array $data, User $actor, ?FixitySchedule $schedule = null): FixitySchedule
    {
        $name = trim((string) ($data['name'] ?? '')) ?: throw new InvalidArgumentException(__('Name the schedule.'));
        $scope = $this->term('fixity-scope', (string) ($data['scope'] ?? 'all'));
        $repository = $scope->notation === 'repository' ? ($data['repository_id'] ?? null) : null;
        $record = $scope->notation === 'record-set' ? ($data['record_resource_id'] ?? null) : null;
        $scope->notation !== 'repository' || DB::table('repositories')->where(['id' => $repository, 'institution_id' => $institution->id])->exists()
            || throw new InvalidArgumentException(__('Choose the repository.'));
        $scope->notation !== 'record-set' || RecordResource::where(['id' => $record, 'institution_id' => $institution->id])->whereNull('deleted_at')->exists()
            || throw new InvalidArgumentException(__('Choose the record.'));
        $whole = function (string $key, int $default, int $min, int $max) use ($data) {
            $v = trim((string) ($data[$key] ?? ''));
            $v === '' && $v = (string) $default;
            (ctype_digit($v) && (int) $v >= $min && (int) $v <= $max) || throw new InvalidArgumentException(__(':field is a whole number from :min to :max.', ['field' => $key, 'min' => $min, 'max' => $max]));

            return (int) $v;
        };
        $notify = ($data['notify_user_id'] ?? '') === '' ? null : (User::where(['id' => $data['notify_user_id'], 'institution_id' => $institution->id])->value('id')
            ?? throw new InvalidArgumentException(__('Choose an account of this institution to tell.')));
        $fields = ['name' => $name, 'scope_id' => $scope->id, 'repository_id' => $repository, 'record_resource_id' => $record,
            'frequency_id' => $this->term('fixity-frequency', (string) ($data['frequency'] ?? 'weekly'))->id,
            'stale_days' => $whole('stale_days', 0, 0, 3650), 'batch_size' => $whole('batch_size', 500, 1, 100000), 'max_runtime_minutes' => $whole('max_runtime_minutes', 60, 1, 1440),
            'is_enabled' => ! empty($data['is_enabled']), 'notify_user_id' => $notify];

        return AuditLog::actingAs($actor, fn () => $schedule ? tap($schedule)->update($fields)
            : FixitySchedule::create($fields + ['institution_id' => $institution->id, 'created_by_id' => $actor->id, 'next_run_at' => now()]));
    }

    /** Move a failure on: acknowledged, investigating, resolved or ignored, with a note; closing one needs the note. */
    public function updateFailure(FixityFailure $failure, string $status, ?string $notes, User $actor): void
    {
        $concept = $this->term('fixity-failure-status', $status);
        $notes = trim((string) $notes);
        in_array($status, ['resolved', 'ignored'], true) && $notes === '' && throw new InvalidArgumentException(__('Say why it is closed.'));
        AuditLog::actingAs($actor, fn () => $failure->update(['status_id' => $concept->id, 'resolution_notes' => $notes === '' ? $failure->resolution_notes : $notes]
            + ($status === 'acknowledged' ? ['acknowledged_by_id' => $actor->id, 'acknowledged_at' => now()] : [])
            + (in_array($status, ['resolved', 'ignored'], true) ? ['resolved_at' => now()] : [])));
    }

    private function next(FixitySchedule $schedule)
    {
        return match ($schedule->frequency->notation) {
            'daily' => now()->addDay(),
            'monthly' => now()->addMonth(),
            default => now()->addWeek(),
        };
    }

    private function term(string $vocabulary, string $notation): Concept
    {
        return Concept::choices(Vocab::BASE.$vocabulary.'/')->where('notation', $notation)->first()
            ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
