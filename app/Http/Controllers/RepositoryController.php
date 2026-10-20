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
use App\Description\RepositoryService;
use App\Models\Concept;
use App\Models\ContactPoint;
use App\Models\Repository;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class RepositoryController extends Controller
{
    public function __construct(private RepositoryService $repositories, private ContactPointService $contacts) {}

    public function index(Request $request): View
    {
        $repositories = Repository::where('institution_id', $request->user()->institution_id)->whereNull('deleted_at')
            ->with('descriptionValues.element')->get()
            ->sortBy(fn (Repository $r) => mb_strtolower((string) $r->described('authorised-name', [app()->getLocale(), 'en'])));

        return view('repositories.index', ['repositories' => $repositories]);
    }

    public function create(): View
    {
        return view('repositories.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:1024'],
            'identifier' => ['nullable', 'string', 'max:255', Rule::unique('repositories')->where('institution_id', $request->user()->institution_id)],
            'language' => ['required', 'string', fn ($a, $v, $fail) => Bcp47::valid((string) $v) || $fail('Give a BCP 47 language tag, such as en or af.')],
        ]);
        $repository = $this->repositories->create($request->user()->institution, $data['name'], $data['language'], $data['identifier'] ?? null, $request->user());

        return redirect()->route('repositories.show', $repository)->with('status', 'Repository created.');
    }

    public function show(Request $request, Repository $repository): View
    {
        $this->own($request, $repository);
        $repository->load('descriptionValues.element.labels', 'descriptionValues.state.labels', 'contactPoints', 'descriptionStatus.labels', 'descriptionDetail.labels');

        return view('repositories.show', ['repository' => $repository, 'elements' => $this->elements()]);
    }

    public function edit(Request $request, Repository $repository): View
    {
        $this->own($request, $repository);
        $language = (string) $request->query('language', 'en');
        abort_unless(Bcp47::valid($language), 404);
        $repository->load('descriptionValues.element', 'descriptionStatus', 'descriptionDetail');

        return view('repositories.edit', ['repository' => $repository, 'elements' => $this->elements(), 'language' => $language,
            'statuses' => $this->concepts(Vocab::DESCRIPTION_STATUSES), 'details' => $this->concepts(Vocab::DESCRIPTION_DETAILS)]);
    }

    public function update(Request $request, Repository $repository): RedirectResponse
    {
        $this->own($request, $repository);
        $notations = $this->elements()->pluck('notation')->all();
        $data = $request->validate([
            'language' => ['required', 'string', fn ($a, $v, $fail) => Bcp47::valid((string) $v) || $fail('Give a BCP 47 language tag, such as en or af.')],
            'identifier' => ['nullable', 'string', 'max:255', Rule::unique('repositories')->where('institution_id', $repository->institution_id)->ignore($repository->id)],
            'values' => ['array'],
            'values.*' => ['nullable', 'string', 'max:65535'],
            'description_identifier' => ['nullable', 'string', 'max:255'],
            'description_status' => ['nullable', 'string', Rule::in($this->concepts(Vocab::DESCRIPTION_STATUSES)->pluck('iri'))],
            'description_detail' => ['nullable', 'string', Rule::in($this->concepts(Vocab::DESCRIPTION_DETAILS)->pluck('iri'))],
        ]);
        $values = array_intersect_key($data['values'] ?? [], array_flip($notations));
        try {
            // One transaction: a refused description leaves the identifier unchanged too.
            DB::transaction(function () use ($repository, $data, $values, $request) {
                $this->repositories->updateIdentity($repository, $data['identifier'] ?? null, $request->user());
                $this->repositories->updateControl($repository, $data['description_identifier'] ?? null, $data['description_status'] ?? null, $data['description_detail'] ?? null, $request->user());
                $this->repositories->describe($repository, $values, $data['language'], $request->user());
            });
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['values' => $e->getMessage()]);
        }

        return redirect()->route('repositories.show', $repository)->with('status', 'Description saved.');
    }

    public function storeContact(Request $request, Repository $repository): RedirectResponse
    {
        $this->own($request, $repository);
        $this->contacts->save($repository, $this->contactFields($request), null, $request->user());

        return redirect()->route('repositories.show', $repository)->with('status', 'Contact point added.');
    }

    public function updateContact(Request $request, Repository $repository, ContactPoint $contact): RedirectResponse
    {
        $this->ownContact($request, $repository, $contact);
        $this->contacts->save($repository, $this->contactFields($request), $contact, $request->user());

        return redirect()->route('repositories.show', $repository)->with('status', 'Contact point saved.');
    }

    public function destroyContact(Request $request, Repository $repository, ContactPoint $contact): RedirectResponse
    {
        $this->ownContact($request, $repository, $contact);
        $this->contacts->remove($contact, $request->user());

        return redirect()->route('repositories.show', $repository)->with('status', 'Contact point removed.');
    }

    /** The whole contact point: a field the form did not send is cleared, not kept. */
    private function contactFields(Request $request): array
    {
        $sent = $request->validate([
            'is_primary' => ['nullable', 'boolean'],
            'label' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'street_address' => ['nullable', 'string', 'max:2000'],
            'city' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:64'],
            'country_code' => ['nullable', 'string', 'regex:/^[A-Za-z]{2}$/'],
            'telephone' => ['nullable', 'string', 'max:64'],
            'fax' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url:http,https', 'max:1024'],
        ]);

        // + keeps the left-hand keys, so what was sent wins over the empty defaults.
        return $sent + array_fill_keys(ContactPoint::FIELDS, null);
    }

    private function ownContact(Request $request, Repository $repository, ContactPoint $contact): void
    {
        $this->own($request, $repository);
        abort_unless($contact->subject_table === $repository->getTable() && $contact->subject_id === $repository->id, 404);
    }

    private function concepts(string $vocabularyIri)
    {
        return Concept::current()->whereHas('vocabulary', fn ($q) => $q->where('iri', $vocabularyIri))->with('labels')->orderBy('id')->get();
    }

    private function own(Request $request, Repository $repository): void
    {
        abort_unless($repository->institution_id === $request->user()->institution_id && $repository->deleted_at === null, 404);
    }

    /** Elements of the vocabulary in its own order (concepts are created in file order, and ids are time-ordered). */
    private function elements()
    {
        return Concept::current()->whereHas('vocabulary', fn ($q) => $q->where('iri', Vocab::DESCRIPTION_ELEMENTS))
            ->with('labels')->orderBy('id')->get();
    }
}
