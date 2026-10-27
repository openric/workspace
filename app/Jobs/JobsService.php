<?php

// OpenRiC Workspace - the record of background jobs (roadmap phase 5).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Ported from Heratio's JobsService (ahg-jobs, heratio-dev 4131171e4): create, find,
// browse, start, complete, fail, cancel, delete, statistics and clearing old jobs. Its
// queries on AtoM's job, object and ahg_job_execution tables are rewritten on the
// Workspace's background_jobs table; the status ids become job-status concepts and the
// types job-type concepts. Jobs belong to an institution.

namespace App\Jobs;

use App\Models\BackgroundJob;
use App\Models\Concept;
use App\Models\User;
use App\Support\Vocab;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class JobsService
{
    public const TYPES = Vocab::BASE.'job-type/';

    public const STATUSES = Vocab::BASE.'job-status/';

    /** Statuses a job does not leave. */
    public const FINISHED = ['completed', 'failed', 'cancelled'];

    /** A pending job of the type, asked for by the account, working on the subject. */
    public function create(string $institutionId, string $type, string $name, ?User $user = null, ?Model $subject = null): BackgroundJob
    {
        return BackgroundJob::create([
            'institution_id' => $institutionId, 'user_id' => $user?->id, 'type_id' => $this->concept(self::TYPES, $type)->id,
            'status_id' => $this->concept(self::STATUSES, 'pending')->id, 'name' => $name,
            'subject_table' => $subject?->getTable(), 'subject_id' => $subject?->getKey(),
        ]);
    }

    public function find(string $institutionId, string $id): ?BackgroundJob
    {
        return BackgroundJob::where('institution_id', $institutionId)->with('type.labels', 'status.labels', 'user')->find($id);
    }

    /** The latest job working on the subject. */
    public function latestFor(Model $subject): ?BackgroundJob
    {
        return BackgroundJob::where(['subject_table' => $subject->getTable(), 'subject_id' => $subject->getKey()])
            ->with('status')->latest()->latest('id')->first();
    }

    /**
     * @param  array{status?: ?string, type?: ?string, user?: ?string, sort?: ?string}  $filters
     */
    public function browse(string $institutionId, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return BackgroundJob::where('institution_id', $institutionId)->with('type.labels', 'status.labels', 'user')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status_id', $this->concept(self::STATUSES, $s)->id))
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('type_id', $this->concept(self::TYPES, $t)->id))
            ->when($filters['user'] ?? null, fn ($q, $u) => $q->where('user_id', $u))
            ->when(($filters['sort'] ?? 'date') === 'name', fn ($q) => $q->orderBy('name'), fn ($q) => $q->latest()->latest('id'))
            ->paginate($perPage)->withQueryString();
    }

    /** Running, unless it was cancelled before a worker reached it; false then. */
    public function start(BackgroundJob $job): bool
    {
        $job->refresh();
        if ($job->status->notation !== 'pending') {
            return false;
        }
        $job->update(['status_id' => $this->concept(self::STATUSES, 'running')->id, 'started_at' => now()]);
        $job->unsetRelation('status');

        return true;
    }

    public function complete(BackgroundJob $job, array $output = []): void
    {
        $this->finish($job, 'completed', ['output' => $output]);
    }

    public function fail(BackgroundJob $job, string $errorMessage, ?string $stackTrace = null): void
    {
        $this->finish($job, 'failed', ['error_message' => mb_substr($errorMessage, 0, 2000), 'stack_trace' => $stackTrace === null ? null : mb_substr($stackTrace, 0, 20000)]);
    }

    /** Only a pending job is cancelled; one that is running finishes. */
    public function cancel(BackgroundJob $job): bool
    {
        if ($job->status->notation !== 'pending') {
            return false;
        }
        $this->finish($job, 'cancelled');

        return true;
    }

    /** Only a finished job is deleted. */
    public function delete(BackgroundJob $job): bool
    {
        if (! in_array($job->status->notation, self::FINISHED, true)) {
            return false;
        }

        return (bool) $job->delete();
    }

    /** @return array<string, int> total and by status */
    public function stats(string $institutionId): array
    {
        $counts = BackgroundJob::where('background_jobs.institution_id', $institutionId)->join('concepts', 'concepts.id', '=', 'background_jobs.status_id')
            ->groupBy('concepts.notation')->selectRaw('concepts.notation, count(*) AS n')->pluck('n', 'notation');
        $stats = ['total' => (int) $counts->sum()];
        foreach (['pending', 'running', ...self::FINISHED] as $status) {
            $stats[$status] = (int) ($counts[$status] ?? 0);
        }

        return $stats;
    }

    /** Delete finished jobs older than the days given. @return int how many */
    public function clearInactive(string $institutionId, int $daysOld = 30): int
    {
        return BackgroundJob::where('institution_id', $institutionId)
            ->whereIn('status_id', Concept::choices(self::STATUSES)->whereIn('notation', self::FINISHED)->pluck('id'))
            ->where('completed_at', '<', CarbonImmutable::now()->subDays($daysOld))->delete();
    }

    private function finish(BackgroundJob $job, string $status, array $fields = []): void
    {
        $job->update(['status_id' => $this->concept(self::STATUSES, $status)->id, 'completed_at' => now(),
            'duration' => $job->started_at ? (int) $job->started_at->diffInSeconds(now()) : null, ...$fields]);
        $job->unsetRelation('status');
    }

    private function concept(string $vocabulary, string $notation): Concept
    {
        return Concept::choices($vocabulary)->where('notation', $notation)->first()
            ?? throw new InvalidArgumentException(__(':value is not in the vocabulary :vocabulary', ['value' => $notation, 'vocabulary' => $vocabulary]));
    }
}
