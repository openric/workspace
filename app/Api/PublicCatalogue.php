<?php

// OpenRiC Workspace - what the public may find: the records, agents and repositories the API and OAI-PMH serve.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// One place for both: an entity is served once its authorised name or title
// is published and the institution's Public role may read it (ExportView,
// ADR 0005). A record's datestamp, for harvesting, is the latest change to
// it or to its names, values, identifiers and dates, so publishing a value
// or correcting a date is harvested again.

namespace App\Api;

use App\Access\AccessControl;
use App\Description\InstantiationService;
use App\Exchange\ExportView;
use App\Models\Activity;
use App\Models\Agent;
use App\Models\DigitalFile;
use App\Models\Instantiation;
use App\Models\Institution;
use App\Models\Name;
use App\Models\Place;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\Rule;
use App\Privacy\Redaction;
use App\Relations\EntityKinds;
use App\Rights\CommunityProtocols;
use App\Rights\Embargoes;
use App\Support\Vocab;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PublicCatalogue
{
    public const KINDS = ['records' => RecordResource::class, 'agents' => Agent::class, 'repositories' => Repository::class, 'places' => Place::class, 'activities' => Activity::class, 'rules' => Rule::class, 'instantiations' => Instantiation::class];

    public const WITH = ['names.nameType', 'names.state', 'descriptionValues.element', 'descriptionValues.state', 'identifiers.identifierType', 'dates.property', 'dates.dateType'];

    /** The tables whose rows are parts of a described entity. */
    private const PARTS = ['names', 'description_values', 'identifiers', 'dates'];

    public readonly ExportView $view;

    public function __construct(public readonly Institution $institution)
    {
        $this->view = new ExportView(null, false, null, $institution);
    }

    /**
     * Every entity of a kind the public may read, in name order; with a query, those whose name or identifier contains it
     * (or, with $prefix, whose name starts with it), narrowed further by $narrow over query() (the units at the top, a
     * repository's). Decided in SQL (ids()), then checked again entity by entity, so a list of hundreds stays exactly
     * what readable() allows; a long list is counted or paged with query() instead.
     */
    public function visible(string $kind, string $query = '', bool $prefix = false, array $with = [], ?callable $narrow = null): Collection
    {
        $class = self::KINDS[$kind];

        return $this->query($kind, $query, $prefix)->when($narrow, $narrow)
            ->with([...self::WITH, ...$with])->get()
            ->tap(function (Collection $found) use ($class) {
                // Each entity's checks below would otherwise ask the database one record at a time.
                if ($class === RecordResource::class) {
                    app(Embargoes::class)->prime($found);
                    app(Redaction::class)->prime($found);
                }
            })
            ->filter(fn ($entity) => $this->view->readable($entity))
            ->sortBy(fn ($entity) => mb_strtolower((string) $this->view->label($entity)))->values();
    }

    /**
     * The entities of a kind the public may read, as a query to count, order and page in the database (v0.0.146, after
     * the capacity test found every public list loading all published records): ids() with the search of visible().
     *
     * @return Builder<Model>
     */
    public function query(string $kind, string $query = '', bool $prefix = false): Builder
    {
        $class = self::KINDS[$kind];
        $table = (new $class)->getTable();
        $like = ($prefix ? '' : '%').addcslashes($query, '%_\\').'%';

        $found = $class::query()->from("$table as e")->select('e.*');
        $this->constrain($found->getQuery(), $kind);

        return $found->when($query !== '', fn (Builder $q) => $q->where(fn ($q) => $q
                // Any published name finds it, not only the authorised one: a place's or a body's other and former names
                // are names in their own right (RiC-CM 1.0.1 Name; design review of 2 November 2026, scenario A).
            ->whereIn('e.id', Name::where('subject_table', $table)->whereHas('state', fn ($q) => $q->where('iri', Vocab::valueState('published')))
                ->where('value', 'ilike', $like)->select('subject_id'))
            ->when(! $prefix, fn ($q) => $q->orWhereIn('e.id', DB::table('identifiers')->where('subject_table', $table)->where('value', 'ilike', $like)->select('subject_id')))));
    }

    /**
     * Order a query() by name, as visible() orders in PHP: the published authorised name, in its original language
     * first, without regard to case; then by id, so pages do not overlap (v0.0.146).
     *
     * @param  Builder<Model>  $found
     * @return Builder<Model>
     */
    public function byName(Builder $found, string $kind): Builder
    {
        $table = (new (self::KINDS[$kind]))->getTable();

        // One name an entity, joined once, rather than a lookup for every row before the sort.
        $names = DB::table('names')->selectRaw('DISTINCT ON (subject_id) subject_id, lower(value) AS sort_name')->where('subject_table', $table)
            ->where('state_id', $this->concept(Vocab::valueState('published')))->where('name_type_id', $this->concept(Vocab::NAME_TYPES.'authorised'))
            ->orderBy('subject_id')->orderByDesc('is_original')->orderBy('language');

        return $found->leftJoinSub($names, 'sort_names', 'sort_names.subject_id', '=', 'e.id')->orderBy('sort_names.sort_name')->orderBy('e.id');
    }

    /**
     * The ids of every entity of a kind the public may read, decided in SQL as ExportView::readable() decides one entity
     * for the public: in the institution and not deleted; its authorised name published; an authority record not under
     * embargo; a record under no full embargo (unless an exception opens it to the visitor's address), no security
     * classification of its own or inherited, and no community protocol withholding it; and the Public role's read
     * permission, the most specific scope deciding (the record, the units above it nearest first, its repository, then
     * everything), a denial winning at its level. PublicCatalogueSqlTest keeps the two in step.
     */
    public function ids(string $kind): QueryBuilder
    {
        $table = (new (self::KINDS[$kind]))->getTable();

        return $this->constrain(DB::table("$table as e")->select('e.id'), $kind);
    }

    /** @var array<string, string> IRI => concept id */
    private array $concepts = [];

    private function concept(string $iri): string
    {
        return $this->concepts[$iri] ??= (string) DB::table('concepts')->where('iri', $iri)->value('id');
    }

    /** Narrow a query over the kind's table, aliased e, to what the public may read (ids()). */
    private function constrain(QueryBuilder $ids, string $kind): QueryBuilder
    {
        $class = self::KINDS[$kind];
        $table = (new $class)->getTable();
        $institution = $this->institution->id;
        $today = CarbonImmutable::now(config('workspace.timezone'))->toDateString();
        // EXISTS rather than IN, so a query for a few (the units at the top, say) starts from them, not from every name.
        $ids->where('e.institution_id', $institution)->whereNull('e.deleted_at')
            // By the two terms' ids rather than a join on their IRIs, so PostgreSQL estimates from the column's statistics how
            // many names are published; joined, it guessed a few dozen of 100,000 and chose plans that ran for seconds.
            ->whereExists(fn ($q) => $q->from('names as n')->whereColumn('n.subject_id', 'e.id')->where('n.subject_table', $table)
                ->where('n.state_id', $this->concept(Vocab::valueState('published')))->where('n.name_type_id', $this->concept(Vocab::NAME_TYPES.'authorised')));

        if ($class === Agent::class) {
            $ids->where(fn ($q) => $q->whereNull('e.embargo_until')->orWhere('e.embargo_until', '<=', $today));
        }
        if ($class === RecordResource::class) {
            $ip = request()->ip();
            // A full embargo in force, with no exception in force for the visitor's address (a visitor has no account or role).
            // As a set (NOT IN), which PostgreSQL hashes once, rather than a lookup for every record.
            $ids->whereNotIn('e.id', fn ($q) => $q->from('embargoes as m')->join('concepts as t', 't.id', '=', 'm.type_id')->select('m.record_resource_id')
                ->where('m.institution_id', $institution)->where('t.iri', Embargoes::TYPES.'full')
                ->whereNull('m.lifted_at')->where('m.starts_on', '<=', $today)->where(fn ($q) => $q->whereNull('m.ends_on')->orWhere('m.ends_on', '>=', $today))
                ->when($ip !== null && filter_var($ip, FILTER_VALIDATE_IP), fn ($q) => $q->whereNotExists(fn ($x) => $x->from('embargo_exceptions as x')->whereColumn('x.embargo_id', 'm.id')
                    ->where(fn ($w) => $w->whereNull('x.valid_from')->orWhere('x.valid_from', '<=', $today))
                    ->where(fn ($w) => $w->whereNull('x.valid_until')->orWhere('x.valid_until', '>=', $today))
                    ->whereNotNull('x.ip_from')->whereRaw('family(x.ip_from) = family(?::inet) AND ?::inet BETWEEN x.ip_from AND x.ip_to', [$ip, $ip]))));
            // A classification above level 0: its own, else that of the nearest unit above it that has one, if that one passes to children.
            $ids->whereRaw('e.id NOT IN (WITH RECURSIVE cl(id, level, inherit) AS (
                    SELECT rc.record_resource_id, sl.level, rc.inherit_to_children FROM record_classifications rc
                        JOIN security_levels sl ON sl.id = rc.level_id JOIN record_resources r ON r.id = rc.record_resource_id WHERE r.institution_id = ?
                    UNION ALL
                    SELECT c.id, cl.level, true FROM record_resources c JOIN cl ON c.parent_id = cl.id
                        WHERE cl.inherit AND NOT EXISTS (SELECT 1 FROM record_classifications o WHERE o.record_resource_id = c.id)
                ) SELECT id FROM cl WHERE level > 0)', [$institution]);
            // Withheld by a community protocol: tagged with a withheld term, or labelled with a withholding condition.
            $withholding = DB::table('concepts as c')->join('concepts as g', 'g.id', '=', 'c.broader_id')->where('g.iri', CommunityProtocols::CONDITIONS.'withheld')->select('c.id');
            $ids->whereNotIn('e.id', DB::table('relations')->where(['institution_id' => $institution, 'source_table' => 'record_resources', 'target_table' => 'concepts'])
                ->whereIn('target_id', DB::table('concept_protocols')->where('institution_id', $institution)->whereIn('access_condition_id', $withholding)->select('concept_id'))
                ->select('source_id'));
            $ids->whereNotIn('e.id', DB::table('community_labels as l')->leftJoin('community_label_conditions as d', 'd.label_id', '=', 'l.label_id')
                ->where(['l.institution_id' => $institution, 'l.subject_table' => 'record_resources'])
                ->whereIn(DB::raw('COALESCE(l.access_condition_id, d.access_condition_id)'), $withholding)->select('l.subject_id'));
        }

        $definition = EntityKinds::get(EntityKinds::of(new $class));
        if ($definition['readable']) {
            $this->permitted($ids, $definition['permission'], $class);
        }

        return $ids;
    }

    /** The Public role's read permission on the kind, as SQL over the same rows AccessControl::allows() reads. */
    private function permitted(QueryBuilder $ids, string $permission, string $class): void
    {
        $rows = app(AccessControl::class)->rows(null, 'read', $permission, $this->institution->id);
        if ($rows->isEmpty()) {
            $ids->whereRaw('false');

            return;
        }
        // At each scope a denial wins: [id => true granted, false denied].
        $decide = fn (Collection $at, string $key) => $at->groupBy($key)->map(fn ($cells) => ! $cells->contains(fn ($c) => ! $c->is_grant))->all();
        $subjects = $decide($rows->whereNotNull('subject_id'), 'subject_id');
        $repositories = $decide($rows->whereNull('subject_id')->whereNotNull('repository_id'), 'repository_id');
        $everything = $rows->whereNull('subject_id')->whereNull('repository_id');
        $all = $everything->isEmpty() ? null : ! $everything->contains(fn ($c) => ! $c->is_grant);
        $granted = fn (array $decided) => array_keys(array_filter($decided));
        $denied = fn (array $decided) => array_keys(array_filter($decided, fn ($g) => ! $g));

        // The repository's scope, then everything: as SQL over a repository id column.
        $fromRepository = function ($q, string|Expression $column) use ($repositories, $all, $granted, $denied) {
            if ($all === true) {
                $q->where(fn ($q) => $q->whereNull($column)->orWhereNotIn($column, $denied($repositories) ?: ['00000000-0000-0000-0000-000000000000']));
            } else {
                $q->whereIn($column, $granted($repositories) ?: ['00000000-0000-0000-0000-000000000000']);
            }
        };

        if ($class === RecordResource::class || $class === Instantiation::class) {
            // An instantiation is read as its unit of description is.
            [$record, $repository] = $class === RecordResource::class
                ? ['e.id', 'e.repository_id']
                : ['e.record_resource_id', DB::raw('(SELECT repository_id FROM record_resources WHERE id = e.record_resource_id)')];
            if ($subjects === []) {
                $fromRepository($ids, $repository);

                return;
            }
            // The decided units and everything below them, each decided by the nearest decided unit above or at it.
            $tree = 'WITH RECURSIVE down(id, allow) AS (SELECT s.id::uuid, s.allow FROM (VALUES '.implode(', ', array_fill(0, count($subjects), '(?, ?::boolean)')).') AS s(id, allow)
                UNION ALL SELECT c.id, down.allow FROM record_resources c JOIN down ON c.parent_id = down.id WHERE c.id::text NOT IN ('.implode(', ', array_fill(0, count($subjects), '?')).'))';
            $bindings = [...array_merge(...array_map(fn ($id, $g) => [$id, $g ? 'true' : 'false'], array_keys($subjects), $subjects)), ...array_keys($subjects)];
            $ids->where(fn ($q) => $q->whereRaw("$record IN ($tree SELECT id FROM down WHERE allow)", $bindings)
                ->orWhere(fn ($q) => $q->whereRaw("$record NOT IN ($tree SELECT id FROM down)", $bindings)->where(fn ($q) => $fromRepository($q, $repository))));

            return;
        }
        // Any other kind: its own scope, then (a repository) its repository's, then everything.
        $ids->where(fn ($q) => $q->whereIn('e.id', $granted($subjects) ?: ['00000000-0000-0000-0000-000000000000'])
            ->orWhere(fn ($q) => $q->whereNotIn('e.id', array_keys($subjects) ?: ['00000000-0000-0000-0000-000000000000'])
                ->where(fn ($q) => $class === Repository::class ? $fromRepository($q, 'e.id') : ($all === true ? $q->whereRaw('true') : $q->whereRaw('false')))));
    }

    /** One entity the public may read, by its UUID, or null. */
    public function find(string $kind, string $key, array $with = []): ?Model
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $key)) {
            return null;
        }
        $class = self::KINDS[$kind];
        $entity = $class::where('institution_id', $this->institution->id)->whereNull('deleted_at')->with([...self::WITH, ...$with])->find($key);

        return $entity && $this->view->readable($entity) ? $entity : null;
    }

    /**
     * The latest change to each record or any of its parts, in UTC.
     *
     * @param  list<string>  $ids
     * @return array<string, CarbonImmutable>
     */
    public function datestamps(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $changes = DB::table('record_resources')->whereIn('id', $ids)->select('id as subject_id', 'updated_at');
        foreach (self::PARTS as $table) {
            $changes->unionAll(DB::table($table)->where('subject_table', 'record_resources')->whereIn('subject_id', $ids)->select('subject_id', 'updated_at'));
        }

        return DB::query()->fromSub($changes, 'changes')->groupBy('subject_id')->selectRaw('subject_id, max(updated_at) as changed')->pluck('changed', 'subject_id')
            ->map(fn ($at) => CarbonImmutable::parse($at)->utc())->all();
    }

    /**
     * The published records for harvesting (OAI-PMH; v0.0.146, in SQL): each with its datestamp, the latest change to it
     * or to its names, values, identifiers and dates (as datestamps()), and the unit at the top of its hierarchy, which
     * is its set; changed from and until, in one set, oldest first to the second, then by id, as the list was before.
     *
     * @return QueryBuilder rows of id, changed and top
     */
    public function harvest(?CarbonImmutable $from = null, ?CarbonImmutable $until = null, ?string $top = null): QueryBuilder
    {
        $visible = $this->ids('records');
        // Each part table aggregated whole and then joined, which PostgreSQL hashes, rather than looked up record by record.
        $parts = implode(' UNION ALL ', array_map(fn ($table) => "SELECT subject_id, updated_at FROM $table WHERE subject_table = 'record_resources'", self::PARTS));
        $sql = 'WITH RECURSIVE v AS MATERIALIZED ('.$visible->toSql().'),
            roots(id, top) AS (SELECT id, id FROM record_resources WHERE institution_id = ? AND parent_id IS NULL
                UNION ALL SELECT c.id, roots.top FROM record_resources c JOIN roots ON c.parent_id = roots.id),
            stamps(id, changed) AS (SELECT subject_id, max(updated_at) FROM (SELECT id AS subject_id, updated_at FROM record_resources WHERE institution_id = ?
                UNION ALL '.$parts.') z GROUP BY subject_id)
            SELECT v.id, s.changed, r.top FROM v JOIN stamps s ON s.id = v.id JOIN roots r ON r.id = v.id';

        return DB::query()->fromRaw("($sql) AS h", [...$visible->getBindings(), $this->institution->id, $this->institution->id])
            ->when($from, fn ($q) => $q->where('h.changed', '>=', $from->format('Y-m-d H:i:s.uP')))
            ->when($until, fn ($q) => $q->where('h.changed', '<=', $until->format('Y-m-d H:i:s.uP')))
            ->when($top !== null, fn ($q) => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', (string) $top) ? $q->where('h.top', $top) : $q->whereRaw('false'))
            ->orderByRaw("date_trunc('second', h.changed), h.id");
    }

    /**
     * The unit at the top of each record's hierarchy, by record id, walking up in SQL.
     *
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    public function tops(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return collect(DB::select('WITH RECURSIVE up(start, id, parent) AS (SELECT id, id, parent_id FROM record_resources WHERE id IN ('.implode(', ', array_fill(0, count($ids), '?')).')
            UNION ALL SELECT up.start, p.id, p.parent_id FROM record_resources p JOIN up ON p.id = up.parent) SELECT start, id FROM up WHERE parent IS NULL', array_values($ids)))
            ->pluck('id', 'start')->all();
    }

    /**
     * The collections the home page's carousel shows: each published unit at the top that has a picture the public may
     * see, in itself or in a unit inside it, newest first, with how many published units it holds and the start of its
     * scope and content. The picture is the thumbnail, else the reference copy, of the first such file. Given $units
     * (cards of any level), the same for each of them instead, in their order. In SQL over ids() (v0.0.146): the
     * pictured units are walked up to the unit they belong to, through published units only, and the units held are
     * counted walking down, so nothing loads every published record.
     *
     * @param  Collection<int, RecordResource>|null  $units
     * @return Collection<int, array{unit: RecordResource, file: DigitalFile, count: int, summary: ?string}>
     */
    public function featured(?Collection $units = null): Collection
    {
        if ($units !== null && $units->isEmpty()) {
            return collect();
        }
        $visible = $this->ids('records');
        $pictures = "SELECT i.record_resource_id FROM instantiations i JOIN digital_files f ON f.instantiation_id = i.id JOIN concepts u ON u.id = f.use_id
            WHERE u.notation IN ('thumbnail', 'reference') AND f.mime_type LIKE 'image/%' AND i.deleted_at IS NULL";
        $anchors = $units === null ? 'parent IS NULL' : 'id IN ('.implode(', ', array_fill(0, $units->count(), '?')).')';
        // [pictured unit => the unit it belongs to], walking up through units the public may read.
        $belongs = collect(DB::select('WITH RECURSIVE v AS MATERIALIZED ('.$visible->toSql().'),
            up(start, id, parent) AS (
                SELECT r.id, r.id, r.parent_id FROM record_resources r JOIN v ON v.id = r.id WHERE r.id IN ('.$pictures.')
                UNION ALL
                SELECT up.start, p.id, p.parent_id FROM record_resources p JOIN up ON p.id = up.parent JOIN v ON v.id = p.id WHERE up.'.($units === null ? 'parent IS NOT NULL' : 'id NOT IN ('.implode(', ', array_fill(0, $units->count(), '?')).')').'
            ) SELECT start, id FROM up WHERE '.$anchors, [...$visible->getBindings(), ...($units === null ? [] : [...$units->pluck('id'), ...$units->pluck('id')])]))
            ->mapWithKeys(fn ($row) => [$row->start => $row->id]);
        if ($belongs->isEmpty()) {
            return collect();
        }
        $service = app(InstantiationService::class);
        $found = [];
        DigitalFile::query()->whereHas('use', fn ($q) => $q->whereIn('notation', ['thumbnail', 'reference']))->where('mime_type', 'like', 'image/%')
            ->whereHas('instantiation', fn ($q) => $q->whereIn('record_resource_id', $belongs->keys())->whereNull('deleted_at'))
            ->with('use', 'instantiation.record', 'instantiation.availability')->orderBy('created_at')->get()
            ->sortBy(fn (DigitalFile $f) => $f->use->notation === 'thumbnail' ? 0 : 1)
            ->each(function (DigitalFile $file) use (&$found, $belongs, $service) {
                $key = $belongs[$file->instantiation->record_resource_id];
                if (! isset($found[$key]) && $service->deliverable(null, $file)) {
                    $found[$key] = $file;
                }
            });
        $chosen = $units === null
            ? $this->query('records')->whereIn('e.id', array_keys($found))->with(self::WITH)->orderByDesc('e.created_at')->limit(12)->get()->filter(fn ($u) => $this->view->readable($u))->values()
            : $units->filter(fn ($u) => isset($found[$u->id]))->values();
        if ($chosen->isEmpty()) {
            return collect();
        }
        // How many published units each holds, walking down through units the public may read.
        $inside = collect(DB::select('WITH RECURSIVE v AS MATERIALIZED ('.$visible->toSql().'),
            down(top, id) AS (
                SELECT r.id, r.id FROM record_resources r WHERE r.id IN ('.implode(', ', array_fill(0, $chosen->count(), '?')).')
                UNION ALL
                SELECT down.top, c.id FROM record_resources c JOIN down ON c.parent_id = down.id JOIN v ON v.id = c.id
            ) SELECT top, count(*) - 1 AS n FROM down GROUP BY top', [...$visible->getBindings(), ...$chosen->pluck('id')]))->pluck('n', 'top');

        return $chosen->map(fn ($unit) => ['unit' => $unit, 'file' => $found[$unit->id], 'count' => (int) ($inside[$unit->id] ?? 0),
            'summary' => ($text = $this->view->value($unit, 'scope-and-content')) ? Str::limit(trim(preg_replace('/\s+/', ' ', $text)), 140) : null]);
    }
}
