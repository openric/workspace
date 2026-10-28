<?php

// OpenRiC Workspace - places: browse, create, view, describe, arrange and delete (rico:Place; ADR 0006).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Any account of the institution reads its places, as Heratio's place
// browse is open; creating, changing and deleting need the vocabulary
// permissions, as Heratio governs places with its term permissions.

namespace App\Http\Controllers;

use App\Description\Bcp47;
use App\Description\DateService;
use App\Description\Elements;
use App\Description\PlaceService;
use App\Description\StorageService;
use App\Models\Concept;
use App\Models\Name;
use App\Models\Place;
use App\Relations\RelationService;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;

class PlaceController extends Controller
{
    public function __construct(private PlaceService $places) {}

    /** Search by name or identifier, filter by place type; or the whole hierarchy (view=tree). */
    public function index(Request $request): View
    {
        $institution = $request->user()->institution_id;
        $query = trim((string) $request->query('q', ''));
        $type = (string) $request->query('type', '');
        $tree = $request->query('view') === 'tree';
        $with = ['names.nameType', 'placeType.labels', 'identifiers.identifierType', 'parent.names.nameType'];
        if ($tree) {
            // ponytail: the whole hierarchy in one page; past some thousands of places this wants lazy loading by level.
            $all = app(StorageService::class)->scopeStorage($this->existing($institution), $institution, false)->with($with)->get();
            $byParent = $all->groupBy(fn (Place $p) => $p->parent_id ?? 'top');

            return view('places.index', ['tree' => $byParent, 'places' => null, 'query' => '', 'type' => '', 'types' => $this->types($institution)]);
        }
        $like = '%'.addcslashes($query, '%_\\').'%';
        $places = app(StorageService::class)->scopeStorage($this->existing($institution), $institution, false)->with($with)
            ->when($query !== '', fn (Builder $q) => $q->where(fn ($q) => $q
                ->whereIn('id', Name::where('subject_table', 'places')->where('value', 'ilike', $like)->select('subject_id'))
                ->orWhereIn('id', DB::table('identifiers')->where('subject_table', 'places')->where('value', 'ilike', $like)->select('subject_id'))))
            ->when($type !== '', fn (Builder $q) => $q->where('place_type_id', $type))
            ->orderBy(Name::select('value')->whereColumn('subject_id', 'places.id')->where('subject_table', 'places')
                ->whereHas('nameType', fn ($q) => $q->where('iri', Vocab::NAME_TYPES.'authorised'))->orderBy('created_at')->limit(1))
            ->paginate(50)->withQueryString();

        return view('places.index', ['tree' => null, 'places' => $places, 'query' => $query, 'type' => $type, 'types' => $this->types($institution)]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()->may('create', 'vocabulary'), 403);
        $parent = $request->query('parent') ? $this->find($request, (string) $request->query('parent')) : null;

        return view('places.create', ['parent' => $parent]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->may('create', 'vocabulary'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:1024'],
            'language' => ['required', 'string', fn ($a, $v, $fail) => Bcp47::valid((string) $v) || $fail(__('Give a BCP 47 language tag, such as en or af.'))],
            'parent' => ['nullable', 'uuid'],
        ]);
        $parent = ($data['parent'] ?? null) ? $this->find($request, $data['parent']) : null;
        try {
            $place = $this->places->create($request->user()->institution, $data['name'], $data['language'], $parent, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['name' => $e->getMessage()]);
        }

        return redirect()->route('places.edit', [$place, 'language' => $data['language']])->with('status', __('Place created. Describe it now, or later.'));
    }

    public function show(Request $request, Place $place): View|RedirectResponse
    {
        if ($place->merged_into_id && $place->institution_id === $request->user()->institution_id) {
            return redirect()->route('places.show', $place->merged_into_id)->with('status', __('That place was merged into this one.'));
        }
        $this->own($request, $place);
        $place->load('descriptionValues.element.labels', 'descriptionValues.state.labels', 'names.nameType.labels', 'names.state.labels', 'names.dates.property',
            'identifiers.identifierType.labels', 'placeType.labels', 'children.names.nameType', 'children.placeType.labels',
            'dates.property.labels', 'dates.dateType.labels', 'dates.qualifier.labels');

        return view('places.show', ['place' => $place, 'dateChoices' => app(DateService::class)->choices('place'),
            'relations' => app(RelationService::class)->of($place)]);
    }

    public function edit(Request $request, Place $place): View
    {
        $this->own($request, $place, 'update');
        $language = (string) $request->query('language', 'en');
        abort_unless(Bcp47::valid($language), 404);
        $place->load('descriptionValues.element', 'names.nameType', 'placeType');

        return view('places.edit', ['place' => $place, 'language' => $language, 'types' => $this->types($place->institution_id)]);
    }

    public function update(Request $request, Place $place): RedirectResponse
    {
        $this->own($request, $place, 'update');
        $data = $request->validate([
            'language' => ['required', 'string', fn ($a, $v, $fail) => Bcp47::valid((string) $v) || $fail(__('Give a BCP 47 language tag, such as en or af.'))],
            'values' => ['array'],
            'values.*' => ['nullable', 'max:65535'], 'values.*.*' => ['nullable', 'string', 'max:8'],
            'place_type' => ['nullable', 'uuid'],
            'latitude' => ['nullable', 'string', 'max:32'],
            'longitude' => ['nullable', 'string', 'max:32'],
            'altitude' => ['nullable', 'string', 'max:32'],
        ]);
        $values = array_intersect_key($data['values'] ?? [], array_flip(Elements::notations(Elements::PLACE)));
        try {
            DB::transaction(function () use ($place, $data, $values, $request) {
                $this->places->updateFacts($place, $data['place_type'] ?? null, $data['latitude'] ?? null, $data['longitude'] ?? null, $data['altitude'] ?? null, $request->user());
                $this->places->describe($place, $values, $data['language'], $request->user());
            });
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['values' => $e->getMessage()]);
        }

        return redirect()->route('places.show', $place)->with('status', __('Place saved.'));
    }

    /** Find the place it now lies in: search, then choose, or put it at the top. */
    public function moveForm(Request $request, Place $place): View
    {
        $this->own($request, $place, 'update');
        $query = trim((string) $request->query('q', ''));
        // A place it contains is not offered: it cannot lie in one.
        $candidates = $query === '' ? collect() : app(RelationService::class)->candidates($request->user(), ['place'], $query, $place)
            ->reject(fn (Place $c) => collect($c->ancestors())->contains('id', $place->id))->values();

        return view('places.move', ['place' => $place->load('parent.names.nameType'), 'query' => $query, 'candidates' => $candidates]);
    }

    public function move(Request $request, Place $place): RedirectResponse
    {
        $this->own($request, $place, 'update');
        $data = $request->validate(['parent' => ['required', 'string', 'regex:/^(top|[0-9a-f-]{36})$/']]);
        $parent = $data['parent'] === 'top' ? null : $this->find($request, $data['parent']);
        try {
            $this->places->move($place, $parent, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['parent' => $e->getMessage()]);
        }

        return redirect()->route('places.show', $place)->with('status', __('Place moved.'));
    }

    private function existing(string $institutionId): Builder
    {
        return Place::where('institution_id', $institutionId)->whereNull('deleted_at');
    }

    /** The current concepts of the institution's place-type vocabularies. */
    private function types(string $institutionId)
    {
        return Concept::where('institution_id', $institutionId)->whereNull('deprecated_at')
            ->whereHas('vocabulary.kind', fn ($q) => $q->where('iri', Vocab::SCHEME_KINDS.'place-type'))
            ->with('labels', 'vocabulary')->get()->sortBy(fn (Concept $c) => mb_strtolower($c->anyLabel(['en'])))->values();
    }

    private function find(Request $request, string $id): Place
    {
        return $this->existing($request->user()->institution_id)->find($id) ?? abort(404);
    }

    /** Another institution's place, or a deleted one, does not exist here (404); changing one needs the vocabulary permission (403). */
    private function own(Request $request, Place $place, ?string $action = null): void
    {
        abort_unless($place->institution_id === $request->user()->institution_id && $place->deleted_at === null, 404);
        abort_unless($action === null || $request->user()->may($action, 'vocabulary', $place), 403);
    }
}
