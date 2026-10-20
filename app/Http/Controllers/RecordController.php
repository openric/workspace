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
use App\Description\RecordService;
use App\Models\Concept;
use App\Models\Name;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Relations\RelationService;
use App\Support\Vocab;
use Illuminate\Database\QueryException;
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
                'repository.identifiers.identifierType', 'parent.names.nameType', 'parent.descriptionValues.element')
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
        $units->setCollection($units->getCollection()->filter(fn (RecordResource $r) => $user->may('read', 'description', $r))->values());

        return view('records.index', ['units' => $units, 'query' => $query, 'filters' => $filters, 'levels' => $this->concepts(self::LEVELS),
            'repositories' => $this->repositories($request)]);
    }

    /** The whole hierarchy the unit belongs to, from its top unit down. */
    public function tree(Request $request, RecordResource $record): View
    {
        $this->own($request, $record);
        $top = $record->ancestors()[0] ?? $record;

        return view('records.tree', ['record' => $record, 'top' => $top, 'byParent' => $this->hierarchy($request, $top)]);
    }

    /** Every unit inside this one, in order, with its reference code, level, dates and extent. */
    public function inventory(Request $request, RecordResource $record): View
    {
        $this->own($request, $record);

        return view('records.inventory', ['record' => $record, 'rows' => $this->flatten($this->hierarchy($request, $record), $record->id, 1),
            'drafts' => $request->user()->may('view-draft', 'description', $record)]);
    }

    /** The description of the unit and, for a finding aid, every unit inside it, ready to print. */
    public function findingAid(Request $request, RecordResource $record): View
    {
        $this->own($request, $record);
        $withInside = ! $request->routeIs('records.print');
        $units = [[$record, 0], ...($withInside ? $this->flatten($this->hierarchy($request, $record), $record->id, 1) : [])];

        return view('records.finding-aid', ['record' => $record, 'units' => $units, 'withInside' => $withInside,
            'drafts' => $request->user()->may('view-draft', 'description', $record) && $request->boolean('drafts')]);
    }

    public function calculateDates(Request $request, RecordResource $record): RedirectResponse
    {
        $this->own($request, $record, 'update');
        $edtf = $this->records->calculateDates($record, $request->user());

        return redirect()->route('records.show', $record)->with('status', $edtf
            ? "Creation date set to $edtf, the span of the units inside it."
            : 'No unit inside it has a creation date, so nothing was calculated.');
    }

    public function create(Request $request): View
    {
        $parent = $request->query('parent') ? $this->find($request, (string) $request->query('parent')) : null;
        abort_unless($parent ? $request->user()->may('create', 'description', $parent) : $request->user()->mayAnywhere('create', 'description'), 403);

        return view('records.create', ['parent' => $parent, 'levels' => $this->concepts(self::LEVELS), 'repositories' => $this->repositories($request)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:2000'],
            'language' => ['required', 'string', fn ($a, $v, $fail) => Bcp47::valid((string) $v) || $fail('Give a BCP 47 language tag, such as en or af.')],
            'level' => ['required', Rule::in($this->concepts(self::LEVELS)->pluck('iri'))],
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
        return redirect()->route('records.edit', [$record, 'language' => $data['language']])->with('status', 'Created. Describe it now, or later.');
    }

    public function show(Request $request, RecordResource $record): View
    {
        $this->own($request, $record);
        $user = $request->user();
        $record->load('descriptionValues.element.labels', 'descriptionValues.state.labels', 'names.nameType.labels', 'names.state.labels', 'names.dates.property', 'identifiers.identifierType.labels', 'level.labels', 'repository.descriptionValues.element', 'repository.names.nameType',
            'children.descriptionValues.element', 'children.names.nameType', 'children.identifiers.identifierType', 'children.level.labels', 'dates.property.labels', 'dates.dateType.labels', 'dates.qualifier.labels');

        $record->setRelation('children', $record->children->filter(fn ($child) => $user->may('read', 'description', $child))->values());

        return view('records.show', ['record' => $record, 'elements' => Elements::of(Elements::ISAD_G), 'dateChoices' => app(DateService::class)->choices('record'), 'relations' => app(RelationService::class)->of($record),
            'drafts' => $user->may('view-draft', 'description', $record), 'canUpdate' => $user->may('update', 'description', $record),
            'canCreateInside' => $user->may('create', 'description', $record)]);
    }

    public function edit(Request $request, RecordResource $record): View
    {
        $this->own($request, $record);
        $language = (string) $request->query('language', 'en');
        abort_unless(Bcp47::valid($language), 404);
        $record->load('descriptionValues.element', 'names.nameType', 'identifiers.identifierType', 'level');
        abort_unless($this->mayWrite($request, $record, $language), 403);

        return view('records.edit', ['record' => $record, 'elements' => Elements::of(Elements::ISAD_G), 'levels' => $this->concepts(self::LEVELS), 'language' => $language,
            'mayUpdate' => $request->user()->may('update', 'description', $record),
            'statuses' => Concept::choices(Vocab::DESCRIPTION_STATUSES)->get(), 'details' => Concept::choices(Vocab::DESCRIPTION_DETAILS)->get()]);
    }

    public function update(Request $request, RecordResource $record): RedirectResponse
    {
        $this->own($request, $record);
        $data = $request->validate([
            'language' => ['required', 'string', fn ($a, $v, $fail) => Bcp47::valid((string) $v) || $fail('Give a BCP 47 language tag, such as en or af.')],
            'identifier' => ['nullable', 'string', 'max:255'],
            'level' => ['required', Rule::in($this->concepts(self::LEVELS)->pluck('iri'))],
            'values' => ['array'],
            'values.*' => ['nullable', 'string', 'max:65535'],
            'description_identifier' => ['nullable', 'string', 'max:255'],
            'description_status' => ['nullable', 'string', Rule::in(Concept::choices(Vocab::DESCRIPTION_STATUSES)->pluck('iri'))],
            'description_detail' => ['nullable', 'string', Rule::in(Concept::choices(Vocab::DESCRIPTION_DETAILS)->pluck('iri'))],
        ]);
        $values = array_intersect_key($data['values'] ?? [], array_flip(Elements::notations(Elements::ISAD_G)));
        abort_unless($this->mayWrite($request, $record, $data['language']), 403);
        // A translator writes values only: the reference code and level need update.
        $control = [($data['description_identifier'] ?? null) ?: null, ($data['description_status'] ?? null) ?: null, ($data['description_detail'] ?? null) ?: null];
        $controlChanged = $request->has('description_identifier') && $control !== [$record->description_identifier, $record->descriptionStatus?->iri, $record->descriptionDetail?->iri];
        $identityChanged = ($data['identifier'] ?? null) !== $record->identifier || $data['level'] !== $record->level->iri || $controlChanged;
        abort_if($identityChanged && ! $request->user()->may('update', 'description', $record), 403);
        try {
            DB::transaction(function () use ($record, $data, $values, $request, $identityChanged, $controlChanged, $control) {
                if ($identityChanged) {
                    $this->records->updateIdentity($record, $data['identifier'] ?? null, $data['level'], $request->user());
                }
                if ($controlChanged) {
                    $this->records->updateControl($record, ...[...$control, $request->user()]);
                }
                $this->records->describe($record, $values, $data['language'], $request->user());
            });
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['values' => $e->getMessage()]);
        }

        return redirect()->route('records.show', $record)->with('status', 'Description saved.');
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

        return redirect()->route('records.show', $record)->with('status', 'Moved; its persistent IRI is unchanged.');
    }

    public function reorder(Request $request, RecordResource $record): RedirectResponse
    {
        $this->own($request, $record, 'update');
        $data = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]]);
        $this->records->reorder($record, $data['direction'] === 'up' ? -1 : 1, $request->user());

        return $record->parent_id ? redirect()->route('records.show', $record->parent_id) : redirect()->route('records.index');
    }

    /**
     * Every readable unit below $top, by parent id, in order, with what the pages show loaded.
     * ponytail: the whole hierarchy in memory; a fonds past some tens of thousands of units wants loading by level.
     *
     * @return Collection<string, Collection<int, RecordResource>>
     */
    private function hierarchy(Request $request, RecordResource $top)
    {
        $ids = [$top->id, ...$top->descendantIds()];

        return RecordResource::whereKey($ids)->whereNull('deleted_at')->whereKeyNot($top->id)
            ->with('descriptionValues.element', 'descriptionValues.state', 'names.nameType', 'names.state', 'identifiers.identifierType', 'level.labels', 'dates.property', 'dates.qualifier.labels')
            ->orderBy('position')->orderBy('id')->get()
            ->filter(fn (RecordResource $r) => $request->user()->may('read', 'description', $r))
            ->groupBy('parent_id');
    }

    /** @return list<array{0: RecordResource, 1: int}> the units below $parentId depth first, with their depth */
    private function flatten($byParent, string $parentId, int $depth): array
    {
        $rows = [];
        foreach ($byParent[$parentId] ?? [] as $unit) {
            $rows[] = [$unit, $depth];
            $rows = [...$rows, ...$this->flatten($byParent, $unit->id, $depth + 1)];
        }

        return $rows;
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

    private function concepts(string $vocabularyIri)
    {
        return Concept::current()->whereHas('vocabulary', fn ($q) => $q->where('iri', $vocabularyIri))->with('labels')->orderBy('id')->get();
    }
}
