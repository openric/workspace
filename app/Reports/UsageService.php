<?php

// OpenRiC Workspace - usage statistics of the public catalogue (roadmap phase 8), after PSIS's ahgStatisticsPlugin
// (StatisticsService: logEvent, detectBot, aggregateDaily, cleanupOldEvents, getDashboardStats, getViewsOverTime,
// getTopItems, getItemStats, getRepositoryStats, exportToCsv), which Heratio's ahg-statistics reads. Nothing that
// identifies a visitor is kept: no address, user agent, referrer or session, and no GeoIP place, so there is no
// count by country. A visitor is a hash under a salt that lives one day in the cache and is then gone.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Reports;

use App\Models\Concept;
use App\Models\DigitalFile;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use Carbon\CarbonImmutable;
use EasyRdf\Graph;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class UsageService
{
    public const EVENTS = Vocab::BASE.'usage-event/';

    public const CRAWLERS = Vocab::BASE.'web-crawler/';

    /** @var array<string, string>|null piece of user agent (lower case) => crawler concept id */
    private ?array $crawlers = null;

    /**
     * Count a view of an entity, or a download of one of its files, by a visitor to the public catalogue.
     * Counting never stops the page: a failure is reported and the page is served.
     */
    public function record(Request $request, Institution $institution, string $event, Model $subject, ?DigitalFile $file = null): void
    {
        try {
            $agent = (string) $request->userAgent();
            $salt = Cache::remember('usage-salt-'.now()->toDateString(), now()->addDays(2), fn () => Str::random(64));
            DB::table('usage_events')->insert([
                'id' => (string) Str::uuid7(), 'institution_id' => $institution->id,
                'event_id' => Concept::where('iri', self::EVENTS.$event)->value('id'),
                'subject_table' => $subject->getTable(), 'subject_id' => $subject->getKey(), 'file_id' => $file?->id,
                'repository_id' => match (true) {
                    $subject instanceof RecordResource => $subject->repository_id, $subject instanceof Repository => $subject->id, default => null
                },
                'crawler_id' => $this->crawler($agent), 'signed_in' => $request->user() !== null,
                'visitor' => hash_hmac('sha256', $request->ip().'|'.$agent, $salt), 'occurred_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The web crawler a user agent names, or null for a person. The pieces of user agent are the hidden labels of the
     * bundled web-crawler vocabulary, read from its file: the database keeps other labels only for an institution's own concepts.
     */
    public function crawler(string $agent): ?string
    {
        $file = resource_path('vocabularies/web-crawler.ttl');
        $this->crawlers ??= (function () use ($file) {
            $pieces = Cache::rememberForever('usage-crawlers-'.filemtime($file), function () use ($file) {
                $graph = new Graph;
                $graph->parse(file_get_contents($file), 'turtle');
                $pieces = [];
                foreach ($graph->allOfType('skos:Concept') as $concept) {
                    foreach ($concept->allLiterals('skos:hiddenLabel') as $label) {
                        $pieces[mb_strtolower((string) $label->getValue())] = $concept->getUri();
                    }
                }

                return $pieces;
            });
            $ids = Concept::whereIn('iri', array_unique($pieces))->whereNull('deprecated_at')->pluck('id', 'iri');

            return array_filter(array_map(fn ($iri) => $ids[$iri] ?? null, $pieces));
        })();
        $agent = mb_strtolower($agent);
        foreach ($this->crawlers as $piece => $id) {
            if (str_contains($agent, $piece)) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Count the events of a day (UTC) into usage_daily, replacing what it had for that day, as PSIS's aggregateDaily:
     * a row for each event and subject, and one for the whole catalogue whose visitors are each counted once.
     */
    public function aggregate(CarbonImmutable $day): int
    {
        $from = $day->startOfDay();
        $range = [$from, $from->addDay()];

        return DB::transaction(function () use ($day, $range) {
            DB::table('usage_daily')->where('day', $day->toDateString())->delete();
            $counts = 'count(*) filter (where crawler_id is null) as people, count(distinct visitor) filter (where crawler_id is null) as visitors,
                count(*) filter (where crawler_id is null and signed_in) as signed_in, count(*) filter (where crawler_id is not null) as crawlers';
            $rows = DB::table('usage_events')->where('occurred_at', '>=', $range[0])->where('occurred_at', '<', $range[1])
                ->groupBy('institution_id', 'event_id', 'subject_table', 'subject_id', 'repository_id')
                ->selectRaw("institution_id, event_id, subject_table, subject_id, repository_id, $counts")->get()
                ->concat(DB::table('usage_events')->where('occurred_at', '>=', $range[0])->where('occurred_at', '<', $range[1])
                    ->groupBy('institution_id')->selectRaw("institution_id, null as event_id, null as subject_table, null as subject_id, null as repository_id, $counts")->get());
            DB::table('usage_daily')->insert($rows->map(fn ($r) => ['id' => (string) Str::uuid7(), 'day' => $day->toDateString()] + (array) $r)->all());

            return $rows->count();
        });
    }

    /**
     * Remove the events older than the retention period; their daily counts stay. The salts of past days are removed
     * too: a cache store such as the database keeps an expired entry until it is read, and no one reads an old day's salt.
     */
    public function prune(int $days): int
    {
        foreach (range(1, 7) as $back) {
            Cache::forget('usage-salt-'.now()->subDays($back)->toDateString());
        }

        return DB::table('usage_events')->where('occurred_at', '<', now()->subDays($days)->startOfDay())->delete();
    }

    /**
     * The statistics of a period: totals, each day, the most viewed and downloaded, by repository, and by web crawler
     * (from the events, so only as far back as they are kept). Optionally for one repository or one subject.
     */
    public function statistics(string $institutionId, string $from, string $to, ?string $repositoryId = null, ?string $subjectId = null): array
    {
        $ids = Concept::where('iri', 'like', self::EVENTS.'%')->pluck('id', 'notation');
        $daily = fn () => DB::table('usage_daily')->where('institution_id', $institutionId)->whereBetween('day', [$from, $to])
            ->when($repositoryId, fn ($q, $id) => $q->where('repository_id', $id))->when($subjectId, fn ($q, $id) => $q->where('subject_id', $id));
        $items = fn () => $daily()->whereNotNull('event_id');
        $sums = 'sum(people) filter (where event_id = ?) as views, sum(people) filter (where event_id = ?) as downloads';
        $bindings = [$ids['view'] ?? null, $ids['download'] ?? null];

        $totals = $items()->selectRaw("$sums, sum(signed_in) as signed_in, sum(crawlers) as crawlers", $bindings)->first();
        $days = $items()->groupBy('day')->selectRaw("day, $sums", $bindings)->orderBy('day')->get()->keyBy(fn ($r) => (string) $r->day);
        $top = fn (string $event) => $items()->where('event_id', $ids[$event] ?? null)->groupBy('subject_table', 'subject_id')
            ->selectRaw('subject_table, subject_id, sum(people) as n')->havingRaw('sum(people) > 0')->orderByDesc('n')->limit(20)->get()
            ->map(fn ($r) => ['entity' => $this->entity($r->subject_table, $r->subject_id), 'count' => (int) $r->n])->filter(fn ($r) => $r['entity'])->values();
        $byRepository = $items()->whereNotNull('repository_id')->groupBy('repository_id')->selectRaw("repository_id, $sums", $bindings)->get();
        $repositories = Repository::with(EntityKinds::get('repository')['with'])->findMany($byRepository->pluck('repository_id'))->keyBy('id');
        $crawlers = DB::table('usage_events as e')->join('concepts as c', 'c.id', '=', 'e.crawler_id')->where('e.institution_id', $institutionId)
            ->where('e.occurred_at', '>=', $from.' 00:00:00')->where('e.occurred_at', '<=', $to.' 23:59:59')
            ->when($repositoryId, fn ($q, $id) => $q->where('e.repository_id', $id))->when($subjectId, fn ($q, $id) => $q->where('e.subject_id', $id))
            ->groupBy('c.id')->selectRaw('c.id, count(*) as n')->orderByDesc('n')->pluck('n', 'id');

        return [
            'views' => (int) $totals->views, 'downloads' => (int) $totals->downloads, 'signed_in' => (int) $totals->signed_in, 'crawlers' => (int) $totals->crawlers,
            // The whole-catalogue rows count each visitor once a day; over a period a visitor is counted each day they came.
            'visitors' => $subjectId || $repositoryId ? null : (int) $daily()->whereNull('event_id')->sum('visitors'),
            'days' => collect(CarbonImmutable::parse($from)->daysUntil(CarbonImmutable::parse($to)))->map(fn ($d) => $d->toDateString())
                ->mapWithKeys(fn ($d) => [$d => ['views' => (int) ($days[$d]->views ?? 0), 'downloads' => (int) ($days[$d]->downloads ?? 0)]]),
            'viewed' => $top('view'), 'downloaded' => $top('download'),
            'repositories' => $byRepository->filter(fn ($r) => $repositories->has($r->repository_id))
                ->map(fn ($r) => ['repository' => $repositories[$r->repository_id], 'views' => (int) $r->views, 'downloads' => (int) $r->downloads])->sortByDesc('views')->values(),
            'by_crawler' => Concept::with('labels')->findMany($crawlers->keys())->map(fn ($c) => ['crawler' => $c, 'count' => (int) $crawlers[$c->id]])->sortByDesc('count')->values(),
        ];
    }

    /** What was counted under an id, of whichever kind, if it is still there. */
    public function subject(string $id): ?Model
    {
        $table = DB::table('usage_daily')->where('subject_id', $id)->value('subject_table');

        return $table ? $this->entity($table, $id) : null;
    }

    /** The subject of a count, if it is still there, with what its label needs. */
    public function entity(string $table, string $id): ?Model
    {
        $kind = EntityKinds::byTable($table);
        $definition = $kind ? EntityKinds::get($kind) : null;

        return $definition ? $definition['class']::with($definition['with'])->whereNull('deleted_at')->find($id) : null;
    }
}
