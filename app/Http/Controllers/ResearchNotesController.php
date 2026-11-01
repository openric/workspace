<?php

// OpenRiC Workspace - a researcher's own working pages: collections, annotations and the annotation studio,
// notebooks and the research diary (roadmap phase 19d, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ResearchCollectionsController, ResearchAnnotationsController, ResearchEvidenceController,
// ResearchNotebooksController and ResearchJournalEntryController. Everything here is the account's own; one sees
// another's only as an annotation they shared, or a collection they shared by its link.

namespace App\Http\Controllers;

use App\Api\PublicCatalogue;
use App\Iiif\Presentation;
use App\Models\Concept;
use App\Models\Favourite;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\ResearchAnnotation;
use App\Models\ResearchCollection;
use App\Models\ResearchCollectionItem;
use App\Models\ResearchDiaryEntry;
use App\Models\ResearchNotebook;
use App\Models\ResearchNotebookItem;
use App\Models\SavedSearch;
use App\Relations\EntityKinds;
use App\Research\Annotations;
use App\Research\Collections;
use App\Research\Diary;
use App\Research\Favourites;
use App\Research\Notebooks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class ResearchNotesController extends Controller
{
    public function __construct(private Collections $collections, private Annotations $annotations, private Notebooks $notebooks, private Diary $diary) {}

    // Collections.

    public function collections(Request $request): View
    {
        return view('research.collections', ['collections' => ResearchCollection::where('user_id', $request->user()->id)->withCount('items')->orderBy('name')->get()]);
    }

    public function saveCollection(Request $request, ?ResearchCollection $collection = null): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000']]);
        try {
            $saved = $this->collections->save($request->user(), $data, $collection);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['research' => $e->getMessage()]);
        }

        return redirect()->route('research.collections.show', $saved)->with('status', __('Saved.'));
    }

    public function collection(Request $request, ResearchCollection $collection): View
    {
        abort_unless($collection->user_id === $request->user()->id, 404);

        return view('research.collection', ['collection' => $collection, 'items' => $this->collections->items($request->user(), $collection)]);
    }

    public function collectionStep(Request $request, ResearchCollection $collection, string $step): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate(['url' => ['nullable', 'string', 'max:2048'], 'title' => ['nullable', 'string', 'max:500'], 'notes' => ['nullable', 'string', 'max:5000'],
            'iris' => ['nullable', 'string', 'max:200000'], 'share' => ['nullable', 'boolean']]);
        if ($step === 'share') {
            try {
                $token = $this->collections->share($user, $collection, $request->boolean('share'));
            } catch (InvalidArgumentException $e) {
                return back()->withErrors(['research' => $e->getMessage()]);
            }

            return back()->with('status', $token ? __('Anyone with this link may read it; it is shown only now:') : __('It is no longer shared.'))
                ->with('share_link', $token ? route('research.collections.shared', $token) : null);
        }
        if ($step === 'import') {
            try {
                $added = $this->collections->import($user, $collection, (string) ($data['iris'] ?? ''));
            } catch (InvalidArgumentException $e) {
                return back()->withErrors(['research' => $e->getMessage()]);
            }

            return back()->with('status', trans_choice('{0} No records were added.|{1} :count record was added.|[2,*] :count records were added.', $added, ['count' => $added]));
        }

        return $this->attempt(fn () => match ($step) {
            'link' => $this->collections->addLink($user, $collection, (string) ($data['url'] ?? ''), (string) ($data['title'] ?? ''), $data['notes'] ?? null),
            'delete' => $this->collections->delete($user, $collection),
        }, __('Saved.'), $step === 'delete' ? 'research.collections.index' : null);
    }

    /** Add an entity to a collection from its page; a new collection when a name is given (Heratio's createCollectionAjax). */
    public function addToCollection(Request $request, string $kind, string $id): RedirectResponse
    {
        $user = $request->user();
        $entity = $this->entity($request, $kind, $id);
        $data = $request->validate(['collection_id' => ['nullable', 'uuid'], 'new_name' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:5000']]);

        return $this->attempt(function () use ($user, $entity, $data) {
            $collection = trim((string) ($data['new_name'] ?? '')) !== '' ? $this->collections->save($user, ['name' => $data['new_name']])
                : ResearchCollection::where(['id' => $data['collection_id'] ?? null, 'user_id' => $user->id])->first() ?? throw new InvalidArgumentException(__('Choose one of your collections.'));
            $this->collections->add($user, $collection, $entity, $data['notes'] ?? null);
        }, __('Added to the collection.'));
    }

    public function collectionItem(Request $request, ResearchCollectionItem $item, string $step): RedirectResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:5000'], 'tags' => ['nullable', 'string', 'max:500'], 'reference_code' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000']]);

        return $this->attempt(fn () => $step === 'remove' ? $this->collections->removeItem($request->user(), $item) : $this->collections->updateItem($request->user(), $item, $data), __('Saved.'));
    }

    /** Send favourites to a collection, with their notes (Heratio's sendToCollection). */
    public function favouritesToCollection(Request $request): RedirectResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['uuid'], 'collection_id' => ['required', 'uuid']]);
        $user = $request->user();
        $collection = ResearchCollection::where(['id' => $data['collection_id'], 'user_id' => $user->id])->firstOrFail();
        $favourites = app(Favourites::class);
        $added = 0;
        foreach (Favourite::where('user_id', $user->id)->whereIn('id', $data['ids'])->get() as $f) {
            try {
                $entity = $favourites->entity($f);
                $entity ? $this->collections->add($user, $collection, $entity, $f->notes) : ($f->url ? $this->collections->addLink($user, $collection, $f->url, (string) $f->title, $f->notes) : null);
                $added += $entity || $f->url ? 1 : 0;
            } catch (InvalidArgumentException) {
                // one it may no longer read is passed over
            }
        }

        return back()->with('status', trans_choice('{0} Nothing was added.|{1} :count was added.|[2,*] :count were added.', $added, ['count' => $added]));
    }

    /** Heratio's finding aid of a collection, to print. */
    public function findingAid(Request $request, ResearchCollection $collection): View
    {
        abort_unless($collection->user_id === $request->user()->id, 404);

        return view('research.finding-aid', ['collection' => $collection, 'records' => $this->collections->findingAid($request->user(), $collection)]);
    }

    public function sharedCollection(string $token): View
    {
        $collection = $this->collections->shared($token) ?? abort(404);

        return view('research.collection-shared', ['collection' => $collection, 'items' => $this->collections->sharedItems($collection), 'institution' => Institution::find($collection->institution_id)]);
    }

    // Annotations.

    public function annotations(Request $request): View
    {
        $type = $request->query('type');

        return view('research.annotations', ['annotations' => $this->annotations->mine($request->user(), $request->query('q'), is_string($type) ? $type : null),
            'service' => $this->annotations, 'types' => Concept::choices(Annotations::TYPES)->get()]);
    }

    /** Heratio's annotation studio and evidence viewer: an entity's annotations, its images to mark regions on, and the form. */
    public function studio(Request $request, string $kind, string $id): View
    {
        $user = $request->user();
        $entity = $this->entity($request, $kind, $id);
        $editing = $request->filled('edit') ? ResearchAnnotation::where(['id' => $request->query('edit'), 'user_id' => $user->id])->with('targets.selectorType')->first() : null;

        return view('research.studio', ['entity' => $entity, 'kind' => $kind, 'annotations' => $this->annotations->of($user, $entity), 'tags' => $this->annotations->tags($user, $entity),
            'canvases' => $this->canvases($entity), 'editing' => $editing, 'collections' => $this->collections->of($user),
            'types' => Concept::choices(Annotations::TYPES)->get(), 'visibilities' => Concept::choices(Annotations::VISIBILITY)->get(), 'selectors' => Concept::choices(Annotations::SELECTORS)->get()]);
    }

    public function saveAnnotation(Request $request, string $kind, string $id): RedirectResponse
    {
        $user = $request->user();
        $entity = $this->entity($request, $kind, $id);
        $data = $request->validate(['annotation_id' => ['nullable', 'uuid'], 'annotation_type' => ['required', 'string', 'max:64'], 'visibility' => ['required', 'string', 'max:64'],
            'title' => ['nullable', 'string', 'max:255'], 'content' => ['nullable', 'string', 'max:50000'], 'tags' => ['nullable', 'string', 'max:500'], 'collection_id' => ['nullable', 'uuid'],
            'canvas_id' => ['nullable', 'string', 'max:2048'], 'instantiation_id' => ['nullable', 'uuid'], 'selector_type' => ['nullable', 'string', 'max:64'],
            'selector_value' => ['nullable', 'string', 'max:100000'], 'exact' => ['nullable', 'string', 'max:5000'], 'prefix' => ['nullable', 'string', 'max:500'],
            'suffix' => ['nullable', 'string', 'max:500'], 'clear_selector' => ['nullable', 'boolean']]);
        $annotation = ($data['annotation_id'] ?? null) ? ResearchAnnotation::where(['id' => $data['annotation_id'], 'user_id' => $user->id])->firstOrFail() : null;
        if ($data['canvas_id'] ?? null) {
            $data['instantiation_id'] = collect($this->canvases($entity))->firstWhere('id', $data['canvas_id'])['instantiation'] ?? null;
        }
        try {
            $this->annotations->save($user, $entity, $data, $annotation);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['research' => $e->getMessage()]);
        }

        return redirect()->route('research.studio', [$kind, $id])->with('status', __('Saved.'));
    }

    public function deleteAnnotation(Request $request, ResearchAnnotation $annotation): RedirectResponse
    {
        return $this->attempt(fn () => $this->annotations->delete($request->user(), $annotation), __('Deleted.'));
    }

    /** The annotations of an entity one may read, as a W3C AnnotationPage. */
    public function annotationPage(Request $request, string $kind, string $id): JsonResponse
    {
        $entity = $this->entity($request, $kind, $id);

        return response()->json($this->annotations->page($request->user(), $entity, $request->url()), 200, ['Content-Type' => 'application/ld+json;profile="http://www.w3.org/ns/anno.jsonld"'],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // Notebooks.

    public function notebooks(Request $request): View
    {
        return view('research.notebooks', ['notebooks' => ResearchNotebook::where('user_id', $request->user()->id)->withCount('items')->with('coverRecord')
            ->orderBy('sort_order')->orderByDesc('updated_at')->get()]);
    }

    public function saveNotebook(Request $request, ?ResearchNotebook $notebook = null): RedirectResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'summary' => ['nullable', 'string', 'max:5000'], 'cover_iri' => ['nullable', 'string', 'max:2048'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000']]);
        try {
            $saved = $this->notebooks->save($request->user(), $data, $notebook);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['research' => $e->getMessage()]);
        }

        return redirect()->route('research.notebooks.show', $saved)->with('status', __('Saved.'));
    }

    public function notebook(Request $request, ResearchNotebook $notebook): View
    {
        abort_unless($notebook->user_id === $request->user()->id, 404);

        return view('research.notebook', ['notebook' => $notebook->load('coverRecord', 'items.itemType', 'items.sourceRecord', 'items.savedSearch'),
            'types' => Concept::choices(Notebooks::TYPES)->get()->reject(fn ($c) => $c->notation === 'ai-output'),
            'searches' => SavedSearch::where('user_id', $request->user()->id)->orderBy('name')->get()]);
    }

    public function notebookStep(Request $request, ResearchNotebook $notebook, string $step): RedirectResponse
    {
        $data = $request->validate(['item_type' => ['nullable', 'string', 'max:64'], 'title' => ['nullable', 'string', 'max:500'], 'body' => ['nullable', 'string', 'max:100000'],
            'iri' => ['nullable', 'string', 'max:2048'], 'saved_search_id' => ['nullable', 'uuid'], 'pinned' => ['nullable', 'boolean']]);

        return $this->attempt(fn () => match ($step) {
            'item' => $this->notebooks->addItem($request->user(), $notebook, $data),
            'delete' => $this->notebooks->delete($request->user(), $notebook),
        }, __('Saved.'), $step === 'delete' ? 'research.notebooks.index' : null);
    }

    public function notebookItem(Request $request, ResearchNotebookItem $item, string $step): RedirectResponse
    {
        $data = $request->validate(['title' => ['nullable', 'string', 'max:500'], 'body' => ['nullable', 'string', 'max:100000'], 'pinned' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000']]);

        return $this->attempt(fn () => $step === 'remove' ? $this->notebooks->removeItem($request->user(), $item) : $this->notebooks->updateItem($request->user(), $item, $data), __('Saved.'));
    }

    // The research diary.

    public function diary(Request $request): View
    {
        $filters = $request->validate(['type' => ['nullable', 'string', 'max:64'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'q' => ['nullable', 'string', 'max:200']]);
        $entries = $this->diary->entries($request->user(), $filters);

        return view('research.diary', ['entries' => (clone $entries)->paginate(50)->withQueryString(), 'minutes' => (int) (clone $entries)->sum('time_spent_minutes'),
            'filters' => $filters, 'types' => Concept::choices(Diary::TYPES)->get(),
            'editing' => $request->filled('edit') ? ResearchDiaryEntry::where(['id' => $request->query('edit'), 'user_id' => $request->user()->id])->first() : null]);
    }

    public function saveDiaryEntry(Request $request, ?ResearchDiaryEntry $entry = null): RedirectResponse
    {
        $data = $request->validate(['entry_date' => ['required', 'date_format:Y-m-d'], 'title' => ['nullable', 'string', 'max:500'], 'content' => ['required', 'string', 'max:50000'],
            'entry_type' => ['nullable', 'string', 'max:64'], 'time_spent_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'], 'tags' => ['nullable', 'string', 'max:500'],
            'is_private' => ['nullable', 'boolean']]);
        $data['is_private'] = $request->boolean('is_private');

        return $this->attempt(fn () => $this->diary->save($request->user(), $data, $entry), __('Saved.'), 'research.diary.index');
    }

    public function deleteDiaryEntry(Request $request, ResearchDiaryEntry $entry): RedirectResponse
    {
        return $this->attempt(fn () => $this->diary->delete($request->user(), $entry), __('Deleted.'));
    }

    /** The entity of a page, by its kind's segment and id, which the account may read. */
    private function entity(Request $request, string $kind, string $id): Model
    {
        $definition = EntityKinds::get(EntityKinds::bySegment($kind) ?? '') ?? abort(404);
        $entity = $definition['class']::where('institution_id', $request->user()->institution_id)->find($id) ?? abort(404);
        abort_unless(EntityKinds::readable($request->user(), $entity), 404);

        return $entity;
    }

    /**
     * The images of a record the public may see, to mark regions on: the canvases of its IIIF manifest.
     *
     * @return list<array{id: string, label: string, url: string, width: ?int, height: ?int, instantiation: ?string}>
     */
    private function canvases(Model $entity): array
    {
        if (! $entity instanceof RecordResource) {
            return [];
        }
        $presentation = new Presentation(new PublicCatalogue(Institution::find($entity->institution_id)));
        if (! $presentation->view()->readable($entity) || ! $presentation->hasManifest($entity)) {
            return [];
        }
        $open = $presentation->openCanvases($entity);

        return collect($presentation->manifest($entity)['items'] ?? [])->map(function (array $canvas) use ($open) {
            $body = $canvas['items'][0]['items'][0]['body'] ?? null;

            return $body && ($body['type'] ?? null) === 'Image' && isset($open[$canvas['id']]) ? ['id' => $canvas['id'], 'label' => (string) (collect($canvas['label'] ?? [])->flatten()->first() ?? ''),
                'url' => $body['id'], 'width' => $canvas['width'] ?? null, 'height' => $canvas['height'] ?? null, 'instantiation' => $open[$canvas['id']]->id] : null;
        })->filter()->values()->all();
    }

    private function attempt(callable $do, string $done, ?string $route = null): RedirectResponse
    {
        try {
            $do();
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['research' => $e->getMessage()]);
        } catch (\UnhandledMatchError) {
            abort(404);
        }

        return ($route ? redirect()->route($route) : back())->with('status', $done);
    }
}
