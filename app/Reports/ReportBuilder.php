<?php

// OpenRiC Workspace - the report builder (roadmap phase 8), after PSIS's ahgReportBuilderPlugin (DataSourceRegistry,
// ColumnDiscovery, ReportBuilderService: executeReportDefinition, resolveColumnValues, cloneReport; TemplateService:
// createFromReport, applyToReport) and Heratio's ReportBuilderController. Its data sources are the kinds of entity, and
// its columns the elements of each kind's standard (ISAD(G), ISAAR(CPF), ISDIAH and the Workspace's own), with the
// fixed fields beside them; PSIS's column discovery over database tables and its SQL query builder have no place here.
// A report becomes a document (sections of text, tables and counts) that each export draws in its own format.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Reports;

use App\Description\Elements;
use App\Models\Concept;
use App\Models\CustomReport;
use App\Models\CustomReportSection;
use App\Models\RecordResource;
use App\Models\ReportTemplate;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ReportBuilder
{
    /** data source (a kind of entity) => the standard whose elements are its columns */
    public const SOURCES = [
        'record' => Elements::ISAD_G, 'agent' => Elements::ISAAR_CPF, 'repository' => Elements::ISDIAH, 'activity' => Elements::ACTIVITY,
        'place' => Elements::PLACE, 'instantiation' => Elements::INSTANTIATION, 'container' => Elements::CONTAINER,
    ];

    /** The heading of each data source, as the menu names it. */
    public const SOURCE_TITLES = ['record' => 'Archival descriptions', 'agent' => 'Authority records', 'repository' => 'Repositories', 'activity' => 'Activities',
        'place' => 'Places', 'instantiation' => 'Instantiations', 'container' => 'Containers'];

    /** fixed column => [label, the sources it applies to (null: every one)] */
    private const FIXED = [
        'name' => ['Name', null],
        'reference-code' => ['Reference code', ['record']],
        'identifier' => ['Identifier', null],
        'description-identifier' => ['Description identifier', ['record', 'agent', 'repository']],
        'level' => ['Level of description', ['record']],
        'repository' => ['Repository', ['record']],
        'type' => ['Type', ['agent', 'activity', 'place', 'container']],
        'published' => ['Published', null],
        'created' => ['Created', null],
        'updated' => ['Updated', null],
        'iri' => ['IRI', null],
    ];

    /** What a counts section counts by: key => [label, the sources it applies to (null: every one)] */
    public const COUNT_BY = [
        'level' => ['Level of description', ['record']],
        'repository' => ['Repository', ['record']],
        'type' => ['Type', ['agent', 'activity', 'place', 'container']],
        'published' => ['Published', null],
        'year' => ['Year created', null],
    ];

    /** The type relation of each source that has one. */
    private const TYPES = ['agent' => 'agentType', 'activity' => 'activityType', 'place' => 'placeType', 'container' => 'containerType'];

    /** ponytail: a report reads at most this many entities into memory; past it the exports say they are cut short. */
    public const ROW_LIMIT = 10000;

    public const STATUSES = Vocab::BASE.'report-status/';

    public const SECTION_TYPES = Vocab::BASE.'report-section-type/';

    public function __construct(private ReportService $reports) {}

    /** Whether an account may build reports on, and read reports of, a data source. */
    public static function mayUse(User $user, string $source): bool
    {
        $kind = EntityKinds::get($source);

        return isset(self::SOURCES[$source]) && (! $kind['readable'] || $user->mayAnywhere('read', $kind['permission']));
    }

    /** @return array<string, string> the columns a source offers: key => label, the fixed ones first, then the elements of its standard */
    public function columns(string $source): array
    {
        $columns = [];
        foreach (self::FIXED as $key => [$label, $sources]) {
            if ($sources === null || in_array($source, $sources, true)) {
                $columns[$key] = __($label);
            }
        }
        $lang = Vocab::languages();
        foreach (Elements::of(self::SOURCES[$source]) as $element) {
            // The element that names the entity is the Name column.
            if ($element->notation !== EntityKinds::get($source)['label']) {
                $columns['element:'.$element->notation] = trim(Elements::number($element).' '.$element->label($lang));
            }
        }

        return $columns;
    }

    /** @return array<string, string> what a counts section of a source may count by */
    public function countBy(string $source): array
    {
        return collect(self::COUNT_BY)->filter(fn ($d) => $d[1] === null || in_array($source, $d[1], true))->map(fn ($d) => __($d[0]))->all();
    }

    /** The validation rules of a report's definition, for a source. */
    public function rules(string $source): array
    {
        return [
            'name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'],
            'columns' => ['required', 'array', 'min:1'], 'columns.*' => ['string', 'in:'.implode(',', array_keys($this->columns($source)))],
            'filters.from' => ['nullable', 'date_format:Y-m-d'], 'filters.to' => ['nullable', 'date_format:Y-m-d'], 'filters.of' => ['nullable', 'in:created,updated'],
            'filters.published' => ['nullable', 'in:yes,no'], 'filters.level' => ['nullable', 'uuid'], 'filters.type' => ['nullable', 'uuid'], 'filters.repository' => ['nullable', 'uuid'],
            'sort.by' => ['nullable', 'in:name,created,updated'], 'sort.direction' => ['nullable', 'in:asc,desc'], 'shared' => ['nullable', 'boolean'],
        ];
    }

    /** A new report, from a template's structure or from nothing. */
    public function create(User $user, array $data, ?ReportTemplate $template = null): CustomReport
    {
        $structure = $template?->structure ?? [];
        $source = $structure['source'] ?? $data['source'];
        if (! self::mayUse($user, $source)) {
            throw new InvalidArgumentException(__('You may not build reports on :source.', ['source' => __(self::SOURCE_TITLES[$source] ?? $source)]));
        }

        return DB::transaction(function () use ($user, $data, $structure, $source, $template) {
            $report = CustomReport::create([
                'institution_id' => $user->institution_id, 'user_id' => $user->id, 'name' => $data['name'], 'description' => $data['description'] ?? $template?->description,
                'source' => $source, 'columns' => $structure['columns'] ?? ['name', 'published'], 'filters' => $structure['filters'] ?? [], 'sort' => $structure['sort'] ?? ['by' => 'name'],
                'status_id' => $this->status('draft'), 'shared' => (bool) ($data['shared'] ?? false), 'template_id' => $template?->id,
            ]);
            foreach ($structure['sections'] ?? [['type' => 'summary', 'title' => __('Summary')], ['type' => 'table', 'title' => __(self::SOURCE_TITLES[$source])]] as $i => $section) {
                $this->addSection($report, $section['type'], $section + ['position' => $i]);
            }

            return $report;
        });
    }

    /** A copy of a report, owned by the account that copies it, as a draft (PSIS's cloneReport). */
    public function copy(CustomReport $report, User $user): CustomReport
    {
        return DB::transaction(function () use ($report, $user) {
            $copy = $report->replicate(['status_id', 'user_id', 'shared'])->fill(['name' => __('Copy of :name', ['name' => $report->name]), 'user_id' => $user->id,
                'status_id' => $this->status('draft'), 'shared' => false]);
            $copy->save();
            foreach ($report->sections as $section) {
                $copy->sections()->create($section->only(['type_id', 'title', 'content', 'config', 'position']));
            }

            return $copy;
        });
    }

    /** A template made from a report's structure, for the institution (PSIS's createFromReport). */
    public function saveAsTemplate(CustomReport $report, string $name, User $user): ReportTemplate
    {
        return ReportTemplate::create(['institution_id' => $report->institution_id, 'name' => $name, 'description' => $report->description, 'created_by_id' => $user->id,
            'structure' => ['source' => $report->source, 'columns' => $report->columns, 'filters' => $report->filters, 'sort' => $report->sort,
                'sections' => $report->sections()->with('type')->get()->map(fn ($s) => ['type' => $s->type->notation, 'title' => $s->title, 'content' => $s->content, 'config' => $s->config ?: (object) []])->all()]]);
    }

    public function addSection(CustomReport $report, string $type, array $fields): CustomReportSection
    {
        $typeId = Concept::where('iri', self::SECTION_TYPES.$type)->value('id') ?? throw new InvalidArgumentException("$type is not a type of report section");
        $config = array_intersect_key($fields['config'] ?? [], ['by' => true]);
        if ($type === 'counts' && ! array_key_exists($config['by'] ?? '', $this->countBy($report->source))) {
            throw new InvalidArgumentException(__('Choose what to count by.'));
        }

        return $report->sections()->create(['type_id' => $typeId, 'title' => $fields['title'] ?? null, 'content' => $fields['content'] ?? null, 'config' => $config,
            'position' => $fields['position'] ?? (int) $report->sections()->max('position') + 1]);
    }

    /** Move a section one place up or down. */
    public function move(CustomReportSection $section, string $direction): void
    {
        DB::transaction(function () use ($section, $direction) {
            $sections = $section->report->sections()->get()->values();
            $at = $sections->search(fn ($s) => $s->id === $section->id);
            $to = $direction === 'up' ? $at - 1 : $at + 1;
            if (! isset($sections[$to])) {
                return;
            }
            [$sections[$at], $sections[$to]] = [$sections[$to], $sections[$at]];
            $sections->each(fn ($s, $i) => $s->position === $i ?: $s->update(['position' => $i]));
        });
    }

    /** The entities the report covers, filtered and sorted, at most ROW_LIMIT of them. */
    public function entities(CustomReport $report): Collection
    {
        $filters = $report->filters ?? [];
        $of = ($filters['of'] ?? '') === 'updated' ? 'updated_at' : 'created_at';
        $with = [...EntityKinds::get($report->source)['with'], 'names.state', 'dates.property'];
        $with = [...$with, ...match ($report->source) {
            'record' => ['level.labels', 'repository.names.nameType', 'repository.names.state', 'repository.descriptionValues.element', 'repository.identifiers.identifierType', 'parent'],
            default => isset(self::TYPES[$report->source]) ? [self::TYPES[$report->source].'.labels'] : [],
        }];
        $query = $this->reports->live($report->source, $report->institution_id)
            ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->where($of, '>=', $from.' 00:00:00'))
            ->when($filters['to'] ?? null, fn (Builder $q, $to) => $q->where($of, '<=', $to.' 23:59:59'))
            ->when($filters['published'] ?? null, fn (Builder $q, $yes) => $yes === 'yes' ? $q->whereIn('id', $this->reports->published($report->source)) : $q->whereNotIn('id', $this->reports->published($report->source)))
            ->when(($filters['level'] ?? null) && $report->source === 'record', fn (Builder $q) => $q->where('level_id', $filters['level']))
            ->when(($filters['repository'] ?? null) && $report->source === 'record', fn (Builder $q) => $q->where('repository_id', $filters['repository']))
            ->when(($filters['type'] ?? null) && isset(self::TYPES[$report->source]), fn (Builder $q) => $q->where($report->source.'_type_id', $filters['type']))
            ->with($with)->limit(self::ROW_LIMIT + 1);
        $by = $report->sort['by'] ?? 'name';
        $desc = ($report->sort['direction'] ?? 'asc') === 'desc';
        $entities = $by === 'name' ? $query->get()->sortBy(fn ($e) => mb_strtolower((string) EntityKinds::label($e)), SORT_NATURAL, $desc)->values()
            : $query->orderBy($by.'_at', $desc ? 'desc' : 'asc')->orderBy('id')->get();

        return $entities;
    }

    /** One cell: the value of a column for an entity, as text. */
    public function cell(Model $entity, string $column): string
    {
        $lang = Vocab::languages();
        if (str_starts_with($column, 'element:')) {
            return (string) $entity->described(substr($column, 8), $lang);
        }
        $type = self::TYPES[EntityKinds::of($entity)] ?? null;

        return match ($column) {
            'name' => (string) EntityKinds::label($entity),
            'reference-code' => $entity instanceof RecordResource ? (string) $entity->referenceCode() : '',
            'identifier' => (string) $entity->identifier,
            'description-identifier' => (string) $entity->description_identifier,
            'level' => (string) $entity->level?->label($lang),
            'repository' => $entity instanceof RecordResource ? (string) EntityKinds::label($entity->repository) : '',
            'type' => $type ? (string) $entity->{$type}?->label($lang) : '',
            'published' => $this->reports->isPublished($entity) ? __('Yes') : __('No'),
            'created' => $entity->created_at?->format('Y-m-d H:i') ?? '',
            'updated' => $entity->updated_at?->format('Y-m-d H:i') ?? '',
            'iri' => (string) $entity->iri,
            default => '',
        };
    }

    /**
     * The report as a document: its title and description, and each section with what it shows: summary (label and value
     * pairs), table (headings and rows), counts (headings and rows) or text (paragraphs).
     */
    public function document(CustomReport $report): array
    {
        $entities = $this->entities($report);
        $cut = $entities->count() > self::ROW_LIMIT;
        $entities = $entities->take(self::ROW_LIMIT);
        // The columns chosen, in the order chosen; one the standard no longer has is left out.
        $offered = $this->columns($report->source);
        $columns = collect($report->columns)->filter(fn ($c) => isset($offered[$c]))->mapWithKeys(fn ($c) => [$c => $offered[$c]])->all();

        $sections = $report->sections()->with('type')->get()->map(function (CustomReportSection $section) use ($report, $entities, $columns) {
            $type = $section->type->notation;
            $base = ['type' => $type, 'title' => (string) $section->title];

            return $base + match ($type) {
                'summary' => ['rows' => array_values(array_filter([
                    [__(self::SOURCE_TITLES[$report->source]), (string) $entities->count()],
                    [__('Published'), (string) $entities->filter(fn ($e) => $this->reports->isPublished($e))->count()],
                    $report->source === 'record' ? [__('With a digital file'), (string) DB::table('instantiations as i')->join('digital_files as f', 'f.instantiation_id', '=', 'i.id')
                        ->whereNull('i.deleted_at')->whereIn('i.record_resource_id', $entities->pluck('id'))->distinct()->count('i.record_resource_id')] : null,
                ]))],
                'table' => ['headings' => array_values($columns), 'rows' => $entities->map(fn ($e) => array_map(fn ($c) => $this->cell($e, $c), array_keys($columns)))->all()],
                'counts' => ['headings' => [$this->countBy($report->source)[$section->config['by'] ?? ''] ?? '', __('Count')], 'rows' => $this->counts($entities, $section->config['by'] ?? '')],
                'text' => ['paragraphs' => array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', (string) $section->content))))],
                default => [],
            };
        })->all();

        return ['title' => $report->name, 'description' => (string) $report->description, 'source' => __(self::SOURCE_TITLES[$report->source]),
            'generated' => now()->format('Y-m-d H:i'), 'status' => $report->status->label(Vocab::languages()), 'cut' => $cut, 'sections' => $sections];
    }

    /** @return list<array{0: string, 1: string}> how many entities have each value of what is counted, most first */
    private function counts(Collection $entities, string $by): array
    {
        $value = fn (Model $e) => match ($by) {
            'level' => $this->cell($e, 'level'),
            'repository' => $this->cell($e, 'repository'),
            'type' => $this->cell($e, 'type'),
            'published' => $this->cell($e, 'published'),
            'year' => $e->created_at?->format('Y') ?? '',
            default => '',
        };

        return $entities->countBy(fn ($e) => $value($e) !== '' ? $value($e) : __('None'))->sortDesc()
            ->map(fn ($n, $label) => [(string) $label, (string) $n])->values()->all();
    }

    private function status(string $notation): string
    {
        return Concept::where('iri', self::STATUSES.$notation)->value('id');
    }
}
