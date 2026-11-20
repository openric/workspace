<?php

// OpenRiC Workspace - the data of a record set's name-first explorer (roadmap phase 27): its units, and every name they
// carry, from the catalogue's own relations (names, places, subjects and activities) and from the names proposed from
// their text, spellings grouped as one, in the shape of Heratio's ExplorerDataService (heratio v1.166.17), so a Heratio
// export and a Workspace explorer read the same: records {id, title, ref, date, url, ents, img?, text?, textUrl?} and
// entities {id, type, name, variants, recs, hidden?, url?}, with site {title, scope, generated}; textUrl and url are the
// Workspace's additions, which a reader of Heratio's shape passes over.
// Names proposed from the text count once accepted, or linked to an authority record (then as that record); waiting for
// review only where the record set's explorer settings show them, above its least confidence; rejected never.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Discover;

use App\Api\PublicCatalogue;
use App\Description\InstantiationService;
use App\Exchange\ExportView;
use App\Models\Activity;
use App\Models\Agent;
use App\Models\Concept;
use App\Models\DigitalFile;
use App\Models\ExplorerSetting;
use App\Models\Instantiation;
use App\Models\Place;
use App\Models\Proposal;
use App\Models\RecordResource;
use App\Models\Relation;
use App\Relations\RelationService;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ExplorerData
{
    /** The most units an explorer reads, as Heratio's live explorer. */
    public const LIMIT = 2000;

    /** How much of a unit's scope and content the panel shows (Heratio's 600). */
    public const TEXT = 600;

    /** Relations that say where a unit sits or is kept, not who or what it names. */
    private const STRUCTURAL = ['storage', 'location', 'holding', 'premises'];

    /** A proposal's kind => the explorer's type (Heratio's: PERSON, ORG and NORP are names; GPE, LOC and FAC places). */
    private const PROPOSED = ['agent' => 'name', 'place' => 'place'];

    public function __construct(private InstantiationService $instantiations, private RelationService $relations) {}

    /** @return array{site: array<string, string>, records: list<array<string, mixed>>, entities: list<array<string, mixed>>} */
    public function build(PublicCatalogue $catalogue, RecordResource $set, ?ExplorerSetting $settings = null): array
    {
        $settings ??= ExplorerSetting::for($set);
        $view = $catalogue->view;
        // ponytail: built on each request; LIMIT units read their relations one by one (about a second for 2,000). Keep
        // the array in the cache by the set's latest change when sets grow past that.
        $units = $catalogue->query('records')->whereIn('e.id', [$set->id, ...$set->descendantIds()])
            ->with('names.nameType', 'names.state', 'dates.property', 'identifiers.identifierType', 'instantiations.files.use', 'instantiations.names.state')
            ->orderBy('e.position')->orderBy('e.id')->limit(self::LIMIT)->get()->filter(fn ($u) => $view->readable($u))->values();

        $records = [];
        $mentions = []; // "type|key" => [printed form => [unit id => true]]
        $pages = []; // "type|key" => the public page of the entity the name is, where it is one
        $mention = function (string $type, ?string $name, string $unit, ?Model $entity = null) use (&$mentions, &$pages) {
            $name = trim((string) preg_replace('/\s+/u', ' ', (string) $name));
            if ($name !== '' && ($key = self::groupKey($name)) !== '') {
                $mentions["$type|$key"][$name][$unit] = true;
                $entity && ($route = self::ROUTES[$entity::class] ?? null) && $pages["$type|$key"] ??= route($route, $entity->getKey());
            }
        };
        $standing = ['accepted', 'disputed', ...($settings->unreviewed ? ['proposed'] : [])];
        foreach ($units as $unit) {
            $records[$unit->id] = $this->record($unit, $catalogue) + ['ents' => []];
            // The relations that stand, and those still proposed where the set shows what is not yet reviewed.
            foreach ($this->relations->of($unit) as $relation) {
                /** @var Relation $relation */
                if (! in_array($relation->standing(), $standing, true) || in_array($relation->kind(), self::STRUCTURAL, true)
                    || ! ($type = self::typeOf($relation->other)) || ! $view->readable($relation->other)) {
                    continue;
                }
                $mention($type, $view->label($relation->other), $unit->id, $relation->other);
            }
        }

        // The names proposed from the units' text (phase 17b2's proposals and mentions).
        $states = [Vocab::ASSERTION_STATUSES.'accepted', ...($settings->unreviewed ? [Vocab::ASSERTION_STATUSES.'proposed'] : [])];
        $proposals = Proposal::whereIn('record_resource_id', array_keys($records))
            ->whereHas('status', fn ($q) => $q->whereIn('iri', $states))
            ->whereHas('kind', fn ($q) => $q->whereIn('notation', array_keys(self::PROPOSED)))->with('kind', 'status')->get();
        foreach ($proposals as $proposal) {
            $proposed = $proposal->status->iri === Vocab::ASSERTION_STATUSES.'proposed';
            if ($proposed && $settings->min_confidence !== null && (float) $proposal->confidence < $settings->min_confidence) {
                continue;
            }
            $linked = $proposal->linked_table && $proposal->linked_id ? $catalogue->find(self::KINDS[$proposal->linked_table] ?? '', (string) $proposal->linked_id) : null;
            $linked && ($type = self::typeOf($linked))
                ? $mention($type, $view->label($linked), $proposal->record_resource_id, $linked)
                : $mention(self::PROPOSED[$proposal->kind->notation], $proposal->accepted_value ?: $proposal->value, $proposal->record_resource_id);
        }

        $hide = array_flip(array_filter(array_map(self::groupKey(...), preg_split('/[,\n]/', (string) $settings->hide))));
        $entities = [];
        foreach ($mentions as $ek => $forms) {
            $recs = array_keys(array_replace(...array_values($forms)));
            if (count($recs) < max(1, (int) $settings->min_mentions)) {
                continue;
            }
            uksort($forms, fn ($a, $b) => count($forms[$b]) <=> count($forms[$a]) ?: strcmp($a, $b));
            [$type, $key] = explode('|', $ek, 2);
            $id = "$type:$key";
            $entities[] = ['id' => $id, 'type' => $type, 'name' => (string) array_key_first($forms), 'variants' => array_map('strval', array_keys($forms)),
                'recs' => array_map('strval', $recs)] + (isset($hide[$key]) ? ['hidden' => true] : []) + (isset($pages[$ek]) ? ['url' => $pages[$ek]] : []);
            foreach ($recs as $rid) {
                $records[$rid]['ents'][] = $id;
            }
        }

        return ['site' => ['title' => (string) $view->label($set), 'scope' => (string) ($set->repository ? $view->label($set->repository) : ''),
            'generated' => now(config('workspace.timezone'))->format('j F Y H:i')], 'records' => array_values($records), 'entities' => $entities];
    }

    /**
     * The text of an instantiation's pages: its transcript, else the file itself where it is plain text (a transcript
     * kept as a file). It goes where the reference copy would (InstantiationService::textDeliverable), which the caller asks.
     */
    public static function text(Instantiation $instantiation, ExportView $view): ?string
    {
        $transcript = $view->values($instantiation, 'instantiation-transcript')->pluck('value')->filter()->implode("\n\n");
        if ($transcript !== '') {
            return $transcript;
        }
        $master = $instantiation->files->first(fn (DigitalFile $f) => $f->use->notation === 'master');
        if ($master && str_starts_with((string) $master->mime_type, 'text/plain') && is_file($path = app(InstantiationService::class)->path($master))) {
            return (string) file_get_contents($path);
        }

        return null;
    }

    /** Heratio's groupKey: lower case, ASCII, without a leading "the", letters and digits only. */
    public static function groupKey(string $name): string
    {
        $k = Str::lower(Str::ascii(trim($name)));
        $k = (string) preg_replace('/^the\s+/', '', $k);

        return (string) preg_replace('/[^a-z0-9]+/', '', $k);
    }

    /** An entity's public page (an entity's url is the Workspace's addition to Heratio's shape; Heratio leaves it out). */
    private const ROUTES = [Agent::class => 'discover.agent', Place::class => 'discover.place', Activity::class => 'discover.activity'];

    /** A table a proposal links to => the catalogue's kind. */
    private const KINDS = ['agents' => 'agents', 'places' => 'places', 'activities' => 'activities', 'repositories' => 'repositories'];

    private static function typeOf(?Model $entity): ?string
    {
        return match (true) {
            $entity instanceof Agent => 'name',
            $entity instanceof Place => 'place',
            $entity instanceof Activity => 'event',
            $entity instanceof Concept => 'subject',
            default => null,
        };
    }

    /** @return array<string, string> */
    private function record(RecordResource $unit, PublicCatalogue $catalogue): array
    {
        $view = $catalogue->view;
        $out = ['id' => $unit->id, 'title' => (string) $view->label($unit), 'ref' => (string) ($unit->referenceCode() ?? ''),
            'date' => (string) ($unit->dates->sortBy(fn ($d) => $d->earliest && $d->latest ? $d->earliest->diffInDays($d->latest) : PHP_INT_MAX)->first()?->display() ?? ''), 'url' => route('discover.record', $unit->id)]; // the narrowest of its dates: a printed day over the month of the set
        // The picture: the first image's reference copy, else its thumbnail; the text: the first plain-text file (a transcript).
        foreach ($unit->instantiations->filter(fn ($i) => $view->label($i) !== null) as $instantiation) {
            $files = $instantiation->files->filter(fn (DigitalFile $f) => $this->instantiations->deliverable(null, $f->setRelation('instantiation', $instantiation)))
                ->keyBy(fn (DigitalFile $f) => $f->use->notation);
            $image = $files->first(fn (DigitalFile $f, string $use) => $use === 'reference') ?? $files->first(fn (DigitalFile $f, string $use) => $use === 'thumbnail');
            if (! isset($out['img']) && $image) {
                $out['img'] = route('discover.file', $image);
            }
            $plain = str_starts_with((string) $instantiation->files->first(fn (DigitalFile $f) => $f->use->notation === 'master')?->mime_type, 'text/plain');
            if (! isset($out['textUrl']) && ($plain || $view->values($instantiation, 'instantiation-transcript')->isNotEmpty()) && $this->instantiations->textDeliverable(null, $instantiation)) {
                $out['textUrl'] = route('discover.explorer.text', [$unit->id, $instantiation->id]);
            }
        }
        $scope = trim((string) preg_replace('/\s+/u', ' ', (string) $view->value($unit, 'scope-and-content')));
        if ($scope !== '') {
            $out['text'] = mb_strlen($scope) > self::TEXT ? mb_substr($scope, 0, self::TEXT).'...' : $scope;
        }

        return $out;
    }
}
