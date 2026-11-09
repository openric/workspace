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
use App\Rights\Embargoes;
use App\Support\Vocab;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
     * (or, with $prefix, whose name starts with it).
     * ponytail: visibility is decided entity by entity in PHP; past some tens of thousands of entities it wants deciding in SQL.
     */
    public function visible(string $kind, string $query = '', bool $prefix = false, array $with = []): Collection
    {
        $class = self::KINDS[$kind];
        $table = (new $class)->getTable();
        $like = ($prefix ? '' : '%').addcslashes($query, '%_\\').'%';
        $published = Name::where('subject_table', $table)->whereHas('state', fn ($q) => $q->where('iri', Vocab::valueState('published')))
            ->whereHas('nameType', fn ($q) => $q->where('iri', Vocab::NAME_TYPES.'authorised'));

        return $class::where('institution_id', $this->institution->id)->whereNull('deleted_at')
            ->whereIn('id', (clone $published)->select('subject_id'))
            ->when($query !== '', fn (Builder $q) => $q->where(fn ($q) => $q
                // Any published name finds it, not only the authorised one: a place's or a body's other and former names
                // are names in their own right (RiC-CM 1.0.1 Name; design review of 2 November 2026, scenario A).
                ->whereIn('id', Name::where('subject_table', $table)->whereHas('state', fn ($q) => $q->where('iri', Vocab::valueState('published')))
                    ->where('value', 'ilike', $like)->select('subject_id'))
                ->when(! $prefix, fn ($q) => $q->orWhereIn('id', DB::table('identifiers')->where('subject_table', $table)->where('value', 'ilike', $like)->select('subject_id')))))
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
     * The collections the home page's carousel shows: each published unit at the top that has a picture the public may
     * see, in itself or in a unit inside it, newest first, with how many published units it holds and the start of its
     * scope and content. The picture is the thumbnail, else the reference copy, of the first such file.
     *
     * @param  Collection<int, RecordResource>|null  $records  every published unit, when the caller has them already
     * @return Collection<int, array{unit: RecordResource, file: DigitalFile, count: int, summary: ?string}>
     */
    public function featured(?Collection $records = null): Collection
    {
        $records ??= $this->visible('records');
        $parents = $records->pluck('parent_id', 'id');
        $top = function (string $id) use ($parents): string {
            for ($seen = 0; ($parents[$id] ?? null) !== null && $seen < 100; $seen++) {
                $id = $parents[$id];
            }

            return $id;
        };
        $inside = $records->countBy(fn ($unit) => $top($unit->id));
        $service = app(InstantiationService::class);
        $pictures = [];
        DigitalFile::query()->whereHas('use', fn ($q) => $q->whereIn('notation', ['thumbnail', 'reference']))->where('mime_type', 'like', 'image/%')
            ->whereHas('instantiation', fn ($q) => $q->whereIn('record_resource_id', $parents->keys())->whereNull('deleted_at'))
            ->with('use', 'instantiation.record', 'instantiation.availability')->orderBy('created_at')->get()
            ->sortBy(fn (DigitalFile $f) => $f->use->notation === 'thumbnail' ? 0 : 1)
            ->each(function (DigitalFile $file) use (&$pictures, $top, $service) {
                $key = $top($file->instantiation->record_resource_id);
                if (! isset($pictures[$key]) && $service->deliverable(null, $file)) {
                    $pictures[$key] = $file;
                }
            });

        return $records->whereNull('parent_id')->filter(fn ($unit) => isset($pictures[$unit->id]))->sortByDesc('created_at')->take(12)->values()
            ->map(fn ($unit) => ['unit' => $unit, 'file' => $pictures[$unit->id], 'count' => max(0, ($inside[$unit->id] ?? 1) - 1),
                'summary' => ($text = $this->view->value($unit, 'scope-and-content')) ? Str::limit(trim(preg_replace('/\s+/', ' ', $text)), 140) : null]);
    }
}
