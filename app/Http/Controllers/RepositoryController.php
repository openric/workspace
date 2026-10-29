<?php

// OpenRiC Workspace - repositories: list, create, view and describe (ISDIAH).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Written for the Workspace. The elements come from the description-element
// vocabulary, in its own order, never from a list in code. Every route needs
// the describe permission, and only the institution's own repositories exist.

namespace App\Http\Controllers;

use App\Description\Bcp47;
use App\Description\ContactPointService;
use App\Description\DateService;
use App\Description\Elements;
use App\Description\RepositoryService;
use App\Models\Concept;
use App\Models\ContactPoint;
use App\Models\Repository;
use App\Relations\RelationService;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RepositoryController extends Controller
{
    public function __construct(private RepositoryService $repositories, private ContactPointService $contacts) {}

    public function index(Request $request): View
    {
        $repositories = Repository::where('institution_id', $request->user()->institution_id)->whereNull('deleted_at')
            ->with('descriptionValues.element', 'names.nameType', 'identifiers.identifierType', 'contactPoints', 'descriptionStatus.labels')
            ->withCount(['records' => fn ($q) => $q->whereNull('deleted_at')])->get()
            ->filter(fn (Repository $r) => $request->user()->may('read', 'repository', $r))
            ->sortBy(fn (Repository $r) => mb_strtolower((string) $r->described('authorised-name', [app()->getLocale(), 'en'])));

        return view('repositories.index', ['repositories' => $repositories]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()->may('create', 'repository'), 403);

        return view('repositories.create');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->may('create', 'repository'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:1024'],
            'identifier' => ['nullable', 'string', 'max:255'],
            'language' => ['required', 'string', fn ($a, $v, $fail) => Bcp47::valid((string) $v) || $fail(__('Give a BCP 47 language tag, such as en or af.'))],
        ]);
        try {
            $repository = $this->repositories->create($request->user()->institution, $data['name'], $data['language'], $data['identifier'] ?? null, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['identifier' => $e->getMessage()]);
        }

        // Straight to the full description, so every ISDIAH element is in view.
        return redirect()->route('repositories.edit', [$repository, 'language' => $data['language']])->with('status', __('Repository created. Describe it now, or later.'));
    }

    public function show(Request $request, Repository $repository): View
    {
        $this->own($request, $repository);
        $repository->load('descriptionValues.element.labels', 'descriptionValues.state.labels', 'names.nameType.labels', 'names.state.labels', 'names.dates.property', 'identifiers.identifierType.labels', 'contactPoints', 'descriptionStatus.labels', 'descriptionDetail.labels',
            'dates.property.labels', 'dates.dateType.labels', 'dates.qualifier.labels');

        return view('repositories.show', ['repository' => $repository, 'elements' => $this->elements(), 'dateChoices' => app(DateService::class)->choices('repository'), 'relations' => app(RelationService::class)->of($repository)]);
    }

    public function edit(Request $request, Repository $repository): View
    {
        $this->own($request, $repository, 'update');
        $language = (string) $request->query('language', 'en');
        abort_unless(Bcp47::valid($language), 404);
        $repository->load('descriptionValues.element', 'names.nameType', 'identifiers.identifierType', 'descriptionStatus', 'descriptionDetail');

        return view('repositories.edit', ['repository' => $repository, 'elements' => $this->elements(), 'language' => $language,
            'statuses' => $this->concepts(Vocab::DESCRIPTION_STATUSES), 'details' => $this->concepts(Vocab::DESCRIPTION_DETAILS), 'types' => $this->concepts(Vocab::BASE.'repository-type/')]);
    }

    public function update(Request $request, Repository $repository): RedirectResponse
    {
        $this->own($request, $repository, 'update');
        $notations = $this->elements()->pluck('notation')->all();
        $data = $request->validate([
            'language' => ['required', 'string', fn ($a, $v, $fail) => Bcp47::valid((string) $v) || $fail(__('Give a BCP 47 language tag, such as en or af.'))],
            'identifier' => ['nullable', 'string', 'max:255'],
            'complete_reference_codes' => ['boolean'],
            'repository_type' => ['nullable', 'string', Rule::in($this->concepts(Vocab::BASE.'repository-type/')->pluck('iri'))],
            'values' => ['array'],
            'values.*' => ['nullable', 'max:65535'], 'values.*.*' => ['nullable', 'string', 'max:8'],
            'description_identifier' => ['nullable', 'string', 'max:255'],
            'description_status' => ['nullable', 'string', Rule::in($this->concepts(Vocab::DESCRIPTION_STATUSES)->pluck('iri'))],
            'description_detail' => ['nullable', 'string', Rule::in($this->concepts(Vocab::DESCRIPTION_DETAILS)->pluck('iri'))],
            'upload_limit_gb' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ]);
        $values = array_intersect_key($data['values'] ?? [], array_flip($notations));
        // The upload limit is the institution's to set, as Heratio's admin-only editUploadLimit; an editor cannot raise it.
        $setsLimit = $request->has('upload_limit_gb') && $request->user()->can(Vocab::permission('manage-institution'));
        try {
            // One transaction: a refused description leaves the identifier unchanged too.
            DB::transaction(function () use ($repository, $data, $values, $request, $setsLimit) {
                if ($setsLimit) {
                    $this->repositories->setUploadLimit($repository, isset($data['upload_limit_gb']) ? (float) $data['upload_limit_gb'] : null, $request->user());
                }
                $this->repositories->updateIdentity($repository, $data['identifier'] ?? null, $request->user());
                $this->repositories->setType($repository, $data['repository_type'] ?? null, $request->user());
                $this->repositories->setCompleteReferenceCodes($repository, (bool) ($data['complete_reference_codes'] ?? $repository->complete_reference_codes), $request->user());
                $this->repositories->updateControl($repository, $data['description_identifier'] ?? null, $data['description_status'] ?? null, $data['description_detail'] ?? null, $request->user());
                $this->repositories->describe($repository, $values, $data['language'], $request->user());
            });
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['values' => $e->getMessage()]);
        }

        return redirect()->route('repositories.show', $repository)->with('status', __('Description saved.'));
    }

    /** AtoM's edit-theme: background colour, banner, logo and page content of the repository's public page. */
    public function editTheme(Request $request, Repository $repository): View
    {
        $this->own($request, $repository, 'update');

        return view('repositories.theme', ['repository' => $repository]);
    }

    public function updateTheme(Request $request, Repository $repository): RedirectResponse
    {
        $this->own($request, $repository, 'update');
        $data = $request->validate([
            'background_colour' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'content' => ['nullable', 'string', 'max:20000'],
            'banner' => ['nullable', 'file', 'mimes:jpeg,png,webp', 'max:5120'],
            'logo' => ['nullable', 'file', 'mimes:jpeg,png,webp', 'max:5120'],
            'remove_banner' => ['boolean'], 'remove_logo' => ['boolean'], 'no_background' => ['boolean'],
        ]);
        if ($request->boolean('no_background')) {
            $data['background_colour'] = null;
        }
        $this->repositories->updateTheme($repository, $data, $request->file('banner'), $request->file('logo'), $request->user());

        return redirect()->route('repositories.show', $repository)->with('status', __('Theme saved.'));
    }

    /** The banner or logo, for the theme form; the public catalogue serves its own. */
    public function themeImage(Request $request, Repository $repository, string $part): BinaryFileResponse
    {
        $this->own($request, $repository);
        $path = $repository->getAttribute("theme_{$part}_path");
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path));
    }

    public function storeContact(Request $request, Repository $repository): RedirectResponse
    {
        $this->own($request, $repository, 'update');
        $this->contacts->save($repository, $this->contactFields($request), null, $request->user());

        return redirect()->route('repositories.show', $repository)->with('status', __('Contact point added.'));
    }

    public function updateContact(Request $request, Repository $repository, ContactPoint $contact): RedirectResponse
    {
        $this->ownContact($request, $repository, $contact);
        $this->contacts->save($repository, $this->contactFields($request), $contact, $request->user());

        return redirect()->route('repositories.show', $repository)->with('status', __('Contact point saved.'));
    }

    public function destroyContact(Request $request, Repository $repository, ContactPoint $contact): RedirectResponse
    {
        $this->ownContact($request, $repository, $contact);
        $this->contacts->remove($contact, $request->user());

        return redirect()->route('repositories.show', $repository)->with('status', __('Contact point removed.'));
    }

    private function contactFields(Request $request): array
    {
        return $this->contacts->fieldsFrom($request);
    }

    private function ownContact(Request $request, Repository $repository, ContactPoint $contact): void
    {
        $this->own($request, $repository, 'update');
        abort_unless($contact->subject_table === $repository->getTable() && $contact->subject_id === $repository->id, 404);
    }

    private function concepts(string $vocabularyIri)
    {
        return Concept::current()->whereHas('vocabulary', fn ($q) => $q->where('iri', $vocabularyIri))->with('labels')->orderBy('id')->get();
    }

    /** Another institution's repository does not exist here (404); without the permission, 403 (ADR 0005). */
    private function own(Request $request, Repository $repository, string $action = 'read'): void
    {
        abort_unless($repository->institution_id === $request->user()->institution_id && $repository->deleted_at === null, 404);
        abort_unless($request->user()->may($action, 'repository', $repository), 403);
    }

    private function elements()
    {
        return Elements::of(Elements::ISDIAH);
    }
}
