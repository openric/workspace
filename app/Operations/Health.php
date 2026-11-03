<?php

// OpenRiC Workspace - how the installation is doing (roadmap phase 12a, ADR 0021), after Heratio's ahg-observability:
// the same figures as a page for the operator and as Prometheus text for a monitor. Changed: no metrics library or
// shared store; each figure is read from the database, the queue and the disk when asked for, so nothing has to run
// in between, and HTTP latency histograms, which need a store shared by every PHP worker, are left to nginx.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Operations;

use App\Files\FileStore;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Health
{
    /** The scheduler is thought stopped when its heartbeat is older than this. */
    public const HEARTBEAT_STALE_SECONDS = 300;

    public function __construct(private FileStore $store) {}

    /**
     * Who may see the installation as a whole (the queue, failed jobs, disks): the accounts WORKSPACE_OPERATORS names,
     * or, where it names none and the installation holds one institution, whoever may manage that institution.
     */
    public static function operator(User $user): bool
    {
        $named = array_filter(array_map(fn ($e) => mb_strtolower(trim($e)), explode(',', (string) config('workspace.operators'))));
        if ($named !== []) {
            return in_array(mb_strtolower($user->email), $named, true);
        }

        return DB::table('institutions')->count() === 1 && $user->may('manage-institution', 'institution');
    }

    /** The figures, by name: [value, help]. */
    public function figures(): array
    {
        $root = $this->store->root();
        $heartbeat = Cache::get('workspace:scheduler:heartbeat');
        $oldest = DB::table('jobs')->min('available_at');

        return [
            'up' => [1, 'The Workspace answers.'],
            'database_up' => [rescue(fn () => (int) (DB::select('SELECT 1 AS ok')[0]->ok === 1), 0, false), 'The database answers.'],
            'queue_pending_jobs' => [DB::table('jobs')->count(), 'Background jobs waiting.'],
            'queue_oldest_pending_seconds' => [$oldest ? max(0, now()->getTimestamp() - (int) $oldest) : 0, 'How long the oldest waiting job has waited.'],
            'queue_failed_jobs' => [DB::table('failed_jobs')->count(), 'Background jobs that failed and wait to be tried again or forgotten.'],
            'scheduler_heartbeat_age_seconds' => [$heartbeat ? now()->getTimestamp() - (int) $heartbeat : -1, 'Seconds since the scheduler last ran; -1 if it never has.'],
            'object_store_writable' => [(int) (is_dir($root) && is_writable($root)), 'The object store can be written to.'],
            'object_store_free_bytes' => [(int) (@disk_free_space($root) ?: 0), 'Free space where files are kept.'],
            'object_store_total_bytes' => [(int) (@disk_total_space($root) ?: 0), 'Size of the disk where files are kept.'],
            'files_total' => [DB::table('digital_files')->count(), 'Files kept.'],
            'files_fixity_unchecked_30_days' => [DB::table('digital_files')->whereNull('fixity_failed_at')->where(fn ($q) => $q->whereNull('fixity_checked_at')->orWhere('fixity_checked_at', '<', now()->subDays(30)))->count(), 'Files not checked for fixity in the last 30 days.'],
            'fixity_open_failures' => [DB::table('fixity_failures')->whereIn('status_id', DB::table('concepts')->whereIn('iri', array_map(fn ($n) => Vocab::BASE.'fixity-failure-status/'.$n, ['open', 'acknowledged', 'investigating']))->select('id'))->count(), 'Files that failed fixity and are not yet resolved.'],
            'fixity_last_run_timestamp_seconds' => [(int) (DB::table('fixity_runs')->max('started_at') ? strtotime((string) DB::table('fixity_runs')->max('started_at')) : 0), 'When a fixity run last began.'],
            'webhooks_stopped' => [DB::table('webhooks')->where('failure_count', '>=', 5)->count(), 'Webhooks left alone after five failures.'],
            'webhook_deliveries_retrying' => [DB::table('webhook_deliveries')->whereNotNull('next_retry_at')->count(), 'Webhook deliveries waiting to be tried again.'],
            'audit_rows' => [DB::table('audit_log')->count(), 'Rows in the audit log.'],
            'audit_signing_key_present' => [(int) is_readable(storage_path('keys/audit-signing.sk')), 'The audit signing key can be read; without it every change is refused.'],
            'institutions' => [DB::table('institutions')->count(), 'Institutions on this installation.'],
        ];
    }

    /** What needs attention, by figure: a sentence for each that is wrong. */
    public function problems(array $figures): array
    {
        $v = fn ($k) => $figures[$k][0];

        return array_filter([
            'database_up' => $v('database_up') ? null : __('The database does not answer.'),
            'scheduler_heartbeat_age_seconds' => $v('scheduler_heartbeat_age_seconds') >= 0 && $v('scheduler_heartbeat_age_seconds') <= self::HEARTBEAT_STALE_SECONDS ? null
                : __('The scheduler has not run in the last five minutes: background jobs, fixity and webhooks wait. Install deploy/cron/openric-workspace.'),
            'queue_oldest_pending_seconds' => $v('queue_oldest_pending_seconds') < 600 ? null : __('A background job has waited more than ten minutes.'),
            'queue_failed_jobs' => $v('queue_failed_jobs') === 0 ? null : __('Background jobs have failed.'),
            'object_store_writable' => $v('object_store_writable') ? null : __('The object store cannot be written to: uploads fail.'),
            'object_store_free_bytes' => $v('object_store_total_bytes') === 0 || $v('object_store_free_bytes') / $v('object_store_total_bytes') >= 0.1 ? null : __('Less than a tenth of the disk where files are kept is free.'),
            'fixity_open_failures' => $v('fixity_open_failures') === 0 ? null : __('Files have failed fixity: restore them from a backup.'),
            'webhooks_stopped' => $v('webhooks_stopped') === 0 ? null : __('A webhook has stopped after five failures.'),
            'audit_signing_key_present' => $v('audit_signing_key_present') ? null : __('The audit signing key cannot be read: every change is refused.'),
        ]);
    }

    /** The figures as Prometheus text (exposition format 0.0.4), each a gauge named openric_workspace_<figure>. */
    public function prometheus(): string
    {
        $out = '# HELP openric_workspace_info The release running.'."\n".'# TYPE openric_workspace_info gauge'."\n"
            .'openric_workspace_info{version="'.addcslashes(trim((string) @file_get_contents(base_path('VERSION'))) ?: 'dev', '"\\').'"} 1'."\n";
        foreach ($this->figures() as $name => [$value, $help]) {
            $out .= "# HELP openric_workspace_$name $help\n# TYPE openric_workspace_$name gauge\nopenric_workspace_$name $value\n";
        }

        return $out;
    }
}
