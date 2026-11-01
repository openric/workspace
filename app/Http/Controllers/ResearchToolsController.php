<?php

// OpenRiC Workspace - reproductions, bibliographies, citations and saved searches (roadmap phase 19c, ADR 0017),
// after Heratio's ResearchReproductionsController, ResearchBibliographiesController, ResearchCitationsController and
// ResearchSavedSearchesController (ahg-research).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Exchange\ExportView;
use App\Models\Bibliography;
use App\Models\BibliographyEntry;
use App\Models\CitationLogEntry;
use App\Models\Concept;
use App\Models\Favourite;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\ReproductionItem;
use App\Models\ReproductionRequest;
use App\Models\SavedSearch;
use App\Relations\EntityKinds;
use App\Research\Bibliographies;
use App\Research\Citations;
use App\Research\Reproductions;
use App\Research\Researchers;
use App\Research\ResearchFiles;
use App\Research\SavedSearches;
use App\Support\HostInstitution;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class ResearchToolsController extends Controller
{
    public function __construct(private Reproductions $reproductions, private Bibliographies $bibliographies, private SavedSearches $searches) {}

    // Reproductions.

    public function reproductions(Request $request): View
    {
        $user = $request->user();
        $staff = $user->hasPermission(Vocab::permission('manage-reading-room'));
        $mine = app(Researchers::class)->of($user);
        $status = $request->query('status');

        return view('research.reproductions', ['staff' => $staff, 'researcher' => $mine, 'status' => $status, 'statuses' => Concept::choices(Reproductions::STATUSES)->get(),
            'requests' => ReproductionRequest::where('institution_id', $user->institution_id)->when(! $staff || $request->boolean('mine'), fn ($q) => $q->where('researcher_id', $mine?->id))
                ->when($status, fn ($q) => $q->whereHas('status', fn ($s) => $s->where('iri', Reproductions::STATUSES.$status)))->with('status', 'researcher')->orderByDesc('created_at')->get(),
            'deliveries' => Concept::choices(Vocab::BASE.'delivery-method/')->get()]);
    }

    public function createReproduction(Request $request): RedirectResponse
    {
        $data = $request->validate(['purpose' => ['nullable', 'string', 'max:5000'], 'intended_use' => ['nullable', 'string', 'max:5000'], 'publication_details' => ['nullable', 'string', 'max:5000'],
            'delivery_method' => ['required', 'string', 'max:64'], 'delivery_address' => ['nullable', 'string', 'max:2000'], 'delivery_email' => ['nullable', 'email', 'max:255'], 'notes' => ['nullable', 'string', 'max:5000']]);
        try {
            $made = $this->reproductions->create($request->user(), $data);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['research' => $e->getMessage()]);
        }

        return redirect()->route('research.reproductions.show', $made)->with('status', __('Add the copies you need, then send the request.'));
    }

    public function reproduction(Request $request, ReproductionRequest $reproduction): View
    {
        $staff = $this->reproductions->manages($request->user(), $reproduction);
        abort_unless($staff || $reproduction->researcher->user_id === $request->user()->id, 404);
        $reproduction->load('status', 'deliveryMethod', 'researcher.user', 'items.record', 'items.reproductionType', 'items.colourMode', 'items.status', 'items.files.file', 'statusChanges.changedBy');

        return view('research.reproduction', ['reproduction' => $reproduction, 'staff' => $staff, 'service' => $this->reproductions,
            'types' => Concept::choices(Vocab::BASE.'reproduction-type/')->get(), 'colours' => Concept::choices(Vocab::BASE.'colour-mode/')->get()]);
    }

    public function reproductionStep(Request $request, ReproductionRequest $reproduction, string $step): RedirectResponse
    {
        $user = $request->user();
        $data = $request->all();

        return $this->attempt(function () use ($user, $reproduction, $step, $data) {
            match ($step) {
                'item' => $this->reproductions->addItem($user, $reproduction, RecordResource::where(['institution_id' => $user->institution_id, 'iri' => trim((string) ($data['iri'] ?? ''))])->whereNull('deleted_at')->first()
                    ?? throw new InvalidArgumentException(__('No record has that IRI.')), $data),
                'submit' => $this->reproductions->submit($user, $reproduction),
                'status' => $this->reproductions->setStatus($user, $reproduction, (string) ($data['status'] ?? ''), $data['notes'] ?? null),
                'payment' => $this->reproductions->recordPayment($user, $reproduction, $data),
                'handle' => $this->reproductions->handle($user, $reproduction, $data),
            };
        }, __('Saved.'));
    }

    public function reproductionItem(Request $request, ReproductionItem $item, string $step): RedirectResponse
    {
        $request->validate(['files' => ['nullable', 'array', 'max:20'], 'files.*' => ['file', 'max:'.ResearchFiles::MAX_KILOBYTES]]);

        return $this->attempt(fn () => $step === 'remove' ? $this->reproductions->removeItem($request->user(), $item)
            : $this->reproductions->finishItem($request->user(), $item, (string) $request->input('status'), $request->file('files', [])), __('Saved.'));
    }

    public function reproductionDownload(Request $request, string $token): Response
    {
        $file = $this->reproductions->download($request->user(), $token) ?? abort(404);

        return app(ResearchFiles::class)->respond($file->file, true);
    }

    // Bibliographies.

    public function bibliographies(Request $request): View
    {
        return view('research.bibliographies', ['bibliographies' => Bibliography::where('user_id', $request->user()->id)->withCount('entries')->with('citationStyle')->orderBy('name')->get(),
            'styles' => Concept::choices(Vocab::BASE.'citation-style/')->get()]);
    }

    public function saveBibliography(Request $request, ?Bibliography $bibliography = null): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'], 'citation_style' => ['required', 'string', 'max:64']]);
        try {
            $saved = $this->bibliographies->save($request->user(), $data, $bibliography);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['research' => $e->getMessage()]);
        }

        return redirect()->route('research.bibliographies.show', $saved)->with('status', __('Saved.'));
    }

    public function bibliography(Request $request, Bibliography $bibliography): View
    {
        abort_unless($bibliography->user_id === $request->user()->id, 404);

        return view('research.bibliography', ['bibliography' => $bibliography->load('citationStyle', 'entries.entryType', 'entries.record'), 'service' => $this->bibliographies,
            'styles' => Concept::choices(Vocab::BASE.'citation-style/')->get(), 'types' => Concept::choices(Vocab::BASE.'bibliography-entry-type/')->get()]);
    }

    public function bibliographyStep(Request $request, Bibliography $bibliography, string $step): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate(['iri' => ['nullable', 'string', 'max:2048'], 'text' => ['nullable', 'string', 'max:500000'], 'format' => ['nullable', 'in:bibtex,ris,citation'],
            'entry_type' => ['nullable', 'string', 'max:64'], 'title' => ['nullable', 'string', 'max:2000'], 'authors' => ['nullable', 'string', 'max:2000'], 'date' => ['nullable', 'string', 'max:100'],
            'publisher' => ['nullable', 'string', 'max:500'], 'container_title' => ['nullable', 'string', 'max:1000'], 'volume' => ['nullable', 'string', 'max:50'], 'issue' => ['nullable', 'string', 'max:50'],
            'pages' => ['nullable', 'string', 'max:50'], 'doi' => ['nullable', 'string', 'max:255'], 'url' => ['nullable', 'string', 'max:2048'], 'archive_name' => ['nullable', 'string', 'max:500'],
            'archive_location' => ['nullable', 'string', 'max:500'], 'collection_title' => ['nullable', 'string', 'max:500'], 'box' => ['nullable', 'string', 'max:100'], 'folder' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'], 'accessed_on' => ['nullable', 'date_format:Y-m-d'], 'share' => ['nullable', 'boolean']]);
        if ($step === 'share') {
            try {
                $token = $this->bibliographies->share($user, $bibliography, $request->boolean('share'));
            } catch (InvalidArgumentException $e) {
                return back()->withErrors(['research' => $e->getMessage()]);
            }

            return back()->with('status', $token ? __('Anyone with this link may read it; it is shown only now:') : __('It is no longer shared.'))->with('share_link', $token ? route('research.bibliographies.shared', $token) : null);
        }

        return $this->attempt(fn () => match ($step) {
            'entry' => $this->bibliographies->addEntry($user, $bibliography, $data),
            'record' => $this->bibliographies->addRecord($user, $bibliography, RecordResource::where(['institution_id' => $user->institution_id, 'iri' => trim((string) ($data['iri'] ?? ''))])->whereNull('deleted_at')->first()
                ?? throw new InvalidArgumentException(__('No record has that IRI.'))),
            'import' => $this->bibliographies->import($user, $bibliography, (string) ($data['text'] ?? ''), (string) ($data['format'] ?? '')),
            'delete' => $this->bibliographies->delete($user, $bibliography),
        }, __('Saved.'), $step === 'delete' ? 'research.bibliographies.index' : null);
    }

    public function entryStep(Request $request, BibliographyEntry $entry, string $step): RedirectResponse
    {
        return $this->attempt(fn () => $step === 'remove' ? $this->bibliographies->removeEntry($request->user(), $entry)
            : $this->bibliographies->updateEntry($request->user(), $entry, $request->only(['entry_type', 'title', 'authors', 'date', 'publisher', 'container_title', 'volume', 'issue', 'pages',
                'doi', 'url', 'archive_name', 'archive_location', 'collection_title', 'box', 'folder', 'notes', 'accessed_on'])), __('Saved.'));
    }

    public function exportBibliography(Request $request, Bibliography $bibliography, string $format): Response
    {
        abort_unless($bibliography->user_id === $request->user()->id && isset(Bibliographies::EXPORTS[$format]), 404);

        return response($this->bibliographies->export($bibliography, $format), 200, ['Content-Type' => Bibliographies::mime($format),
            'Content-Disposition' => 'attachment; filename="'.Str::slug($bibliography->name).'.'.Bibliographies::extension($format).'"']);
    }

    /** A bibliography opened by its link. */
    public function sharedBibliography(string $token): View
    {
        $bibliography = $this->bibliographies->shared($token) ?? abort(404);

        return view('research.bibliography-shared', ['bibliography' => $bibliography->load('citationStyle', 'entries.entryType'), 'service' => $this->bibliographies,
            'institution' => Institution::find($bibliography->institution_id)]);
    }

    /** Send favourites to a bibliography (Heratio's sendToBibliography). */
    public function favouritesToBibliography(Request $request): RedirectResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['uuid'], 'bibliography_id' => ['required', 'uuid']]);
        $bibliography = Bibliography::where(['id' => $data['bibliography_id'], 'user_id' => $request->user()->id])->firstOrFail();
        $added = 0;
        foreach (Favourite::where('user_id', $request->user()->id)->whereIn('id', $data['ids'])->where('subject_table', 'record_resources')->get() as $f) {
            try {
                $this->bibliographies->addRecord($request->user(), $bibliography, RecordResource::findOrFail($f->subject_id));
                $added++;
            } catch (InvalidArgumentException) {
                // Already in it, or no longer readable: left out.
            }
        }

        return back()->with('status', trans_choice('{0} No record was added.|{1} :count record was added.|[2,*] :count records were added.', $added, ['count' => $added]));
    }

    // Citing a record (Heratio's cite and citeExport).

    public function cite(Request $request, string $record): Response|View
    {
        $user = $request->user();
        $institution = $user?->institution ?? HostInstitution::for($request) ?? abort(404);
        $unit = RecordResource::where('institution_id', $institution->id)->whereNull('deleted_at')->findOrFail($record);
        $view = new ExportView($user, false, null, $institution);
        abort_unless($user ? EntityKinds::readable($user, $unit) : $view->readable($unit), 404);
        $citations = app(Citations::class);
        $format = $request->query('format');
        if ($format !== null) {
            abort_unless(isset(Citations::FORMATS[$format]), 404);
            CitationLogEntry::create(['institution_id' => $institution->id, 'user_id' => $user?->id, 'record_resource_id' => $unit->id, 'format' => $format, 'created_at' => now()]);

            return response($citations->one($view, $unit, $format), 200, ['Content-Type' => Citations::mime($format),
                'Content-Disposition' => (in_array($format, Citations::STYLES, true) ? 'inline' : 'attachment').'; filename="'.Str::slug(EntityKinds::label($unit)).'.'.Citations::extension($format).'"']);
        }

        return view('research.cite', ['unit' => $unit, 'institution' => $institution, 'styles' => collect(Citations::STYLES)->mapWithKeys(fn ($s) => [$s => $citations->one($view, $unit, $s)]),
            'files' => array_diff_key(Citations::FORMATS, array_flip(Citations::STYLES))]);
    }

    // Saved searches.

    public function searches(Request $request): View
    {
        return view('research.searches', ['searches' => SavedSearch::where('user_id', $request->user()->id)->with('alertFrequency')->orderBy('name')->get(),
            'frequencies' => Concept::choices(Vocab::BASE.'alert-frequency/')->get()]);
    }

    public function saveSearch(Request $request, ?SavedSearch $search = null): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000'], 'search_query' => ['required', 'string', 'max:2000'],
            'search_filters' => ['nullable', 'array'], 'alert_enabled' => ['nullable', 'boolean'], 'alert_frequency' => ['nullable', 'string', 'max:64']]);

        return $this->attempt(fn () => $this->searches->save($request->user(), $data, $search), __('The search is saved.'), $search ? null : 'research.searches.index');
    }

    public function searchStep(Request $request, SavedSearch $search, string $step): RedirectResponse|View
    {
        abort_unless($search->user_id === $request->user()->id, 404);

        return match ($step) {
            'run' => tap(redirect()->route('search', ['q' => $search->search_query] + (array) $search->search_filters), fn () => $search->update(['last_run_at' => now()])),
            'snapshot' => back()->with('status', trans_choice('{0} The snapshot holds no result.|{1} The snapshot holds :count result.|[2,*] The snapshot holds :count results.', $n = $this->searches->snapshot($request->user(), $search), ['count' => $n])),
            'diff' => view('research.search-diff', ['search' => $search, 'diff' => $this->searches->diff($request->user(), $search)]),
            'delete' => tap(redirect()->route('research.searches.index')->with('status', __('The search is deleted.')), fn () => $this->searches->delete($request->user(), $search)),
        };
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
