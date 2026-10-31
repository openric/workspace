<?php

// OpenRiC Workspace - built reports made on a schedule, archived, shared and versioned (roadmap phase 8), after PSIS's
// ahgReportBuilderPlugin (ReportScheduler: runDueReports, runSchedule, calculateNextRun, sendEmail; ShareService:
// createShare, getShare, deactivateShare, isExpired; VersionService: createVersion, restoreVersion) and Heratio's
// ReportBuilderController (scheduleStore, scheduleDelete, apiShareCreate, apiShareDeactivate, sharedView, apiVersions,
// apiVersionCreate, apiVersionRestore). A schedule sends to accounts of the institution through the Outbox, so the
// suppression gate and the email log apply, and never to typed addresses; a share link always expires.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Reports;

use App\Mail\Outbox;
use App\Mail\ScheduledReportMail;
use App\Models\Concept;
use App\Models\CustomReport;
use App\Models\ReportArchive;
use App\Models\ReportSchedule;
use App\Models\ReportShare;
use App\Models\ReportVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class ReportDelivery
{
    public const FREQUENCIES = 'https://workspace.openric.org/vocab/report-frequency/';

    /** The longest a share link may stay open, in days. */
    public const SHARE_DAYS_MAX = 365;

    public function __construct(private ReportBuilder $builder, private ReportExport $export, private Outbox $outbox) {}

    /** Make the report in a format and keep the file in its archive. */
    public function generate(CustomReport $report, string $format, ?ReportSchedule $schedule = null, ?User $by = null): ReportArchive
    {
        isset(ReportExport::FORMATS[$format]) || throw new InvalidArgumentException("$format is not an export format");
        $document = $this->builder->document($report);
        $id = (string) Str::uuid7();
        $path = "reports/$id.".ReportExport::FORMATS[$format][0];
        Storage::disk('local')->put($path, $this->export->render($document, $format));

        return ReportArchive::create(['id' => $id, 'report_id' => $report->id, 'schedule_id' => $schedule?->id, 'format' => $format, 'path' => $path,
            'size_bytes' => Storage::disk('local')->size($path), 'entities' => $document['entities'], 'generated_by_id' => $by?->id, 'generated_at' => now()]);
    }

    /** Remove a report with its archived files. */
    public function remove(CustomReport $report): void
    {
        $paths = $report->archives()->pluck('path')->all();
        $report->delete();
        Storage::disk('local')->delete($paths);
    }

    /**
     * A new schedule, or a changed one: how often, on which day and at what time (in the Workspace's time zone), in which
     * format, and to which accounts of the institution, each of which must be able to read the report's kind.
     */
    public function schedule(CustomReport $report, array $data, User $by, ?ReportSchedule $schedule = null): ReportSchedule
    {
        $frequency = Concept::choices(self::FREQUENCIES)->where('notation', $data['frequency'])->first() ?? throw new InvalidArgumentException(__('Choose how often.'));
        // In the order given: the database answers in no order of its own.
        $given = array_values(array_unique($data['recipients'] ?? []));
        $recipients = User::where('institution_id', $report->institution_id)->whereIn('id', $given)->get()
            ->filter(fn (User $u) => ! $u->isDisabled() && ReportBuilder::mayUse($u, $report->source))->sortBy(fn (User $u) => array_search($u->id, $given, true));
        if ($recipients->isEmpty()) {
            throw new InvalidArgumentException(__('Choose who receives it: accounts that may read :source.', ['source' => __(ReportBuilder::SOURCE_TITLES[$report->source])]));
        }
        $fields = ['frequency_id' => $frequency->id, 'format' => $data['format'], 'time_of_day' => $data['time_of_day'] ?? '08:00',
            'day_of_week' => $frequency->notation === 'weekly' ? (int) ($data['day_of_week'] ?? 1) : null,
            'day_of_month' => in_array($frequency->notation, ['monthly', 'quarterly'], true) ? (int) ($data['day_of_month'] ?? 1) : null,
            'recipients' => $recipients->pluck('id')->values()->all(), 'active' => (bool) ($data['active'] ?? true)];
        $fields['next_run_at'] = $this->nextRun($frequency->notation, $fields['day_of_week'], $fields['day_of_month'], $fields['time_of_day'], CarbonImmutable::now());

        return $schedule ? tap($schedule)->update($fields) : $report->schedules()->create($fields + ['created_by_id' => $by->id]);
    }

    /** The first run after a moment, as PSIS's calculateNextRun, in the Workspace's time zone; in UTC. */
    public function nextRun(string $frequency, ?int $dayOfWeek, ?int $dayOfMonth, string $time, CarbonImmutable $after): CarbonImmutable
    {
        $local = $after->setTimezone(config('workspace.timezone'));
        [$hour, $minute] = array_map('intval', explode(':', $time) + [1 => 0]);
        $at = fn (CarbonImmutable $day) => $day->setTime($hour, $minute);
        $onDay = fn (CarbonImmutable $month) => $month->setDay(min($dayOfMonth ?? 1, $month->daysInMonth));
        $next = match ($frequency) {
            'daily' => $at($local),
            'weekly' => $at($local->addDays((($dayOfWeek ?? 1) - $local->isoWeekday() + 7) % 7)),
            'monthly' => $at($onDay($local->startOfMonth())),
            'quarterly' => $at($onDay($local->startOfMonth())),
        };
        $step = ['daily' => fn ($d) => $d->addDay(), 'weekly' => fn ($d) => $d->addWeek(),
            'monthly' => fn ($d) => $at($onDay($d->startOfMonth()->addMonthNoOverflow())), 'quarterly' => fn ($d) => $at($onDay($d->startOfMonth()->addMonthsNoOverflow(3)))][$frequency];
        while ($next <= $local) {
            $next = $step($next);
        }

        return $next->utc();
    }

    /**
     * Make each report whose schedule is due, keep the file, send it to the schedule's accounts and set the next run.
     * One failing does not stop the rest. @return array{made: int, failed: int}
     */
    public function runDue(): array
    {
        $made = $failed = 0;
        foreach (ReportSchedule::where('active', true)->where('next_run_at', '<=', now())->with('report', 'frequency')->get() as $schedule) {
            try {
                $archive = $this->generate($schedule->report, $schedule->format, $schedule);
                foreach (User::whereIn('id', $schedule->recipients)->get() as $recipient) {
                    // Who may no longer read its kind is not sent it.
                    if (ReportBuilder::mayUse($recipient, $schedule->report->source)) {
                        $this->outbox->send($recipient, new ScheduledReportMail($recipient, $schedule->report, $archive));
                    }
                }
                $made++;
            } catch (Throwable $e) {
                report($e);
                $failed++;
            }
            $schedule->update(['last_run_at' => now(), 'next_run_at' => $this->nextRun($schedule->frequency->notation, $schedule->day_of_week, $schedule->day_of_month,
                substr((string) $schedule->time_of_day, 0, 5), CarbonImmutable::now())]);
        }

        return ['made' => $made, 'failed' => $failed];
    }

    /** A link that opens the report without signing in, for some days. @return array{0: ReportShare, 1: string} the share and its token */
    public function share(CustomReport $report, int $days, User $by): array
    {
        $token = Str::random(64);
        $share = $report->shares()->create(['token' => hash('sha256', $token), 'expires_at' => now()->addDays(max(1, min(self::SHARE_DAYS_MAX, $days))), 'created_by_id' => $by->id]);

        return [$share, $token];
    }

    /** The report a share link opens, if it is still open; counts the opening. */
    public function open(string $token): ?CustomReport
    {
        $share = ReportShare::where('token', hash('sha256', $token))->with('report')->first();
        if (! $share?->isOpen()) {
            return null;
        }
        $share->increment('opened');

        return $share->report;
    }

    /** Keep the report's definition and sections as a numbered version (PSIS's createVersion). */
    public function version(CustomReport $report, User $by, ?string $summary = null): ReportVersion
    {
        return DB::transaction(function () use ($report, $by, $summary) {
            // The report's row is locked, so two versions kept at once do not take the same number.
            CustomReport::whereKey($report->id)->lockForUpdate()->first();

            return $report->versions()->create(['number' => (int) $report->versions()->max('number') + 1, 'snapshot' => $this->snapshot($report),
                'summary' => $summary, 'created_by_id' => $by->id, 'created_at' => now()]);
        });
    }

    /** Put the report back as a version had it, keeping what it was as a new version first (PSIS's restoreVersion). */
    public function restore(ReportVersion $version, User $by): void
    {
        DB::transaction(function () use ($version, $by) {
            $report = CustomReport::lockForUpdate()->findOrFail($version->report_id);
            $this->version($report, $by, __('Before restoring version :number', ['number' => $version->number]));
            $snapshot = $version->snapshot;
            $report->update(array_intersect_key($snapshot, array_flip(['name', 'description', 'columns', 'filters', 'sort', 'shared'])));
            $report->sections()->get()->each->delete();
            foreach ($snapshot['sections'] as $i => $section) {
                $this->builder->addSection($report, $section['type'], $section + ['position' => $i]);
            }
        });
    }

    private function snapshot(CustomReport $report): array
    {
        return $report->only(['name', 'description', 'source', 'columns', 'filters', 'sort', 'shared']) + ['sections' => $report->sections()->with('type')->get()
            ->map(fn ($s) => ['type' => $s->type->notation, 'title' => $s->title, 'content' => $s->content, 'config' => $s->config ?: []])->all()];
    }
}
