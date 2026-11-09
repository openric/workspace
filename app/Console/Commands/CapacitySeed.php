<?php

// OpenRiC Workspace - a large synthetic archive for the capacity test (roadmap R1 hardening, after the external
// production-readiness assessment of 7 November 2026), made as the application makes records: through the same services,
// audited and signed, so what is measured afterwards is what an institution's own data would be. By default 100,000 units
// of description (a fonds of 10,000 items directly under it, a branch eight levels deep and fonds of series, files and
// items), 10,000 authority records, 2,000 places and 500 dated activities, linked as creators, subjects and places; most
// published, some left as drafts and some closed; a few hundred small scans. Only into a database whose name ends in
// _capacity, so it can never be run on a real Workspace; it is kept afterwards.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Console\Commands;

use App\Accounts\AccountService;
use App\Description\ActivityService;
use App\Description\AgentService;
use App\Description\DateService;
use App\Description\InstantiationService;
use App\Description\PlaceService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Models\Activity;
use App\Models\Agent;
use App\Models\Institution;
use App\Models\Place;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\User;
use App\Relations\RelationService;
use App\Rights\Embargoes;
use App\Search\SearchIndex;
use App\Support\Vocab;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Imagick;
use ImagickPixel;

class CapacitySeed extends Command
{
    protected $signature = 'workspace:capacity:seed
        {--password= : The password the capacity accounts sign in with (at least 12 characters)}
        {--units=100000 : Units of description}
        {--agents=10000 : Authority records}
        {--places=2000 : Places}
        {--activities=500 : Dated activities}
        {--files=300 : Units given a small scan}
        {--seed=20261109 : The random seed, so a run can be made again}';

    protected $description = 'Fill a capacity-test database (its name ends in _capacity) with a large synthetic archive, through the application\'s own services';

    private const LEVEL = Vocab::BASE.'level-of-description/';

    /** Units written in one transaction: few enough that a failure loses little. */
    private const BATCH = 250;

    private const FIRST = ['Anna', 'Jacob', 'Maria', 'Pieter', 'Sarah', 'Johannes', 'Elsie', 'Thabo', 'Nomsa', 'David', 'Ruth', 'Sipho', 'Grace', 'Willem', 'Lerato', 'Hendrik', 'Martha', 'Themba', 'Susanna', 'Andries'];

    private const LAST = ['Botha', 'Visser', 'Naudé', 'Mokoena', 'Dlamini', 'van der Merwe', 'Smith', 'Nkosi', 'du Plessis', 'Khumalo', 'Pretorius', 'Ndlovu', 'Venter', 'Molefe', 'Jacobs', 'Steyn', 'Zulu', 'Coetzee', 'Mahlangu', 'Fourie'];

    private const BODIES = ['Council', 'School Board', 'Hospital', 'Water Board', 'Church Council', 'Farmers\' Association', 'Magistrate\'s Court', 'Library Committee', 'Mission Station', 'Railway Office'];

    private const TOWNS = ['Riverside', 'Hillcrest', 'Kloofdal', 'Mooiplaas', 'Bergvliet', 'Doornkop', 'Rietfontein', 'Weltevreden', 'Brakpan', 'Stillwater', 'Rooikrans', 'Elandsrivier'];

    private const SUBJECTS = ['water supply', 'roads and bridges', 'public health', 'land and title', 'schools', 'burials', 'rates and taxes', 'markets', 'drought relief', 'electricity', 'housing', 'licences'];

    private const KINDS = ['Minutes', 'Correspondence', 'Accounts', 'Registers', 'Reports', 'Plans', 'Petitions', 'Photographs'];

    private User $actor;

    private Institution $institution;

    private Repository $repository;

    private int $made = 0;

    private int $total;

    private float $started;

    /** @var list<array{0: string, 1: int, 2: ?Agent}> each unit's id, its depth and its creator, for linking later */
    private array $units = [];

    public function handle(): int
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        if (! str_ends_with($database, '_capacity')) {
            $this->error("Refused: this fills a capacity-test database, whose name ends in _capacity; this one is $database.");

            return self::FAILURE;
        }
        $institution = Institution::orderBy('created_at')->first();
        if ($institution === null) {
            $this->error('Install the Workspace first: php artisan workspace:install.');

            return self::FAILURE;
        }
        $this->institution = $institution;
        if (User::where('email', 'archivist@capacity.invalid')->exists()) {
            $this->error('The capacity dataset is loaded already; it is kept, not loaded twice.');

            return self::FAILURE;
        }
        $password = (string) $this->option('password');
        if (strlen($password) < 12) {
            $this->error('Give --password, at least 12 characters, for the capacity accounts.');

            return self::INVALID;
        }
        mt_srand((int) $this->option('seed'));
        $this->total = max(10, (int) $this->option('units'));
        $this->started = microtime(true);

        $accounts = app(AccountService::class);
        $this->actor = $accounts->create($this->institution, 'Capacity archivist', 'archivist@capacity.invalid', $password);
        $accounts->grantTemplateRole($this->actor, 'editor');
        $accounts->create($this->institution, 'Capacity reader', 'reader@capacity.invalid', $password);
        auth()->login($this->actor);
        $this->repository = Repository::where('institution_id', $this->institution->id)->orderBy('created_at')->firstOrFail();

        [$agents, $bodies] = $this->authorities((int) $this->option('agents'));
        $places = $this->places((int) $this->option('places'));
        $this->line(sprintf('%d authority records and %d places in %.0f s.', count($agents), count($places), microtime(true) - $this->started));

        // A fonds of 10,000 items directly under it, for the widest tree (or a tenth of the units when there are fewer).
        $wide = min(10_000, intdiv($this->total, 10));
        $fonds = null;
        $this->batch(function () use (&$fonds, $bodies) {
            $fonds = $this->unit('fonds', 'Registers of births and deaths, '.$this->pick(self::TOWNS).' (synthetic)', null, 'CAP-W', 0, $bodies);
        });
        foreach (array_chunk(range(1, $wide), self::BATCH) as $chunk) {
            $this->batch(function () use ($chunk, $fonds) {
                foreach ($chunk as $i) {
                    $this->unit('item', 'Register entry '.$i.', '.(1900 + $i % 100).' (synthetic)', $fonds, "CAP-W/$i", 1);
                }
            });
        }

        // A branch eight levels deep.
        $this->batch(function () use ($bodies) {
            $parent = null;
            foreach (['fonds', 'subfonds', 'subfonds', 'series', 'subseries', 'file', 'item', 'part'] as $depth => $level) {
                $parent = $this->unit($level, ucfirst($level).' at depth '.($depth + 1).' of the deep fonds (synthetic)', $parent, 'CAP-D'.str_repeat('/1', $depth), $depth, $bodies);
            }
        });

        // The rest: fonds of five series of twenty files of nine items.
        for ($f = 1; $this->made < $this->total; $f++) {
            $this->batch(function () use ($f, $bodies) {
                $body = $this->pick(self::TOWNS).' '.$this->pick(self::BODIES);
                $fonds = $this->unit('fonds', "Records of the $body (synthetic)", null, "CAP-$f", 0, $bodies);
                $this->describe($fonds, "Synthetic records of the $body, made for the OpenRiC Workspace capacity test. Every name in them is invented.");
                for ($s = 1; $s <= 5 && $this->made < $this->total; $s++) {
                    $kind = self::KINDS[($f + $s) % count(self::KINDS)];
                    $series = $this->unit('series', "$kind of the $body (synthetic)", $fonds, "CAP-$f/$s", 1);
                    for ($d = 1; $d <= 20 && $this->made < $this->total; $d++) {
                        $year = 1900 + ($f * 7 + $d) % 120;
                        $subject = $this->pick(self::SUBJECTS);
                        $file = $this->unit('file', "$kind about $subject, $year (synthetic)", $series, "CAP-$f/$s/$d", 2);
                        $this->describe($file, "$kind of the $body about $subject in $year, among them letters from ".$this->person().'.');
                        for ($i = 1; $i <= 9 && $this->made < $this->total; $i++) {
                            $item = $this->unit('item', "$kind about $subject, ".$this->month()." $year (synthetic)", $file, "CAP-$f/$s/$d/$i", 3);
                            if ($i % 3 === 0) {
                                $this->describe($item, 'Concerning '.$this->person()." and $subject at ".$this->pick(self::TOWNS).'.');
                            }
                        }
                        $this->checkpoint();
                    }
                }
            });
        }
        $this->line(sprintf('%d units in %.0f s.', $this->made, microtime(true) - $this->started));

        $this->relations($agents, $places);
        $this->activities((int) $this->option('activities'), $agents);
        $this->publish();
        $this->scans((int) $this->option('files'));
        app(SearchIndex::class)->flush();

        $this->info(sprintf('Loaded the capacity dataset in %.0f minutes: %d units, %d authority records, %d places.', (microtime(true) - $this->started) / 60, $this->made, count($agents), count($places)));
        $this->line('Accounts: archivist@capacity.invalid (editor) and reader@capacity.invalid, with the password given. The dataset is kept.');

        return self::SUCCESS;
    }

    /** @return array{0: list<Agent>, 1: list<Agent>} every authority record, and the corporate bodies */
    private function authorities(int $count): array
    {
        $service = app(AgentService::class);
        $all = $bodies = [];
        for ($done = 0; $done < $count;) {
            DB::transaction(function () use ($service, $count, &$done, &$all, &$bodies) {
                for ($n = 0; $n < self::BATCH && $done < $count; $n++, $done++) {
                    $roll = mt_rand(1, 100);
                    if ($roll <= 70) {
                        $all[] = $service->create($this->institution, 'person', $this->pick(self::LAST).', '.$this->pick(self::FIRST)." ($done, synthetic)", 'en', null, $this->actor);
                    } elseif ($roll <= 95) {
                        $all[] = $bodies[] = $service->create($this->institution, 'corporate-body', $this->pick(self::TOWNS).' '.$this->pick(self::BODIES)." ($done, synthetic)", 'en', null, $this->actor);
                    } else {
                        $all[] = $service->create($this->institution, 'family', $this->pick(self::LAST)." family ($done, synthetic)", 'en', null, $this->actor);
                    }
                }
            });
            app(SearchIndex::class)->flush();
        }

        return [$all, $bodies ?: $all];
    }

    private function places(int $count): array
    {
        $service = app(PlaceService::class);
        $all = [];
        for ($done = 0; $done < $count;) {
            DB::transaction(function () use ($service, $count, &$done, &$all) {
                for ($n = 0; $n < self::BATCH && $done < $count; $n++, $done++) {
                    $all[] = $service->create($this->institution, $this->pick(self::TOWNS).' '.['farm', 'ward', 'district', 'location', 'township'][$done % 5]." $done (synthetic)", 'en', null, $this->actor);
                }
            });
            app(SearchIndex::class)->flush();
        }

        return $all;
    }

    /** Creators for fonds and series, subjects for a fifth of the items, places for a tenth. */
    private function relations(array $agents, array $places): void
    {
        $relations = app(RelationService::class);
        foreach (array_chunk($this->units, self::BATCH) as $chunk) {
            DB::transaction(function () use ($chunk, $relations, $agents, $places) {
                $found = RecordResource::whereIn('id', array_column($chunk, 0))->get()->keyBy('id');
                foreach ($chunk as [$id, $depth, $creator]) {
                    $unit = $found[$id];
                    if ($creator) {
                        $relations->create($unit, 'creation', $creator, [], $this->actor);
                    } elseif ($depth >= 3 && mt_rand(1, 5) === 1) {
                        $relations->create($unit, 'subject', $agents[array_rand($agents)], [], $this->actor);
                    }
                    if ($depth >= 2 && mt_rand(1, 10) === 1) {
                        $relations->create($unit, 'subject', $places[array_rand($places)], [], $this->actor);
                    }
                }
            });
            app(SearchIndex::class)->flush();
        }
        $this->line(sprintf('Relations made at %.0f s.', microtime(true) - $this->started));
    }

    private function activities(int $count, array $agents): void
    {
        $activities = app(ActivityService::class);
        $relations = app(RelationService::class);
        $dates = app(DateService::class);
        $fonds = array_values(array_filter($this->units, fn ($u) => $u[1] === 0));
        foreach (array_chunk(range(1, $count), 100) as $chunk) {
            DB::transaction(function () use ($chunk, $activities, $relations, $dates, $agents, $fonds) {
                foreach ($chunk as $n) {
                    $year = 1900 + $n % 120;
                    $activity = $activities->create($this->institution, 'Keeping the '.$this->pick(self::KINDS)." records, $year (synthetic $n)", 'en', null, $this->actor);
                    $dates->add($activity, ['property' => 'associated', 'normalized' => $year.'/'.($year + 9)], $this->actor);
                    $relations->create($activity, 'performance', $agents[array_rand($agents)], [], $this->actor);
                    $relations->create($activity, 'result', RecordResource::findOrFail($fonds[array_rand($fonds)][0]), [], $this->actor);
                }
            });
        }
        $this->line(sprintf('Activities made at %.0f s.', microtime(true) - $this->started));
    }

    /** Everything published but one unit in twenty, left as a draft; one in a hundred closed until 2040. */
    private function publish(): void
    {
        $publication = app(PublicationService::class);
        $embargoes = app(Embargoes::class);
        $entities = fn (string $class) => $class::where('institution_id', $this->institution->id)->orderBy('id')->lazyById(self::BATCH);
        $publication->apply($this->repository, 'publish', null, false, $this->actor);
        foreach ([Agent::class, Place::class, Activity::class] as $class) {
            foreach ($entities($class)->chunk(self::BATCH) as $chunk) {
                DB::transaction(fn () => $chunk->each(fn ($e) => $publication->apply($e, 'publish', null, false, $this->actor)));
                app(SearchIndex::class)->flush();
            }
        }
        $n = 0;
        foreach ($entities(RecordResource::class)->chunk(self::BATCH) as $chunk) {
            DB::transaction(function () use ($chunk, $publication, $embargoes, &$n) {
                foreach ($chunk as $unit) {
                    $n++;
                    if ($n % 20 !== 7) {
                        $publication->apply($unit, 'publish', null, false, $this->actor);
                    }
                    if ($n % 100 === 13 && $unit->parent_id !== null) { // never one of the drafts (n % 20 === 7)
                        $embargoes->impose($unit, ['type' => 'full', 'reason' => 'privacy', 'ends_on' => '2040-12-31', 'public_message' => 'Closed until 2040 (synthetic).'], false, $this->actor);
                    }
                }
            });
            app(SearchIndex::class)->flush();
        }
        $this->line(sprintf('Published at %.0f s.', microtime(true) - $this->started));
    }

    private function scans(int $count): void
    {
        $instantiations = app(InstantiationService::class);
        $items = array_values(array_filter($this->units, fn ($u) => $u[1] === 3));
        $path = tempnam(sys_get_temp_dir(), 'capacity').'.png';
        try {
            for ($i = 0; $i < $count && $items !== []; $i++) {
                $image = new Imagick;
                $image->newImage(800, 600, new ImagickPixel(sprintf('#%02x%02x%02x', 220 + $i % 30, 200 + $i % 40, 160 + $i % 60)));
                $image->setImageFormat('png');
                $image->writeImage($path);
                $instantiation = $instantiations->upload(RecordResource::findOrFail($items[($i * 97) % count($items)][0]), $path, "scan-$i.png", 'en', "Scan $i (synthetic)", null, 'online', $this->actor);
                rescue(fn () => $instantiations->makeCopies($instantiation->fresh(['files.use']), $this->actor), report: false);
                app(PublicationService::class)->apply($instantiation, 'publish', null, false, $this->actor);
            }
        } finally {
            @unlink($path);
        }
        app(SearchIndex::class)->flush();
        $this->line(sprintf('%d scans at %.0f s.', $count, microtime(true) - $this->started));
    }

    private function unit(string $level, string $title, ?RecordResource $parent, string $identifier, int $depth, ?array $creators = null): RecordResource
    {
        $unit = app(RecordService::class)->create($this->institution, self::LEVEL.$level, $title, 'en', $parent, $parent ? null : $this->repository, $identifier, $this->actor);
        // Ids, not models: a hundred thousand models would not fit in memory.
        $this->units[] = [$unit->id, $depth, $creators ? $creators[array_rand($creators)] : null];
        $this->made++;

        return $unit;
    }

    private function describe(RecordResource $unit, string $scope): void
    {
        app(RecordService::class)->describe($unit, ['scope-and-content' => $scope], 'en', $this->actor);
    }

    /** One transaction, then the search index of what it made. */
    private function batch(callable $work): void
    {
        DB::transaction($work);
        $this->checkpoint();
    }

    private function checkpoint(): void
    {
        app(SearchIndex::class)->flush();
        static $last = 0;
        if ($this->made - $last >= 2000) {
            $last = $this->made;
            $rate = $this->made / max(1, microtime(true) - $this->started);
            $this->line(sprintf('%d of %d units, %.0f a second, about %.0f minutes to go.', $this->made, $this->total, $rate, ($this->total - $this->made) / max(1, $rate) / 60));
        }
    }

    private function pick(array $list): string
    {
        return $list[mt_rand(0, count($list) - 1)];
    }

    private function person(): string
    {
        return $this->pick(self::FIRST).' '.$this->pick(self::LAST);
    }

    private function month(): string
    {
        return ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'][mt_rand(0, 11)];
    }
}
