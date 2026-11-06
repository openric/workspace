<?php

/*
 * OpenRiC Workspace - generative scholarship, research leads and the review of language contributions for staff
 * (roadmap phase 17d4), copied from Heratio, packages/ahg-semantic-search/src/Controllers/ScholarshipController.php
 * (show: the discovery for one record, and across federation peers), DiscoveriesController.php (index, the cards
 * and their bands), ResearchLeadAdminController.php (the curation list, generate, publish, dismiss, back to pending),
 * LanguageCorpusController.php and LanguageTranscriptionController.php (moderate, moderateSet) and the console
 * commands ahg:scholarship-discover, ahg:generate-discoveries and ahg:generate-research-leads, at commit
 * bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md). Changed: discovering and generating run
 * in the background through the gateway (AskModel); the discoveries are staff's, curated by those who may publish
 * descriptions; contributions are reviewed by those who may change descriptions (Heratio: administrators); the
 * namespace and code style.
 * Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Http\Controllers;

use App\Ai\Gateway;
use App\Jobs\AskModel;
use App\Jobs\JobsService;
use App\Language\Contributions;
use App\Language\Corpus;
use App\Models\Concept;
use App\Models\LanguageContribution;
use App\Models\LanguageGlossaryEntry;
use App\Models\RecordResource;
use App\Models\ResearchLead;
use App\Models\ScholarshipDiscovery;
use App\Models\ScholarshipFederatedDiscovery;
use App\Relations\EntityKinds;
use App\Scholarship\ResearchLeads;
use App\Scholarship\Scholarship;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class ScholarshipController extends Controller
{
    public function __construct(private Gateway $gateway, private Scholarship $scholarship, private ResearchLeads $leads, private Contributions $contributions) {}

    /** The discoveries, the most confident first. */
    public function index(Request $request): View
    {
        $this->curates($request);
        $institution = $request->user()->institution;

        return view('scholarship.index', ['discoveries' => ScholarshipDiscovery::where('institution_id', $institution->id)->with('record')->orderByDesc('confidence')->orderByDesc('generated_at')->limit(200)->get(),
            'counts' => $this->leads->counts($institution), 'refusal' => $this->gateway->refusal($institution->id), 'disclaimer' => ResearchLeads::DISCLAIMER]);
    }

    public function generate(Request $request): RedirectResponse
    {
        $this->curates($request);
        $data = $request->validate(['limit' => ['nullable', 'integer', 'between:1,200']]);

        return $this->queue($request, 'discoveries', $request->user()->institution_id, ['limit' => (int) ($data['limit'] ?? 25)], __('Refreshing the discoveries'),
            __('The discoveries are being refreshed, the most connected records first.'));
    }

    /** One record's discovery, and what federation peers hold that shares its access points. */
    public function show(Request $request, RecordResource $record): View
    {
        $this->curates($request, $record);

        return view('scholarship.show', ['record' => $record, 'discovery' => ScholarshipDiscovery::where('record_resource_id', $record->id)->first(),
            'federated' => ScholarshipFederatedDiscovery::where('record_resource_id', $record->id)->first(),
            'lead' => ResearchLead::where('record_resource_id', $record->id)->with('status.labels')->first(), 'refusal' => $this->gateway->refusal($record->institution_id),
            'disclaimer' => ResearchLeads::DISCLAIMER]);
    }

    public function discover(Request $request, RecordResource $record): RedirectResponse
    {
        $this->curates($request, $record);

        return $this->queue($request, 'discover', $record->id, [], __('Discovering connections around :title', ['title' => EntityKinds::label($record)]),
            __('The model has been asked. What it finds will be shown here for a curator.'));
    }

    public function federated(Request $request, RecordResource $record): RedirectResponse
    {
        $this->curates($request, $record);
        $this->scholarship->federated($record, true, $request->user());

        return redirect()->route('scholarship.show', $record)->with('status', __('Federation peers searched.'));
    }

    public function leads(Request $request): View
    {
        $this->curates($request);
        $institution = $request->user()->institution;
        $status = Concept::where('iri', ResearchLeads::STATUSES.$request->query('status'))->exists() ? $request->query('status') : null;

        return view('scholarship.leads', ['leads' => $this->leads->list($institution, $status), 'status' => $status, 'statuses' => Concept::choices(ResearchLeads::STATUSES)->get(),
            'counts' => $this->leads->counts($institution), 'refusal' => $this->gateway->refusal($institution->id), 'disclaimer' => ResearchLeads::DISCLAIMER]);
    }

    public function generateLeads(Request $request): RedirectResponse
    {
        $this->curates($request);
        $data = $request->validate(['limit' => ['nullable', 'integer', 'between:1,200']]);
        $enrich = $request->boolean('enrich');
        // Promoting needs no model; writing "why it matters" does.
        if ($enrich && ($reason = $this->gateway->refusal($request->user()->institution_id))) {
            return back()->withErrors(['ai' => $reason]);
        }
        if (! $enrich) {
            $result = $this->leads->generate($request->user()->institution, (int) ($data['limit'] ?? 25), false, $request->user());

            return redirect()->route('scholarship.leads')->with('status', __(':promoted promoted, :refreshed refreshed.', ['promoted' => $result['promoted'], 'refreshed' => $result['refreshed']]));
        }

        return $this->queue($request, 'leads', $request->user()->institution_id, ['limit' => (int) ($data['limit'] ?? 25), 'enrich' => true], __('Promoting discoveries to research leads'),
            __('The discoveries are being promoted, a model writing why each might matter.'), route('scholarship.leads'));
    }

    public function curate(Request $request, ResearchLead $lead, string $status): RedirectResponse
    {
        $this->curates($request);
        abort_unless($lead->institution_id === $request->user()->institution_id, 404);
        try {
            $this->leads->curate($lead, $status, $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['lead' => $e->getMessage()]);
        }

        return back()->with('status', __('Saved.'));
    }

    /** What people contributed about the languages of the collection, to review. */
    public function languageQueue(Request $request): View
    {
        abort_unless($request->user()->mayAnywhere('update', 'description'), 403);
        $institution = $request->user()->institution;
        $status = Concept::where('iri', Corpus::STATUSES.$request->query('status'))->exists() ? $request->query('status') : 'pending';

        return view('languages.review', [...$this->contributions->queue($institution, $status), 'status' => $status, 'statuses' => Concept::choices(Corpus::STATUSES)->get(),
            'counts' => $this->contributions->counts($institution), 'disclaimer' => Contributions::DISCLAIMER]);
    }

    public function moderateContribution(Request $request, LanguageContribution $contribution): RedirectResponse
    {
        return $this->moderate($request, $contribution);
    }

    public function moderateEntry(Request $request, LanguageGlossaryEntry $entry): RedirectResponse
    {
        return $this->moderate($request, $entry);
    }

    private function moderate(Request $request, LanguageContribution|LanguageGlossaryEntry $item): RedirectResponse
    {
        abort_unless($request->user()->mayAnywhere('update', 'description'), 403);
        abort_unless($item->institution_id === $request->user()->institution_id, 404);
        $data = $request->validate(['status' => ['required', 'string', 'max:64']]);
        try {
            $this->contributions->moderate($item, $data['status'], $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return back()->with('status', __('Moderation saved.'));
    }

    private function queue(Request $request, string $task, string $subjectId, array $options, string $name, string $done, ?string $to = null): RedirectResponse
    {
        if ($reason = $this->gateway->refusal($request->user()->institution_id)) {
            return back()->withErrors(['ai' => $reason]);
        }
        $job = app(JobsService::class)->create($request->user()->institution_id, 'ai-assist', $name, $request->user(), $task === 'discover' ? RecordResource::find($subjectId) : null);
        AskModel::dispatch($job->id, $task, $subjectId, $options);

        return ($to ? redirect()->to($to) : back())->with('status', $done);
    }

    /** Who curates discoveries and leads: who may publish descriptions; a record of their institution. */
    private function curates(Request $request, ?RecordResource $record = null): void
    {
        abort_unless($request->user()->mayAnywhere('publish', 'description'), 403);
        abort_if($record && ($record->institution_id !== $request->user()->institution_id || $record->deleted_at !== null), 404);
    }
}
