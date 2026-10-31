<?php

use App\AccessRequests\AccessRequestService;
use App\Description\AgreementService;
use App\Description\WorkflowSlaService;
use App\Reports\ReportDelivery;
use App\Reports\UsageService;
use App\Rights\Embargoes;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Background jobs (roadmap phase 5): a worker each minute, while there is work, from the scheduler that
// deploy/cron/openric-workspace runs. Overlap is refused, so a long import holds one worker, not many.
// ponytail: one worker on one queue; run a supervised `queue:work` when imports queue behind each other.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=1')->everyMinute()->withoutOverlapping(60);

// Access requests (roadmap phase 7): an approval whose time is over is marked expired, each hour. Files stop
// opening the moment it ends whatever the hour (AccessRequestService::grants); this keeps the status true.
Artisan::command('workspace:access-requests:expire', function (AccessRequestService $requests) {
    $this->line($requests->expire().' access requests expired.');
})->purpose('Mark approved access requests whose time is over as expired');
Schedule::command('workspace:access-requests:expire')->hourly();

// Usage statistics (roadmap phase 8): each hour, the events of today and yesterday are counted again into the daily
// counts the statistics read, as PSIS's aggregateDaily, and events past the retention period are removed; the counts stay.
Artisan::command('workspace:usage:aggregate {--day= : count this day (YYYY-MM-DD) only}', function (UsageService $usage) {
    $days = $this->option('day') ? [CarbonImmutable::parse($this->option('day'))] : [CarbonImmutable::yesterday(), CarbonImmutable::today()];
    foreach ($days as $day) {
        $this->line($day->toDateString().': '.$usage->aggregate($day).' counts.');
    }
    $this->line($usage->prune((int) config('workspace.usage_retention_days')).' events past the retention period removed.');
})->purpose('Count the usage events of today and yesterday into daily counts, and remove old events');
Schedule::command('workspace:usage:aggregate')->hourly()->withoutOverlapping();

// Scheduled reports (roadmap phase 8): every five minutes, each report whose schedule is due is made, kept in its
// archive and sent to the schedule's accounts through the Outbox, as PSIS's ReportScheduler::runDueReports.
Artisan::command('workspace:reports:run-scheduled', function (ReportDelivery $delivery) {
    $result = $delivery->runDue();
    $this->line("{$result['made']} scheduled reports made, {$result['failed']} failed.");
})->purpose('Make and send the reports whose schedule is due');
Schedule::command('workspace:reports:run-scheduled')->everyFiveMinutes()->withoutOverlapping();

// Donor agreement reminders (roadmap phase 9b): each morning, the reminders whose warning period has begun are sent to
// their accounts, as Heratio's ahg:donor-reminders; a reminder no account could be sent stays due for the next run.
Artisan::command('workspace:agreements:remind {--day= : send as on this day (YYYY-MM-DD)}', function (AgreementService $agreements) {
    $result = $agreements->sendDue($this->option('day') ? CarbonImmutable::parse($this->option('day')) : null);
    $this->line("{$result['sent']} reminders sent, {$result['unsent']} not sent.");
})->purpose('Send the donor agreement reminders that are due');
Schedule::command('workspace:agreements:remind')->dailyAt('07:00')->withoutOverlapping();

// Service levels (roadmap phase 10b): each morning, PSIS's processSlaBreach. A task at risk or overdue is noted and its
// account told once a day; a breached one is escalated once, by its policy.
Artisan::command('workspace:workflow:sla', function (WorkflowSlaService $sla) {
    $result = $sla->process();
    $this->line("{$result['at_risk']} at risk, {$result['overdue']} overdue, {$result['escalated']} escalated.");
})->purpose('Warn of workflow tasks at risk or overdue, and escalate those past their service level');
Schedule::command('workspace:workflow:sla')->dailyAt('06:30')->timezone(config('workspace.timezone'))->withoutOverlapping();

// Embargoes (roadmap phase 13a, ADR 0015): warn before one ends, and give notice once one has ended.
Artisan::command('workspace:embargoes', function (Embargoes $embargoes) {
    $counts = $embargoes->process();
    $this->info("Warned of {$counts['warned']} embargoes ending; told of {$counts['ended']} ended.");
})->purpose('Warn before an embargo ends, and give notice once one has ended');
Schedule::command('workspace:embargoes')->dailyAt('06:40')->timezone(config('workspace.timezone'))->withoutOverlapping();
