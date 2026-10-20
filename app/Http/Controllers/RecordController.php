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
use App\Description\Elements;
use App\Description\RecordService;
use App\Models\Concept;
use App\Models\RecordDate;
use App\Models\RecordResource;
use App\Models\Repository;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class RecordController extends Controller
{
    private const LEVELS = 'https://workspace.openric.org/vocab/level-of-description/';

    private const DATE_TYPES = 'https://openric.org/vocab/date-type/';

    public function __construct(private RecordService $records) {}

    public function index(Request $request): View
    {
        $top = RecordResource::where('institution_id', $request->user()->institution_id)->whereNull('parent_id')->whereNull('deleted_at')
            ->with('descriptionValues.element', 'level.labels', 'repository.descriptionValues.element')->orderBy('position')->get()
            ->filter(fn (RecordResource $r) => $request->user()->may('read', 'description', $r));

        return view('records.index', ['groups' => $top->groupBy('repository_id')]);
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
        $record->load('descriptionValues.element.labels', 'descriptionValues.state.labels', 'level.labels', 'repository.descriptionValues.element',
            'children.descriptionValues.element', 'children.level.labels', 'dates.dateType.labels');

        $record->setRelation('children', $record->children->filter(fn ($child) => $user->may('read', 'description', $child))->values());

        return view('records.show', ['record' => $record, 'elements' => Elements::of(Elements::ISAD_G), 'dateTypes' => $this->concepts(self::DATE_TYPES),
            'drafts' => $user->may('view-draft', 'description', $record), 'canUpdate' => $user->may('update', 'description', $record),
            'canCreateInside' => $user->may('create', 'description', $record)]);
    }

    public function edit(Request $request, RecordResource $record): View
    {
        $this->own($request, $record);
        $language = (string) $request->query('language', 'en');
        abort_unless(Bcp47::valid($language), 404);
        $record->load('descriptionValues.element', 'level');
        abort_unless($this->mayWrite($request, $record, $language), 403);

        return view('records.edit', ['record' => $record, 'elements' => Elements::of(Elements::ISAD_G), 'levels' => $this->concepts(self::LEVELS), 'language' => $language]);
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
        ]);
        $values = array_intersect_key($data['values'] ?? [], array_flip(Elements::notations(Elements::ISAD_G)));
        abort_unless($this->mayWrite($request, $record, $data['language']), 403);
        // A translator writes values only: the reference code and level need update.
        $identityChanged = ($data['identifier'] ?? null) !== $record->identifier || $data['level'] !== $record->level->iri;
        abort_if($identityChanged && ! $request->user()->may('update', 'description', $record), 403);
        try {
            DB::transaction(function () use ($record, $data, $values, $request, $identityChanged) {
                if ($identityChanged) {
                    $this->records->updateIdentity($record, $data['identifier'] ?? null, $data['level'], $request->user());
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
            ->with('descriptionValues.element')->get()
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

    public function storeDate(Request $request, RecordResource $record): RedirectResponse
    {
        $this->own($request, $record, 'update');
        $data = $request->validate([
            'date_type' => ['required', Rule::in($this->concepts(self::DATE_TYPES)->pluck('iri'))],
            'begin_date' => ['nullable', 'date_format:Y-m-d', 'required_without:end_date'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:begin_date'],
        ]);
        $this->records->addDate($record, $data['date_type'], $data['begin_date'] ?? null, $data['end_date'] ?? null, $request->user());

        return redirect()->route('records.show', $record)->with('status', 'Date added.');
    }

    public function destroyDate(Request $request, RecordResource $record, RecordDate $date): RedirectResponse
    {
        $this->own($request, $record, 'update');
        abort_unless($date->record_resource_id === $record->id, 404);
        $this->records->removeDate($date, $request->user());

        return redirect()->route('records.show', $record)->with('status', 'Date removed.');
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
        return Repository::where('institution_id', $request->user()->institution_id)->whereNull('deleted_at')->with('descriptionValues.element')->get();
    }

    private function concepts(string $vocabularyIri)
    {
        return Concept::current()->whereHas('vocabulary', fn ($q) => $q->where('iri', $vocabularyIri))->with('labels')->orderBy('id')->get();
    }
}
