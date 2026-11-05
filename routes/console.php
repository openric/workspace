<?php

use App\AccessRequests\AccessRequestService;
use App\Authority\Candidates;
use App\Authority\Mentions;
use App\Authority\NerFeedback;
use App\Authority\ParkQueue;
use App\Authority\Scorer;
use App\Dedupe\Duplicates;
use App\Description\AgreementService;
use App\Description\WorkflowSlaService;
use App\Dois\DataciteEvents;
use App\Dois\FundingImport;
use App\Dois\RecordDois;
use App\Federation\Discovery;
use App\Federation\FederatedSearch;
use App\Federation\Harvest;
use App\Federation\TripleStore;
use App\Federation\UnionCatalogue;
use App\Federation\VocabularySync;
use App\Files\FileStore;
use App\Files\MediaDuration;
use App\Fixity\Fixity;
use App\Help\HelpLibrary;
use App\Ingest\WatchFolders;
use App\Jurisdiction\ExportPermits;
use App\Jurisdiction\Naz;
use App\Models\AuthorityMention;
use App\Models\DigitalFile;
use App\Models\Institution;
use App\Models\Proposal;
use App\Preservation\Archivematica\Transfers;
use App\Preservation\Conversions;
use App\Preservation\FormatIdentification;
use App\Preservation\MigrationPlans;
use App\Preservation\Replication;
use App\Privacy\Registers;
use App\Reports\ReportDelivery;
use App\Reports\UsageService;
use App\Research\FieldAlerts;
use App\Research\Impact;
use App\Research\Notifications;
use App\Research\Researchers;
use App\Research\SavedSearches;
use App\Research\ShareLinks;
use App\Rights\Clearances;
use App\Rights\Embargoes;
use App\Support\Vocab;
use App\Versions\Versions;
use App\Webhooks\Webhooks;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

// Share links (roadmap phase 19a, ADR 0017; Heratio's share-link prune): note the links that have expired, and delete
// links ended a year ago and log rows older than 180 days.
Artisan::command('workspace:share-links:prune', function (ShareLinks $links) {
    $done = $links->prune();
    $this->info("{$done['expired']} expired, {$done['links_deleted']} links and {$done['events_deleted']} log rows deleted.");
})->purpose('Note expired share links and delete old ones');
Artisan::command('workspace:researchers', function (Researchers $researchers) {
    $done = $researchers->process();
    $this->info("Warned {$done['warned']} researchers of their registration ending; {$done['expired']} expired.");
})->purpose('Warn researchers before their registration ends, and end it when it has');
Schedule::command('workspace:researchers')->dailyAt('06:50')->timezone(config('workspace.timezone'))->withoutOverlapping();
Artisan::command('workspace:saved-search-alerts', function (SavedSearches $searches) {
    $this->info($searches->alert().' owners told of new results.');
})->purpose('Run the saved searches whose alert has come, and tell their owners of new results');
Schedule::command('workspace:saved-search-alerts')->dailyAt('05:40')->timezone(config('workspace.timezone'))->withoutOverlapping();
Artisan::command('workspace:field-alerts', function (FieldAlerts $alerts) {
    $this->info($alerts->scanAll().' field alerts raised.');
})->purpose('Ask Crossref and OpenAlex about the works research projects cite: retractions, updates and new citing works');
Schedule::command('workspace:field-alerts')->dailyAt('04:10')->timezone(config('workspace.timezone'))->withoutOverlapping();
Artisan::command('workspace:research-impact', function (Impact $impact) {
    $this->info($impact->scanAll().' signs of impact found.');
})->purpose('Ask OpenAlex and Crossref Event Data who cites, mentions or reuses the published outputs of research projects');
Schedule::command('workspace:research-impact')->dailyAt('04:30')->timezone(config('workspace.timezone'))->withoutOverlapping();
Artisan::command('workspace:read-durations', function (MediaDuration $durations, FileStore $store) {
    [$read, $unread] = [0, 0];
    DigitalFile::whereNull('duration_seconds')->where(fn ($q) => $q->where('mime_type', 'like', 'audio/%')->orWhere('mime_type', 'like', 'video/%'))
        ->chunkById(100, function ($files) use ($durations, $store, &$read, &$unread) {
            foreach ($files as $file) {
                $seconds = $durations->read($store->path($file->storage_path));
                $seconds ? $file->update(['duration_seconds' => $seconds]) : null;
                $seconds ? $read++ : $unread++;
            }
        });
    $this->info("$read sound and video files given their length; $unread could not be read and stay out of IIIF manifests.");
})->purpose('Read the length of the sound and video files held before lengths were read at upload, for their IIIF canvases');
Artisan::command('workspace:declassify', function (Clearances $clearances) {
    $this->info($clearances->declassifyDue().' records declassified.');
})->purpose('Declassify the records whose declassification date has come, and record the clearances that have ended');
Schedule::command('workspace:declassify')->dailyAt('00:20')->timezone(config('workspace.timezone'))->withoutOverlapping();
Artisan::command('workspace:jurisdiction-daily', function (ExportPermits $permits, Naz $naz) {
    $this->info($permits->expireOverdue().' export permits expired, '.$naz->advancePermits().' National Archives of Zimbabwe research permits moved on, '.Registers::expireLicences().' data controller licences expired.');
})->purpose('Expire export permits and data controller licences whose period has ended, and start or end research permits of the National Archives of Zimbabwe');
Schedule::command('workspace:jurisdiction-daily')->dailyAt('00:25')->timezone(config('workspace.timezone'))->withoutOverlapping();
Artisan::command('workspace:watch-folders', function (WatchFolders $folders) {
    $this->info($folders->runAll().' files taken in or refused.');
})->purpose('Take in what was dropped in each enabled watched folder: tables and descriptions imported, bags checked, other files made records');
Schedule::command('workspace:watch-folders')->everyMinute()->withoutOverlapping();
Artisan::command('workspace:versions:capture', function (Versions $versions) {
    $count = 0;
    foreach (Versions::KINDS as $table => $class) {
        $class::whereNull('deleted_at')->whereNotIn('id', DB::table('entity_versions')->where('subject_table', $table)->select('subject_id'))
            ->chunkById(200, function ($entities) use ($versions, &$count) {
                foreach ($entities as $entity) {
                    $count += $versions->write($entity, null, __('Captured as it was before versions were kept')) ? 1 : 0;
                }
            });
    }
    $this->info("$count first versions captured.");
})->purpose('Capture a first version of every unit of description and authority record that has none (Heratio\'s version-control backfill)');
Artisan::command('workspace:duplicates:scan', function (Duplicates $duplicates) {
    $found = Institution::all()->sum(fn ($i) => $duplicates->scan($i->id));
    $this->info("$found new pairs of possible duplicates.");
})->purpose('Find archival descriptions that may be duplicates, by each institution\'s rules (Heratio\'s dedupe scan)');
Schedule::command('workspace:duplicates:scan')->weeklyOn(0, '03:40')->timezone(config('workspace.timezone'))->withoutOverlapping();
Artisan::command('workspace:webhooks:retry', function (Webhooks $webhooks) {
    $this->info($webhooks->retryDue().' webhook deliveries tried again.');
})->purpose('Send again the webhook deliveries that failed and whose next try is due (Heratio\'s webhook retry)');
Schedule::command('workspace:webhooks:retry')->everyMinute()->withoutOverlapping();

Artisan::command('workspace:formats:identify', function (FormatIdentification $identification) {
    $done = 0;
    DigitalFile::whereNull('format_identified_at')->orderBy('id')->each(function (DigitalFile $file) use ($identification, &$done) {
        $identification->identify($file) && $done++;
    });
    $this->info("$done files identified.");
})->purpose('Identify against PRONOM the formats of the files not yet identified (phase 15a)');

Artisan::command('workspace:fixity:scheduled', function (Fixity $fixity) {
    $this->info($fixity->runDue().' fixity schedules run.');
})->purpose('Run the fixity schedules that are due (Heratio\'s integrity scheduler)');

// Fixity schedules that are due (roadmap phase 12a), and the heartbeat that shows the scheduler is running.
Schedule::command('workspace:fixity:scheduled')->everyFifteenMinutes()->withoutOverlapping(180);

// Preservation planning (roadmap phase 15c): replication targets copied on their schedule, approved migration plans
// started at their scheduled start, and the masters kept before a rule existed normalised on request.
Artisan::command('workspace:replication:scheduled', function (Replication $replication) {
    $this->info($replication->runDue().' replication targets copied.');
})->purpose('Copy the object store to each replication target whose time has come (Heratio\'s replication targets)');
Schedule::command('workspace:replication:scheduled')->everyFifteenMinutes()->withoutOverlapping(1440);
Artisan::command('workspace:migration-plans:scheduled', function (MigrationPlans $plans) {
    $this->info($plans->runDue().' migration plans started.');
})->purpose('Start the approved migration plans whose scheduled start has come');
Schedule::command('workspace:migration-plans:scheduled')->everyFifteenMinutes()->withoutOverlapping(60);
// Federation (roadmap phase 16a): cached peer answers past their fifteen minutes removed each hour.
Artisan::command('workspace:federation:cache-clean', function (FederatedSearch $federation) {
    $this->info($federation->clearExpired().' cached peer answers removed.');
})->purpose('Remove the cached federation answers that have expired (Heratio\'s SearchCacheCleanCommand)');
Schedule::command('workspace:federation:cache-clean')->hourly();
// Harvest and the union catalogue (roadmap phase 16b): OAI-PMH peers harvested on their interval, each hour; each sharing
// institution's records put into its union and its members' fetched, each night.
Artisan::command('workspace:federation:harvest', function (Harvest $harvest) {
    $this->info($harvest->runDue().' peers harvested.');
})->purpose('Harvest the OAI-PMH peers whose interval has passed (Heratio\'s HarvestCommand)');
Schedule::command('workspace:federation:harvest')->hourly()->withoutOverlapping(720);
Artisan::command('workspace:federation:union', function (UnionCatalogue $union) {
    foreach (Institution::all() as $institution) {
        $this->line($institution->name.': '.$union->publish($institution).' records in the union');
        foreach (DB::table('federation_members')->where(['institution_id' => $institution->id, 'is_enabled' => true, 'is_self' => false])->get() as $member) {
            $this->line('  '.$member->name.': '.rescue(fn () => $union->fetch($member).' fetched', fn ($e) => $e->getMessage(), false));
        }
    }
})->purpose('Put each institution\'s records into its union catalogue and fetch its members\' (Heratio\'s UnionPublishCommand)');
Schedule::command('workspace:federation:union')->dailyAt('02:30')->withoutOverlapping(240);
// Governance and vocabulary sync (roadmap phase 16c): peers' protocol descriptors probed and vocabularies synced, each night.
Artisan::command('workspace:federation:discover', function (Discovery $discovery) {
    foreach ($discovery->probeAll() as $peer => $status) {
        $this->line("$peer: $status");
    }
})->purpose('Probe every Workspace and Heratio peer\'s protocol descriptor (Heratio\'s PeerDiscoverCommand)');
Schedule::command('workspace:federation:discover')->dailyAt('03:15');
Artisan::command('workspace:doi:process {--limit=50}', function (RecordDois $dois) {
    $result = $dois->process((int) $this->option('limit'));
    $this->info("{$result['done']} done, {$result['failed']} failed.");
})->purpose('Send the DOI requests whose time has come to DataCite (Heratio\'s doi queue)');
Schedule::command('workspace:doi:process')->everyFiveMinutes()->withoutOverlapping(30)->when(fn () => DB::table('doi_queue')->exists());
Artisan::command('workspace:doi:events', function (DataciteEvents $events) {
    $result = $events->flush();
    $this->info("{$result['sent']} sent, {$result['failed']} failed.");
})->purpose('Report the waiting usage and citation events to DataCite (Heratio\'s DoiEventsFlushCommand)');
Schedule::command('workspace:doi:events')->hourly()->withoutOverlapping(60)->when(fn () => DB::table('doi_settings')->where('events_enabled', true)->exists());
Artisan::command('workspace:doi:metrics-backfill {institution : the institution\'s id} {--since= : a date; 30 days ago unless given}', function (DataciteEvents $events) {
    $since = $this->option('since') ?: now()->subDays(30)->toDateString();
    $this->info($events->backfill((string) $this->argument('institution'), $since).' events kept to report.');
})->purpose('Keep the views and downloads already counted as events to report to DataCite (Heratio\'s MetricsBackfillCommand)');
Artisan::command('workspace:doi:funding-import {file : a CSV with record, funder_name, funder_identifier, funder_identifier_type, award_number, award_uri, award_title}', function (FundingImport $import) {
    $result = $import->run((string) $this->argument('file'));
    $this->info("{$result['added']} added, {$result['skipped']} skipped.");
    foreach ($result['problems'] as $problem) {
        $this->warn($problem);
    }
})->purpose('Take in funding references for units of description from a CSV (Heratio\'s DoiFundingImportCommand)');
Artisan::command('workspace:help:ingest', function (HelpLibrary $library) {
    $counts = $library->ingest();
    $this->info("{$counts['articles']} guides read in, {$counts['links']} links between them.");
})->purpose('Read the Help Center\'s guides in docs/help in again (Heratio\'s ahg:help-ingest-all); it is done by itself when they change');
Artisan::command('workspace:sparql:sync {--full : put everything published and drop what is not}', function (TripleStore $store) {
    if (! TripleStore::configured()) {
        $this->warn(__('No SPARQL triple store is set up (WORKSPACE_SPARQL_URL).'));

        return;
    }
    foreach (Institution::orderBy('name')->get() as $institution) {
        $counts = $store->sync($institution, (bool) $this->option('full'));
        $this->line("{$institution->name}: {$counts['put']} put, {$counts['dropped']} dropped");
    }
})->purpose('Bring the SPARQL triple store up to date with the published RiC-O (phase 16e)');
// Only where a triple store is set up; the full pass each night drops what is no longer published.
Schedule::command('workspace:sparql:sync')->everyTenMinutes()->withoutOverlapping(60)->when(fn () => TripleStore::configured());
Schedule::command('workspace:sparql:sync --full')->dailyAt('03:40')->withoutOverlapping(240)->when(fn () => TripleStore::configured());
Artisan::command('workspace:federation:vocabularies', function (VocabularySync $sync) {
    $this->info($sync->runDue().' vocabularies synced.');
})->purpose('Take in the peers\' vocabularies whose sync interval has passed (Heratio\'s VocabSyncCommand)');
Schedule::command('workspace:federation:vocabularies')->hourly()->withoutOverlapping(120);
// Archivematica (roadmap phase 15d): transfers followed to their AIP every five minutes, DIPs fetched each hour, and a ping.
Artisan::command('workspace:archivematica:poll', function (Transfers $transfers) {
    $this->info($transfers->poll().' transfers looked at.');
})->purpose('Follow each transfer to Archivematica one step, to its AIP (Heratio\'s PollArchivematicaJobs)');
Schedule::command('workspace:archivematica:poll')->everyFiveMinutes()->withoutOverlapping(30);
Artisan::command('workspace:archivematica:fetch-dips', function (Transfers $transfers) {
    $links = $transfers->fetchDips();
    $this->info(count($links).' DIPs looked at: '.collect($links)->countBy(fn ($l) => $l->status->notation)->map(fn ($n, $s) => "$n $s")->join(', '));
})->purpose('Fetch the DIPs the Archivematica Storage Service holds and take each in (Heratio\'s IngestDipsCommand)');
Schedule::command('workspace:archivematica:fetch-dips')->hourly()->withoutOverlapping(120);
Artisan::command('workspace:archivematica:ping', function (Transfers $transfers) {
    foreach ($transfers->ping() as $service => $answer) {
        $this->line("$service: $answer");
    }
})->purpose('Ask the Archivematica Dashboard and Storage Service whether they answer (Heratio\'s PingCommand)');
Artisan::command('workspace:normalise {--institution= : only this institution\'s masters}', function (Conversions $conversions) {
    $done = $failed = 0;
    DigitalFile::whereHas('use', fn ($q) => $q->where('notation', 'master'))->whereHas('instantiation', fn ($q) => $q->whereNull('deleted_at'))
        ->when($this->option('institution'), fn ($q, $id) => $q->where('institution_id', $id))->orderBy('id')
        ->chunkById(100, function ($files) use ($conversions, &$done, &$failed) {
            foreach ($files as $file) {
                foreach (['preservation', 'access'] as $purpose) {
                    if ($conversion = $conversions->normalise($file, $purpose)) {
                        $conversion->status->notation === 'completed' ? $done++ : $failed++;
                    }
                }
            }
        });
    $this->info("$done made, $failed failed.");
})->purpose('Normalise by the rules every master that has no preservation master or access copy yet (Heratio\'s NormalizeExistingCommand)');
Schedule::call(fn () => Cache::forever('workspace:scheduler:heartbeat', now()->getTimestamp()))->everyMinute()->name('scheduler-heartbeat');
Artisan::command('workspace:research-digests', function (Notifications $notifications) {
    $this->info($notifications->digests().' digests of research notices sent.');
})->purpose('Mail each researcher the notices held for their hourly, daily or weekly digest');
Schedule::command('workspace:research-digests')->hourly()->withoutOverlapping();
Schedule::command('workspace:share-links:prune')->dailyAt('03:20')->timezone(config('workspace.timezone'))->withoutOverlapping();

// Resolving names to authority records and places (roadmap phase 17b2), after Heratio's ahg-authority-resolution commands.
Artisan::command('workspace:authority:promote {--limit=500}', function () {
    $made = 0;
    Proposal::whereHas('status', fn ($q) => $q->where('iri', Vocab::ASSERTION_STATUSES.'proposed'))
        ->whereHas('kind', fn ($q) => $q->whereIn('notation', ['agent', 'place']))->whereNotIn('id', AuthorityMention::select('proposal_id'))
        ->orderBy('created_at')->limit((int) $this->option('limit'))->get()->each(function ($proposal) use (&$made) {
            app(Mentions::class)->promote($proposal);
            $made++;
        });
    $this->info("$made names brought into the queue.");
})->purpose('Make mentions to resolve of the proposed people, organisations and places not yet in the queue (Heratio\'s PromoteSampleCommand)');
Artisan::command('workspace:authority:rescore {--institution=}', function () {
    $count = 0;
    AuthorityMention::whereHas('state', fn ($q) => $q->where('notation', 'pending'))->when($this->option('institution'), fn ($q, $id) => $q->where('institution_id', $id))
        ->with('proposal.kind', 'proposal.agentType', 'record')->get()->each(function ($mention) use (&$count) {
            app(Candidates::class)->generate($mention);
            app(Scorer::class)->scoreAll($mention);
            $count++;
        });
    $this->info("$count mentions given their candidates and evidence afresh.");
})->purpose('Find and score the candidates of every waiting mention again (Heratio\'s GenerateCandidatesCommand, ScoreEvidenceCommand and ReprocessCommand)');
Artisan::command('workspace:authority:scan-parked', function () {
    $this->info(app(ParkQueue::class)->scan().' parked names now have a new candidate.');
})->purpose('Look again for the candidates of every parked name and flag those with a new one (Heratio\'s ScanParkedCommand)');
Schedule::command('workspace:authority:scan-parked')->dailyAt('02:40')->timezone(config('workspace.timezone'))->withoutOverlapping(120)
    ->when(fn () => DB::table('authority_parks')->exists());
Artisan::command('workspace:authority:export-feedback {--format=jsonl : jsonl or conll} {--institution=}', function () {
    $result = app(NerFeedback::class)->export((string) $this->option('format'), $this->option('institution') ?: null);
    $this->info("{$result['count']} rejected names written to {$result['path']}.");
})->purpose('Write the rejected names not yet exported, for training the named-entity model again (Heratio\'s ExportNerFeedbackCommand)');
Artisan::command('workspace:authority:cache-clear {--source= : one source, such as viaf} {--expired : only answers past their time}', function () {
    $q = DB::table('authority_lookup_cache')->when($this->option('source'), fn ($q, $s) => $q->whereIn('source_id', DB::table('concepts')->where('iri', Vocab::BASE.'lookup-source/'.$s)->select('id')))
        ->when($this->option('expired'), fn ($q) => $q->whereRaw("retrieved_at + (ttl || ' seconds')::interval < now()"));
    $this->info($q->delete().' cached answers removed.');
})->purpose('Remove the cached answers of outside authorities (Heratio\'s CacheClearCommand)');
Artisan::command('workspace:authority:status', function () {
    $states = DB::table('authority_mentions as m')->join('concepts as c', 'c.id', '=', 'm.state_id')->selectRaw('c.notation, count(*) AS n')->groupBy('c.notation')->pluck('n', 'notation');
    foreach ($states as $state => $n) {
        $this->line("$state: $n");
    }
    $this->line('parked with a new candidate: '.DB::table('authority_parks')->where('new_candidate_available', true)->count());
    $this->line('feedback not yet exported: '.DB::table('ner_feedback')->whereNull('exported_at')->count());
    $this->line('cached outside answers: '.DB::table('authority_lookup_cache')->count());
})->purpose('How many names wait, are linked, parked or rejected, and what is cached (Heratio\'s StatusCommand and CacheStatsCommand)');
