<?php

// OpenRiC Workspace - units of description: arrange, describe and date them (ISAD(G), WS-002, WS-004).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Written for the Workspace. Every route needs the describe permission, and
// only the institution's own units exist here.

namespace App\Http\Controllers;

use App\Access\AccessControl;
use App\Description\Bcp47;
use App\Description\DateService;
use App\Description\Elements;
use App\Description\InstantiationService;
use App\Description\RecordService;
use App\Description\TreeService;
use App\Models\Concept;
use App\Models\DigitalFile;
use App\Models\Name;
use App\Models\RecordResource;
use App\Models\RecordWatermark;
use App\Models\Repository;
use App\Models\User;
use App\Relations\RelationService;
use App\Rights\CommunityProtocols;
use App\Support\Vocab;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class RecordController extends Controller
{
    private const LEVELS = 'https://workspace.openric.org/vocab/level-of-description/';

    public function __construct(private RecordService $records) {}

    /**
     * Browse units of description: search titles, other names and identifiers; filter by
     * repository, level and publication; the top level only unless a search is given;
     * sort by title, identifier or last change.
     */
    public function index(Request $request): View
    {
        $user = $request->user();
        $query = trim((string) $request->query('q', ''));
        $filters = ['repository' => (string) $request->query('repository', ''), 'level' => (string) $request->query('level', ''),
            'publication' => (string) $request->query('publication', ''), 'sort' => in_array($request->query('sort'), ['identifier', 'updated'], true) ? $request->query('sort') : 'title',
            'top' => $request->has('top') ? $request->boolean('top') : $query === ''];
        $like = '%'.addcslashes($query, '%_\\').'%';
        $published = Concept::where('iri', Vocab::valueState('published'))->value('id');
        $title = Name::select('value')->whereColumn('subject_id', 'record_resources.id')->where('subject_table', 'record_resources')
            ->whereHas('nameType', fn ($q) => $q->where('iri', Vocab::NAME_TYPES.'authorised'))->orderBy('created_at')->limit(1);
        $units = RecordResource::where('institution_id', $user->institution_id)->whereNull('deleted_at')
            ->with('descriptionValues.element', 'names.nameType', 'identifiers.identifierType', 'level.labels', 'repository.names.nameType', 'repository.descriptionValues.element',
                'repository.identifiers.identifierType', 'parent.names.nameType', 'parent.descriptionValues.element', 'dates.qualifier.labels')
            ->withCount(['instantiations' => fn ($q) => $q->whereNull('deleted_at'), 'children' => fn ($q) => $q->whereNull('deleted_at')])
            ->when($filters['top'], fn ($q) => $q->whereNull('parent_id'))
            ->when($query !== '', fn ($q) => $q->where(fn ($q) => $q
                ->whereIn('id', Name::where('subject_table', 'record_resources')->where('value', 'ilike', $like)->select('subject_id'))
                ->orWhereIn('id', DB::table('identifiers')->where('subject_table', 'record_resources')->where('value', 'ilike', $like)->select('subject_id'))))
            ->when($filters['repository'] !== '', fn ($q) => $q->where('repository_id', $filters['repository']))
            ->when($filters['level'] !== '', fn ($q) => $q->where('level_id', $filters['level']))
            ->when($filters['publication'] === 'published', fn ($q) => $q->whereIn('id', Name::where(['subject_table' => 'record_resources', 'state_id' => $published])->select('subject_id')))
            ->when($filters['publication'] === 'unpublished', fn ($q) => $q->whereNotIn('id', Name::where(['subject_table' => 'record_resources', 'state_id' => $published])->select('subject_id')))
            ->when($filters['sort'] === 'updated', fn ($q) => $q->orderByDesc('updated_at'))
            ->when($filters['sort'] === 'identifier', fn ($q) => $q->orderBy(DB::table('identifiers')->select('value')->whereColumn('subject_id', 'record_resources.id')->where('subject_table', 'record_resources')->limit(1)))
            ->when($filters['sort'] === 'title', fn ($q) => $q->orderBy($title))
            ->paginate(50)->withQueryString();
        // Permissions can be set on a single unit, so the page shows only what the account may read.
        $units->setCollection($units->getCollection()->filter(fn (RecordResource $r) => $user->may('read', 'description', $r) && app(CommunityProtocols::class)->allows($user, $r, 'description'))->values());

        return view('records.index', ['units' => $units, 'query' => $query, 'filters' => $filters, 'levels' => $this->levels($request),
            'repositories' => $this->repositories($request)]);
    }

    /** A branch of the hierarchy tree, the next page of it (Heratio's treeview-data). */
    public function treeChildren(Request $request, RecordResource $record): JsonResponse
    {
        abort_unless($record->institution_id === $request->user()->institution_id && $record->deleted_at === null && $request->user()->may('read', 'description', $record), 404);

        return response()->json((new TreeService($request->user()))->children($record->id, max(0, (int) $request->query('offset', 0))));
    }

    /** The whole hierarchy the unit belongs to, from its top unit down. */
    public function tree(Request $request, RecordResource $record): View
    {
        $this->own($request, $record);
        $top = $record->ancestors()[0] ?? $record;

        return view('records.tree', ['record' => $record, 'top' => $top, 'byParent' => $this->records->hierarchy($top, $request->user())]);
    }

    /** Every unit inside this one, in order, with its reference code, level, dates and extent. */
    public function inventory(Request $request, RecordResource $record): View
    {
        $this->own($request, $record);

        return view('records.inventory', ['record' => $record, 'rows' => RecordService::flatten($this->records->hierarchy($record, $request->user()), $record->id),
            'drafts' => $request->user()->may('view-draft', 'description', $record)]);
    }

    /** The description of the unit and, for a finding aid, every unit inside it, ready to print. */
    public function findingAid(Request $request, RecordResource $record): View
    {
        $this->own($request, $record);
        $withInside = ! $request->routeIs('records.print');
        $units = [[$record, 0], ...($withInside ? RecordService::flatten($this->records->hierarchy($record, $request->user()), $record->id) : [])];

        return view('records.finding-aid', ['record' => $record, 'units' => $units, 'withInside' => $withInside,
            'drafts' => $request->user()->may('view-draft', 'description', $record) && $request->boolean('drafts')]);
    }

    public function calculateDates(Request $request, RecordResource $record): RedirectResponse
    {
        $this->own($request, $record, 'update');
        $edtf = $this->records->calculateDates($record, $request->user());

        return redirect()->route('records.show', $record)->with('status', $edtf
            ? __('Creation date set to :date, the span of the units inside it.', ['date' => $edtf])
            : __('No unit inside it has a creation date, so nothing was calculated.'));
    }

    public function create(Request $request): View
    {
        $parent = $request->query('parent') ? $this->find($request, (string) $request->query('parent')) : null;
        abort_unless($parent ? $request->user()->may('create', 'description', $parent) : $request->user()->mayAnywhere('create', 'description'), 403);

        return view('records.create', ['parent' => $parent, 'levels' => $this->levels($request), 'repositories' => $this->repositories($request)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:2000'],
            'language' => ['required', 'string', fn ($a, $v, $fail) => Bcp47::valid((string) $v) || $fail(__('Give a BCP 47 language tag, such as en or af.'))],
            'level' => ['required', Rule::in($this->levels($request)->pluck('iri'))],
            'identifier' => ['nullable', 'string', 'max:255'],
            'parent' => ['nullable', 'uuid'],
            'repository' => ['nullable', 'uuid'],
        ]);
        $parent = isset($data['parent']) ? $this->find($request, $data['parent']) : null;
        $repository = isset($data['repository']) ? Repository::where('institution_id', $request->user()->institution_id)->find($data['repository']) : null;
        abort_unless($request->user()->may('create', 'description', $parent ?? $repository), 403);
        try {
            $record = $this->records->create($request->user()->institution, $data['level'], $data['title'], $data['language'], $parent, $repository, $data['identifier'] ?? null, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['repository' => $e->getMessage()]);
        }

        // Straight to the full description, so every ISAD(G) element is in view.
        return redirect()->route('records.edit', [$record, 'language' => $data['language']])->with('status', __('Created. Describe it now, or later.'));
    }

    public function show(Request $request, RecordResource $record): View
    {
        $this->own($request, $record);
        $user = $request->user();
        $record->load('descriptionValues.element.labels', 'descriptionValues.state.labels', 'names.nameType.labels', 'names.state.labels', 'names.dates.property', 'identifiers.identifierType.labels', 'level.labels', 'repository.descriptionValues.element', 'repository.names.nameType',
            'children.descriptionValues.element', 'children.names.nameType', 'children.identifiers.identifierType', 'children.level.labels', 'dates.property.labels', 'dates.dateType.labels', 'dates.qualifier.labels',
            'instantiations.files.use', 'instantiations.names.nameType', 'instantiations.carrierType.labels', 'instantiations.availability.labels', 'displayStandard', 'resourceTypes.type.labels');

        $record->setRelation('children', $record->children->filter(fn ($child) => $user->may('read', 'description', $child))->values());

        return view('records.show', ['record' => $record, 'elements' => Elements::of($record->standard()), 'dateChoices' => app(DateService::class)->choices('record'), 'relations' => app(RelationService::class)->of($record),
            'drafts' => $user->may('view-draft', 'description', $record), 'canUpdate' => $user->may('update', 'description', $record),
            'canCreateInside' => $user->may('create', 'description', $record), 'childThumbnails' => $this->childThumbnails($record, $user),
            'tree' => (new TreeService($user))->window($record)]);
    }

    /**
     * Heratio's imageflow strip: a thumbnail for each unit inside this one, at any depth, that has one this account may
     * receive, linking to that unit; at most 24, with how many there are.
     *
     * @return array{tiles: Collection<int, array{unit: RecordResource, file: DigitalFile}>, total: int}
     */
    private function childThumbnails(RecordResource $record, User $user): array
    {
        // "View children as compound?" No: the units inside are reached one by one.
        if (! $record->display_as_compound) {
            return ['tiles' => collect(), 'total' => 0];
        }
        $ids = collect(DB::select('WITH RECURSIVE inside AS (SELECT id FROM record_resources WHERE parent_id = ? AND deleted_at IS NULL
            UNION ALL SELECT r.id FROM record_resources r JOIN inside ON r.parent_id = inside.id WHERE r.deleted_at IS NULL) SELECT id FROM inside', [$record->id]))->pluck('id');
        if ($ids->isEmpty()) {
            return ['tiles' => collect(), 'total' => 0];
        }
        $service = app(InstantiationService::class);
        $found = [];
        DigitalFile::query()->whereHas('use', fn ($q) => $q->where('notation', 'thumbnail'))
            ->whereHas('instantiation', fn ($q) => $q->whereIn('record_resource_id', $ids)->whereNull('deleted_at'))
            ->with('use', 'instantiation.record.names.nameType', 'instantiation.availability')->orderBy('created_at')->get()
            ->each(function ($file) use (&$found, $service, $user) {
                $owner = $file->instantiation->record_resource_id;
                if (! isset($found[$owner]) && $service->deliverable($user, $file)) {
                    $found[$owner] = ['unit' => $file->instantiation->record, 'file' => $file];
                }
            });

        return ['tiles' => collect(array_values($found))->take(24), 'total' => count($found)];
    }

    public function edit(Request $request, RecordResource $record): View
    {
        $this->own($request, $record);
        $language = (string) $request->query('language', 'en');
        abort_unless(Bcp47::valid($language), 404);
        $record->load('descriptionValues.element', 'names.nameType', 'identifiers.identifierType', 'level', 'displayStandard', 'resourceTypes.type');
        abort_unless($this->mayWrite($request, $record, $language), 403);
        $typeList = RecordService::TYPE_LISTS[$record->standard()] ?? null;

        return view('records.edit', ['record' => $record, 'elements' => Elements::of($record->standard()),
            'standards' => Concept::choices(Vocab::BASE.'display-standard/')->with('labels')->get(),
            'typeChoices' => $typeList ? Concept::choices(Vocab::BASE.$typeList.'/')->with('labels')->get() : collect(), 'levels' => $this->levels($request), 'language' => $language,
            'mayUpdate' => $request->user()->may('update', 'description', $record), 'mayCreateInside' => $request->user()->may('create', 'description', $record),
            'statuses' => Concept::choices(Vocab::DESCRIPTION_STATUSES)->get(), 'details' => Concept::choices(Vocab::DESCRIPTION_DETAILS)->get(),
            'watermark' => RecordWatermark::where('record_resource_id', $record->id)->with('watermarkType', 'watermarkPosition')->first(),
            'watermarkTypes' => Concept::choices(Vocab::BASE.'watermark-type/')->with('labels')->get(), 'positions' => Concept::choices(Vocab::BASE.'watermark-position/')->with('labels')->get()]);
    }

    public function update(Request $request, RecordResource $record): RedirectResponse
    {
        $this->own($request, $record);
        $data = $request->validate([
            'language' => ['required', 'string', fn ($a, $v, $fail) => Bcp47::valid((string) $v) || $fail(__('Give a BCP 47 language tag, such as en or af.'))],
            'identifier' => ['nullable', 'string', 'max:255'],
            'level' => ['required', Rule::in($this->levels($request)->pluck('iri'))],
            'values' => ['array'],
            'values.*' => ['nullable', 'max:65535'], 'values.*.*' => ['nullable', 'string', 'max:8'],
            'description_identifier' => ['nullable', 'string', 'max:255'],
            'description_status' => ['nullable', 'string', Rule::in(Concept::choices(Vocab::DESCRIPTION_STATUSES)->pluck('iri'))],
            'description_detail' => ['nullable', 'string', Rule::in(Concept::choices(Vocab::DESCRIPTION_DETAILS)->pluck('iri'))],
            'source_standard' => ['nullable', 'string', 'max:255'],
            // Heratio's display standard, and "Make this selection the new default for existing children".
            'display_standard' => ['nullable', 'string', Rule::in(Concept::choices(Vocab::BASE.'display-standard/')->pluck('notation'))],
            'display_standard_inside' => ['boolean'],
            'types' => ['array'], 'types.*' => ['string', 'max:64'],
            // Heratio's watermark for one object: the institution's (none chosen), none, or a type of its own.
            'watermark' => ['nullable', 'string', Rule::in(['none', ...Concept::choices(Vocab::BASE.'watermark-type/')->pluck('notation')])],
            'watermark_position' => ['nullable', 'string', Rule::in(Concept::choices(Vocab::BASE.'watermark-position/')->pluck('notation'))],
            'watermark_opacity' => ['nullable', 'integer', 'between:1,100'],
            'remake_copies' => ['boolean'],
            'display_as_compound' => ['boolean'],
            // Heratio's "Add new child levels": a row with a title becomes a unit inside this one; its level is required here.
            'child_levels' => ['array', 'max:20'],
            'child_levels.*.identifier' => ['nullable', 'string', 'max:255'],
            'child_levels.*.level' => ['nullable', 'required_with:child_levels.*.title', Rule::in($this->levels($request)->pluck('iri'))],
            'child_levels.*.title' => ['nullable', 'string', 'max:2000'],
        ], ['child_levels.*.level.required_with' => __('Choose the level of each new child level.')]);
        $children = array_values(array_filter($data['child_levels'] ?? [], fn ($row) => trim((string) ($row['title'] ?? '')) !== ''));
        abort_if($children !== [] && ! $request->user()->may('create', 'description', $record), 403);
        $values = array_intersect_key($data['values'] ?? [], array_flip([...Elements::notations(Elements::ISAD_G), ...Elements::notations(Elements::OTHER_STANDARDS)]));
        abort_unless($this->mayWrite($request, $record, $data['language']), 403);
        // A translator writes values only: the reference code and level need update.
        $control = [($data['description_identifier'] ?? null) ?: null, ($data['description_status'] ?? null) ?: null, ($data['description_detail'] ?? null) ?: null];
        $controlChanged = $request->has('description_identifier') && $control !== [$record->description_identifier, $record->descriptionStatus?->iri, $record->descriptionDetail?->iri];
        $own = RecordWatermark::where('record_resource_id', $record->id)->with('watermarkType', 'watermarkPosition')->first();
        $watermark = [($data['watermark'] ?? null) ?: null, ($data['watermark_position'] ?? null) ?: null, isset($data['watermark_opacity']) ? (int) $data['watermark_opacity'] : null];
        $watermarkChanged = $request->has('watermark') && $watermark !== [$own ? ($own->enabled ? $own->watermarkType?->notation : 'none') : null, $own?->watermarkPosition?->notation, $own?->opacity];
        $compoundChanged = $request->has('display_as_compound') && $request->boolean('display_as_compound') !== (bool) $record->display_as_compound;
        $standardChanged = $request->has('display_standard') && ((($data['display_standard'] ?? null) ?: null) !== $record->displayStandard?->notation || $request->boolean('display_standard_inside'));
        $typeList = RecordService::TYPE_LISTS[$record->standard()] ?? null;
        $typesChanged = $typeList !== null && $request->has('types_shown')
            && collect($data['types'] ?? [])->sort()->values()->all() !== $record->resourceTypes()->whereHas('type.vocabulary', fn ($q) => $q->where('iri', Vocab::BASE.$typeList.'/'))->with('type')->get()->pluck('type.notation')->sort()->values()->all();
        $sourceChanged = $request->has('source_standard') && (trim((string) ($data['source_standard'] ?? '')) ?: null) !== $record->source_standard;
        $identityChanged = ($data['identifier'] ?? null) !== $record->identifier || $data['level'] !== $record->level->iri || $controlChanged || $sourceChanged || $watermarkChanged || $compoundChanged || $standardChanged || $typesChanged || $request->boolean('remake_copies');
        abort_if($identityChanged && ! $request->user()->may('update', 'description', $record), 403);
        try {
            DB::transaction(function () use ($record, $data, $values, $request, $identityChanged, $controlChanged, $control, $children, $sourceChanged, $watermarkChanged, $watermark, $compoundChanged, $standardChanged, $typesChanged, $typeList) {
                if ($identityChanged) {
                    $this->records->updateIdentity($record, $data['identifier'] ?? null, $data['level'], $request->user());
                }
                if ($controlChanged) {
                    $this->records->updateControl($record, ...[...$control, $request->user()]);
                }
                if ($watermarkChanged) {
                    // A position or opacity without a watermark of its own has nothing to apply to.
                    $this->records->setWatermark($record, $watermark[0], $watermark[0] ? $watermark[1] : null, $watermark[0] ? $watermark[2] : null, $request->user());
                }
                if ($compoundChanged) {
                    $this->records->setDisplayAsCompound($record, $request->boolean('display_as_compound'), $request->user());
                }
                if ($sourceChanged) {
                    $this->records->setSourceStandard($record, $data['source_standard'] ?? null, $request->user());
                }
                if ($typesChanged) {
                    $this->records->setResourceTypes($record, $typeList, $data['types'] ?? [], $request->user());
                }
                if ($standardChanged) {
                    $this->records->setDisplayStandard($record, $data['display_standard'] ?? null, $request->boolean('display_standard_inside'), $request->user());
                }
                $this->records->describe($record, $values, $data['language'], $request->user());
                foreach ($children as $row) {
                    $this->records->create($request->user()->institution, $row['level'], trim($row['title']), $data['language'], $record, null, ($row['identifier'] ?? null) ?: null, $request->user());
                }
            });
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['values' => $e->getMessage()]);
        }

        if ($request->boolean('remake_copies')) {
            foreach ($record->instantiations()->with('files.use')->get()->filter(fn ($i) => str_starts_with((string) $i->file('master')?->mime_type, 'image/')) as $instantiation) {
                app(InstantiationService::class)->queueRemake($instantiation, $request->user());
            }
        }

        return redirect()->route('records.show', $record)->with('status', __('Description saved.'));
    }

    public function moveForm(Request $request, RecordResource $record): View
    {
        $this->own($request, $record, 'update');
        $excluded = [$record->id, ...$record->descendantIds()];
        $candidates = RecordResource::where('institution_id', $record->institution_id)->whereNull('deleted_at')->whereNotIn('id', $excluded)
            ->with('descriptionValues.element', 'names.nameType', 'identifiers.identifierType')->get()
            ->filter(fn ($r) => $request->user()->may('create', 'description', $r))
            ->sortBy(fn ($r) => mb_strtolower((string) $r->described('title', ['en'])));

        return view('records.move', ['record' => $record, 'candidates' => $candidates]);
    }

    public function move(Request $request, RecordResource $record): RedirectResponse
    {
        $this->own($request, $record, 'update');
        $data = $request->validate(['parent' => ['nullable', 'uuid']]);
        $parent = isset($data['parent']) ? $this->find($request, $data['parent']) : null;
        abort_unless($request->user()->may('create', 'description', $parent ?? $record->repository), 403);
        try {
            $this->records->move($record, $parent, $request->user());
        } catch (QueryException $e) {
            // The database refuses a cycle; the form never offers one, so this is a stale page.
            return back()->withErrors(['parent' => 'It cannot move under itself or one of its own descendants.']);
        }

        return redirect()->route('records.show', $record)->with('status', __('Moved; its persistent IRI is unchanged.'));
    }

    public function reorder(Request $request, RecordResource $record): RedirectResponse
    {
        $this->own($request, $record, 'update');
        $data = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]]);
        $this->records->reorder($record, $data['direction'] === 'up' ? -1 : 1, $request->user());

        return $record->parent_id ? redirect()->route('records.show', $record->parent_id) : redirect()->route('records.index');
    }

    private function find(Request $request, string $id): RecordResource
    {
        $record = RecordResource::find($id);
        abort_unless($record !== null, 404);
        $this->own($request, $record);

        return $record;
    }

    /** Another institution's unit does not exist here (404); without the permission, 403 (ADR 0005). */
    private function own(Request $request, RecordResource $record, string $action = 'read'): void
    {
        abort_unless($record->institution_id === $request->user()->institution_id && $record->deleted_at === null, 404);
        abort_unless($request->user()->may($action, 'description', $record), 403);
        // A record a community protocol withholds is not there for who may not change it (Heratio's TermProtocolGate).
        abort_unless(app(CommunityProtocols::class)->allows($request->user(), $record, 'description'), 404);
    }

    /** Writing a language: update, or translate in one of the account's translation languages (ADR 0005). */
    private function mayWrite(Request $request, RecordResource $record, string $language): bool
    {
        return app(AccessControl::class)->allowsLanguage($request->user(), 'description', $record, $language, $record->originalLanguage('title'));
    }

    private function repositories(Request $request)
    {
        return Repository::where('institution_id', $request->user()->institution_id)->whereNull('deleted_at')->with('descriptionValues.element', 'names.nameType', 'identifiers.identifierType')->get();
    }

    /** The levels a unit may have: the bundled ones, then the institution's own. */
    private function levels(Request $request)
    {
        return Concept::bundledThenLocal(self::LEVELS, 'level-of-description', $request->user()->institution_id);
    }
}
