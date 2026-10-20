<?php

// OpenRiC Workspace - physical storage: containers and storage places (openricx:Container, extension ontology 1.6.0).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The storage permissions govern containers; storage places are places, so
// making one needs the vocabulary permissions, as any place does. Nothing of
// storage reaches the public: the Public role has no storage permission and a
// storage place is public only if someone publishes it.

namespace App\Http\Controllers;

use App\Description\Bcp47;
use App\Description\DateService;
use App\Description\Elements;
use App\Description\IdentifierService;
use App\Description\PlaceService;
use App\Description\StorageService;
use App\Models\Concept;
use App\Models\Container;
use App\Models\Identifier;
use App\Models\Name;
use App\Models\Place;
use App\Models\RecordResource;
use App\Models\Relation;
use App\Relations\RelationService;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StorageController extends Controller
{
    public function __construct(private StorageService $storage) {}

    /** Containers, searched by name, identifier or barcode, by type, and by where they are kept. */
    public function index(Request $request): View
    {
        $user = $request->user();
        $query = trim((string) $request->query('q', ''));
        $type = (string) $request->query('type', '');
        $unplaced = $request->boolean('unplaced');
        $like = '%'.addcslashes($query, '%_\\').'%';
        $containers = $this->existing($user->institution_id)->with('names.nameType', 'containerType.labels', 'identifiers.identifierType', 'status.labels')
            ->when($query !== '', fn (Builder $q) => $q->where(fn ($q) => $q
                ->whereIn('id', Name::where('subject_table', 'containers')->where('value', 'ilike', $like)->select('subject_id'))
                ->orWhereIn('id', DB::table('identifiers')->where('subject_table', 'containers')->where('value', 'ilike', $like)->select('subject_id'))))
            ->when($type !== '', fn (Builder $q) => $q->where('container_type_id', $type))
            ->when($unplaced, fn (Builder $q) => $q->whereIn('id', $this->storage->unplaced($user->institution_id)->pluck('id')))
            ->orderBy(Name::select('value')->whereColumn('subject_id', 'containers.id')->where('subject_table', 'containers')
                ->whereHas('nameType', fn ($q) => $q->where('iri', Vocab::NAME_TYPES.'authorised'))->orderBy('created_at')->limit(1))
            ->paginate(50)->withQueryString();

        return view('storage.index', ['containers' => $containers, 'query' => $query, 'type' => $type, 'unplaced' => $unplaced,
            'types' => $this->storage->types($user->institution_id), 'storage' => $this->storage]);
    }

    /** Find a container by its barcode, as a scanner types it. */
    public function scan(Request $request): RedirectResponse
    {
        $barcode = trim((string) $request->query('barcode', ''));
        $container = $barcode === '' ? null : $this->storage->byBarcode($request->user()->institution_id, $barcode);

        return $container ? redirect()->route('containers.show', $container)
            : redirect()->route('containers.index', ['q' => $barcode])->withErrors(['barcode' => "No container has the barcode $barcode."]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()->may('create', 'storage'), 403);

        return view('storage.create', ['types' => $this->storage->types($request->user()->institution_id), 'places' => $this->storage->storagePlaces($request->user()->institution_id),
            'levels' => $this->levelChoices(), 'current' => []]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->may('create', 'storage'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:1024'],
            'language' => ['required', 'string', fn ($a, $v, $fail) => Bcp47::valid((string) $v) || $fail('Give a BCP 47 language tag, such as en or af.')],
            'type' => ['nullable', 'uuid'],
            'identifier' => ['nullable', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:255'],
            'place' => ['nullable', 'uuid'],
            ...$this->levelRules(),
        ]);
        try {
            $container = DB::transaction(function () use ($user, $data) {
                $container = $this->storage->create($user->institution, $data['name'], $data['language'], $data['type'] ?? null, $data['identifier'] ?? null, $user);
                $this->storage->setBarcode($container, $data['barcode'] ?? null, $user);

                return $container;
            });
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['container' => $e->getMessage()]);
        }
        // A container that cannot be placed is still kept, and the reason shown.
        $placed = $this->placeFromForm($request, $container, $data);

        $redirect = redirect()->route('containers.show', $container)->with('status', 'Container created'.$placed.'. Add what it holds from each unit of description, or here.');

        return $this->placementError ? $redirect->withErrors(['placement' => $this->placementError]) : $redirect;
    }

    public function show(Request $request, Container $container): View
    {
        $this->own($request, $container);
        $container->load('descriptionValues.element.labels', 'descriptionValues.state.labels', 'names.nameType.labels', 'names.state.labels', 'names.dates.property',
            'identifiers.identifierType.labels', 'containerType.labels', 'containerType.vocabulary', 'status.labels', 'securityLevel.labels', 'capacityUnit.labels',
            'dates.property.labels', 'dates.dateType.labels', 'dates.qualifier.labels');
        $place = $this->storage->place($container);
        $locations = $this->storage->locations($container)->map(fn (Relation $r) => [$r, Place::find($r->target_id)]);

        return view('storage.show', ['container' => $container, 'place' => $place, 'path' => $place ? $this->storage->path($place) : null,
            'locations' => $locations, 'places' => $this->storage->storagePlaces($container->institution_id), 'storage' => $this->storage,
            'dateChoices' => app(DateService::class)->choices('container'), 'relations' => app(RelationService::class)->of($container),
            'canUpdate' => $request->user()->may('update', 'storage', $container)]);
    }

    public function edit(Request $request, Container $container): View
    {
        $this->own($request, $container, 'update');
        $language = (string) $request->query('language', 'en');
        abort_unless(Bcp47::valid($language), 404);
        $container->load('descriptionValues.element', 'names.nameType', 'identifiers.identifierType');

        return view('storage.edit', ['container' => $container, 'language' => $language, 'types' => $this->storage->types($container->institution_id),
            'statuses' => Concept::choices(StorageService::STATUSES)->get(), 'securityLevels' => Concept::choices(StorageService::SECURITY_LEVELS)->get(),
            'units' => Concept::choices(StorageService::CAPACITY_UNITS)->get(), 'barcode' => $this->barcode($container),
            'levels' => $this->levelChoices(), 'current' => $this->storage->levelsOf($this->storage->place($container))]);
    }

    public function update(Request $request, Container $container): RedirectResponse
    {
        $this->own($request, $container, 'update');
        $notations = fn (string $vocabulary) => Concept::choices($vocabulary)->pluck('notation')->all();
        $data = $request->validate([
            'language' => ['required', 'string', fn ($a, $v, $fail) => Bcp47::valid((string) $v) || $fail('Give a BCP 47 language tag, such as en or af.')],
            'values' => ['array'],
            'values.*' => ['nullable', 'string', 'max:65535'],
            'type' => ['nullable', 'uuid'],
            'barcode' => ['nullable', 'string', 'max:255'],
            'identifier' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in($notations(StorageService::STATUSES))],
            'security_level' => ['nullable', Rule::in($notations(StorageService::SECURITY_LEVELS))],
            'capacity_unit' => ['nullable', Rule::in($notations(StorageService::CAPACITY_UNITS))],
            'climate_controlled' => ['nullable', Rule::in(['', '0', '1'])],
            ...array_fill_keys(['width_cm', 'height_cm', 'depth_cm', 'capacity', 'capacity_used', 'temperature_min', 'temperature_max', 'humidity_min', 'humidity_max'], ['nullable', 'string', 'max:20']),
            ...$this->levelRules(),
        ]);
        $values = array_intersect_key($data['values'] ?? [], array_flip(Elements::notations(Elements::CONTAINER)));
        try {
            DB::transaction(function () use ($container, $data, $values, $request) {
                $this->storage->updateFacts($container, $data, $request->user());
                $this->storage->setBarcode($container, $data['barcode'] ?? null, $request->user());
                app(IdentifierService::class)->setLocal($container, $data['identifier'] ?? null, $request->user());
                $this->storage->describe($container, $values, $data['language'], $request->user());
            });
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['values' => $e->getMessage()]);
        }
        $placed = $this->placeFromForm($request, $container, $data);

        $redirect = redirect()->route('containers.show', $container)->with('status', 'Container saved'.$placed.'.');

        return $this->placementError ? $redirect->withErrors(['placement' => $this->placementError.' The rest was saved.']) : $redirect;
    }

    /** Keep the container in a storage place, or take it out of storage, with a note. */
    public function move(Request $request, Container $container): RedirectResponse
    {
        $this->own($request, $container, 'update');
        $data = $request->validate(['place' => ['nullable', 'uuid'], 'note' => ['nullable', 'string', 'max:2000'], 'day' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today']]);
        try {
            $place = ($data['place'] ?? null) ? $this->storagePlace($container->institution_id, $data['place']) : null;
            $this->storage->move($container, $place, $data['note'] ?? null, 'en', $request->user(), $data['day'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['move' => $e->getMessage()]);
        }

        return redirect()->route('containers.show', $container)->with('status', $place ? 'Moved to '.$this->storage->path($place).'.' : 'Taken out of storage.');
    }

    /** What a container holds, to print and put in it, as Heratio's box list. */
    public function boxList(Request $request, Container $container): View
    {
        $this->own($request, $container);
        $units = $this->storage->contents($container)->where('source_table', 'record_resources')
            ->map(fn (Relation $r) => RecordResource::with('names.nameType', 'descriptionValues.element', 'dates.property', 'identifiers.identifierType', 'level.labels')->find($r->source_id))
            ->filter(fn (?RecordResource $u) => $u && $u->deleted_at === null && $request->user()->may('read', 'description', $u))
            ->sortBy(fn (RecordResource $u) => $u->referenceCode() ?? '')->values();
        $place = $this->storage->place($container);

        return view('storage.box-list', ['container' => $container, 'units' => $units, 'path' => $place ? $this->storage->path($place) : null]);
    }

    /** Every container with its type, identifiers and where it is kept, as CSV. */
    public function holdings(Request $request): StreamedResponse
    {
        abort_unless($request->user()->mayAnywhere('read', 'storage'), 403);
        $containers = $this->existing($request->user()->institution_id)->with('names.nameType', 'containerType.labels', 'identifiers.identifierType', 'status.labels', 'capacityUnit.labels')->get()
            ->filter(fn (Container $c) => $request->user()->may('read', 'storage', $c))
            ->sortBy(fn (Container $c) => mb_strtolower((string) $c->described('container-name', ['en'])));

        return response()->streamDownload(function () use ($containers) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['name', 'type', 'identifier', 'barcode', 'location', 'status', 'capacity', 'capacityUsed', 'capacityUnit', 'persistentIri'], escape: '');
            foreach ($containers as $container) {
                $place = $this->storage->place($container);
                fputcsv($out, [$container->described('container-name', ['en']), $container->containerType?->anyLabel(['en']), $container->identifier, $this->barcode($container),
                    $place ? $this->storage->path($place) : '', $container->status?->label(['en']), $container->capacity, $container->capacity_used,
                    $container->capacityUnit?->label(['en']), $container->iri], escape: '');
            }
            fclose($out);
        }, 'storage-holdings-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** The storage places, as a tree, with the containers each keeps. */
    public function places(Request $request): View
    {
        $institution = $request->user()->institution_id;
        $places = $this->storage->storagePlaces($institution);

        return view('storage.places', ['byParent' => $places->groupBy(fn (Place $p) => $places->contains('id', $p->parent_id) ? $p->parent_id : 'top'),
            'types' => $this->storage->placeTypes($institution), 'storage' => $this->storage, 'mayCreate' => $request->user()->may('create', 'vocabulary'),
            'unplaced' => $this->storage->unplaced($institution)->count()]);
    }

    public function storePlace(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->may('create', 'vocabulary'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:1024'],
            'language' => ['required', 'string', fn ($a, $v, $fail) => Bcp47::valid((string) $v) || $fail('Give a BCP 47 language tag, such as en or af.')],
            'type' => ['required', 'uuid', Rule::in($this->storage->placeTypes($user->institution_id)->pluck('id')->all())],
            'parent' => ['nullable', 'uuid'],
        ]);
        $parent = ($data['parent'] ?? null) ? Place::where('institution_id', $user->institution_id)->whereNull('deleted_at')->find($data['parent']) ?? abort(404) : null;
        $places = app(PlaceService::class);
        try {
            $place = DB::transaction(function () use ($places, $user, $data, $parent) {
                $place = $places->create($user->institution, $data['name'], $data['language'], $parent, $user);
                $places->updateFacts($place, $data['type'], null, null, null, $user);

                return $place;
            });
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['place' => $e->getMessage()]);
        }

        return redirect()->route('storage.place', $place)->with('status', 'Storage place created.');
    }

    /** A storage place: its path, the places inside it, the containers it keeps and keeps below it, their capacity, and what came and went. */
    public function place(Request $request, string $id): View
    {
        $user = $request->user();
        $place = $this->storagePlace($user->institution_id, $id);
        $children = $this->storage->storagePlaces($user->institution_id)->where('parent_id', $place->id)->values();
        $history = Relation::where(['target_table' => 'places', 'target_id' => $place->id])->whereHas('relationType', fn ($q) => $q->where('notation', 'location'))
            ->with('dates.property')->get()->sortByDesc(fn (Relation $r) => ($r->datedAs('beginning')?->normalized_value ?? '').'|'.$r->created_at->format('Y-m-d H:i:s.u').'|'.$r->id)
            ->map(fn (Relation $r) => [$r, Container::with('names.nameType')->find($r->source_id)])->take(100)->values();

        return view('storage.place', ['place' => $place->load('names.nameType', 'placeType.labels'), 'path' => $this->storage->path($place), 'children' => $children,
            'here' => $this->storage->containersIn($place), 'below' => $this->storage->containersIn($place, true), 'capacity' => $this->storage->capacity($place),
            'history' => $history, 'places' => $this->storage->storagePlaces($user->institution_id), 'unplaced' => $this->storage->unplaced($user->institution_id),
            'mayMove' => $user->may('update', 'storage'), 'storage' => $this->storage]);
    }

    /** Put containers in this storage place: unplaced ones, or (with from) those kept in another. */
    public function moveHere(Request $request, string $id): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->may('update', 'storage'), 403);
        $data = $request->validate(['containers' => ['required', 'array', 'max:500'], 'containers.*' => ['uuid'], 'destination' => ['nullable', 'uuid'],
            'note' => ['nullable', 'string', 'max:2000']]);
        $here = $this->storagePlace($user->institution_id, $id);
        $destination = array_key_exists('destination', $data) ? (($data['destination'] ?? null) ? $this->storagePlace($user->institution_id, $data['destination']) : null) : $here;
        $containers = $this->existing($user->institution_id)->whereIn('id', $data['containers'])->get()->filter(fn (Container $c) => $user->may('update', 'storage', $c));
        try {
            $moved = $this->storage->moveMany($containers, $destination, $data['note'] ?? null, 'en', $user);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['move' => $e->getMessage()]);
        }

        return redirect()->route('storage.place', $here)->with('status', "$moved ".($moved === 1 ? 'container' : 'containers').' moved'.($destination ? ' to '.$this->storage->path($destination) : ' out of storage').'.');
    }

    private ?string $placementError = null;

    /**
     * Put the container where the form says: the levels typed (Building to Shelf), else the storage place chosen. Every level
     * empty and nothing chosen leaves it where it is: clearing the form never takes a container out of storage.
     *
     * @return string ", and kept in ..." when it moved, else ""
     */
    private function placeFromForm(Request $request, Container $container, array $data): string
    {
        try {
            $place = $this->storage->placeByLevels($request->user()->institution, $data['levels'] ?? [], 'en', $request->user())
                ?? (($data['place'] ?? null) ? $this->storagePlace($container->institution_id, $data['place']) : null);
            if ($place === null || $this->storage->place($container)?->id === $place->id) {
                return '';
            }
            $this->storage->move($container, $place, 'Placed with the storage form.', 'en', $request->user());

            return ', and kept in '.$this->storage->path($place);
        } catch (InvalidArgumentException $e) {
            $this->placementError = 'It was not placed: '.$e->getMessage().'.';

            return '';
        }
    }

    /** @return array<string, string> level notation => label, from the top */
    private function levelChoices(): array
    {
        $types = Concept::choices(StorageService::PLACE_TYPES)->get()->keyBy('notation');

        return collect(StorageService::LEVELS)->mapWithKeys(fn ($level) => [$level => (string) $types[$level]?->label(['en'])])->all();
    }

    private function levelRules(): array
    {
        return ['levels' => ['nullable', 'array'], ...collect(StorageService::LEVELS)->mapWithKeys(fn ($l) => ["levels.$l" => ['nullable', 'string', 'max:255']])->all()];
    }

    private function storagePlace(string $institutionId, string $id): Place
    {
        $place = Place::where('institution_id', $institutionId)->whereNull('deleted_at')->find($id);
        abort_unless($place && $this->storage->isStoragePlace($place), 404);

        return $place;
    }

    private function barcode(Container $container): ?string
    {
        return $container->identifiers->first(fn (Identifier $i) => $i->identifierType->iri === Vocab::IDENTIFIER_TYPES.'barcode')?->value;
    }

    private function existing(string $institutionId): Builder
    {
        return Container::where('institution_id', $institutionId)->whereNull('deleted_at');
    }

    /** Another institution's container, or a deleted one, does not exist here (404); without the permission, 403. */
    private function own(Request $request, Container $container, string $action = 'read'): void
    {
        abort_unless($container->institution_id === $request->user()->institution_id && $container->deleted_at === null, 404);
        abort_unless($request->user()->may($action, 'storage', $container), 403);
    }
}
