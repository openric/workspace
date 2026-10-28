<?php

use App\AccessRequests\AccessRequestService;
use App\Reports\UsageService;
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
