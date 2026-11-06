<?php

/*
 * OpenRiC Workspace - the languages of the collection and its research leads in the public catalogue (roadmap phase
 * 17d4), copied from Heratio, packages/ahg-semantic-search/src/Controllers/LanguageCorpusController.php (index, show,
 * contribute, translate), LanguageTranscriptionController.php (form, contribute) and ResearchLeadsController.php
 * (index, show) at commit bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md). Changed: only what
 * the public catalogue shows; a contribution through a hidden field and a rate limit, as the Workspace's other public
 * forms; a passage translated only where the institution has AI on, with its receipt; the namespace and code style.
 * Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Http\Controllers;

use App\Ai\AiUnavailable;
use App\Ai\Gateway;
use App\Api\PublicCatalogue;
use App\Language\Contributions;
use App\Language\Corpus;
use App\Models\Concept;
use App\Models\RecordResource;
use App\Scholarship\ResearchLeads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class PublicLanguageController extends Controller
{
    public function __construct(private Corpus $corpus, private Contributions $contributions, private ResearchLeads $leads, private Gateway $gateway) {}

    public function index(Request $request): View
    {
        $catalogue = $this->catalogue($request);

        return view('discover.languages.index', ['view' => $catalogue->view, 'languages' => $this->corpus->languages($catalogue->institution), 'disclaimer' => Corpus::DISCLAIMER]);
    }

    public function show(Request $request, string $code): View
    {
        $catalogue = $this->catalogue($request);
        $code = Corpus::language($code) ?? abort(404);
        $institution = $catalogue->institution;

        return view('discover.languages.show', ['view' => $catalogue->view, 'code' => $code, 'label' => Corpus::label($code),
            'records' => $this->corpus->describedRecords($institution, $code), 'terms' => $this->corpus->terms($institution, $code),
            'transcriptions' => $this->corpus->transcriptions($institution, $code), 'contributed' => $this->contributions->approvedForLanguage($institution, $code)
                ->filter(fn ($c) => $catalogue->find('records', $c->record_resource_id) !== null)->values(),
            'glossary' => $this->corpus->glossary($institution, $code), 'translatable' => $this->gateway->refusal($institution->id) === null,
            'disclaimer' => Corpus::DISCLAIMER, 'mtLabel' => Corpus::MT_LABEL]);
    }

    public function contributeGlossary(Request $request, string $code): RedirectResponse
    {
        $catalogue = $this->catalogue($request);
        $code = Corpus::language($code) ?? abort(404);
        if ($request->filled('website')) {
            return redirect()->route('discover.languages.show', $code);
        }
        $data = $request->validate(['term' => ['required', 'string', 'max:512'], 'meaning' => ['required', 'string', 'max:20000'], 'usage_example' => ['nullable', 'string', 'max:20000'],
            'source' => ['nullable', 'string', 'max:512'], 'contributor_name' => ['nullable', 'string', 'max:255']]);
        $this->attempt(fn () => $this->corpus->contribute($catalogue->institution, [...$data, 'language' => $code], $request->user()));

        return redirect()->route('discover.languages.show', $code)->with('status', __('Thank you. Your glossary entry has been submitted and will appear once a reviewer has approved it.'));
    }

    /** A passage, translated by machine and labelled as such. */
    public function translate(Request $request, string $code): JsonResponse
    {
        $catalogue = $this->catalogue($request);
        $code = Corpus::language($code) ?? abort(404);
        $data = $request->validate(['text' => ['required', 'string', 'max:5000'], 'target' => ['nullable', 'string', 'max:35']]);
        try {
            $out = $this->corpus->translate($catalogue->institution, $data['text'], $data['target'] ?? 'en', $code, $request->user());
        } catch (AiUnavailable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 503);
        }

        return response()->json($out === null ? ['ok' => false] : ['ok' => true, 'text' => $out['text'], 'label' => __($out['label'])]);
    }

    public function transcribe(Request $request, string $id): View
    {
        $catalogue = $this->catalogue($request);
        $unit = $catalogue->find('records', $id) ?? abort(404);

        return view('discover.languages.transcribe', ['view' => $catalogue->view, 'unit' => $unit, 'types' => Concept::choices(Contributions::TYPES)->get(),
            'approved' => $this->contributions->approvedForItem($unit), 'language' => Corpus::language($unit->originalLanguage('title')), 'disclaimer' => Contributions::DISCLAIMER,
            'languages' => $this->corpus->languages($catalogue->institution)]);
    }

    public function contribute(Request $request, string $id): RedirectResponse
    {
        $catalogue = $this->catalogue($request);
        /** @var RecordResource $unit */
        $unit = $catalogue->find('records', $id) ?? abort(404);
        if ($request->filled('website')) {
            return redirect()->route('discover.transcribe', $unit->id);
        }
        $data = $request->validate(['type' => ['required', 'string', 'max:64'], 'language' => ['nullable', 'string', 'max:35'], 'body' => ['required', 'string', 'max:60000'],
            'source' => ['nullable', 'string', 'max:512'], 'contributor_name' => ['nullable', 'string', 'max:255']]);
        $this->attempt(fn () => $this->contributions->contribute($unit, [...$data, 'credit_consent' => $request->boolean('credit_consent')], $request->user()));

        return redirect()->route('discover.transcribe', $unit->id)->with('status', __('Thank you. Your contribution has been submitted and will appear once a reviewer has approved it.'));
    }

    public function leads(Request $request): View
    {
        $catalogue = $this->catalogue($request);

        return view('discover.research-leads.index', ['view' => $catalogue->view, 'leads' => $this->leads->publicFeed($catalogue), 'disclaimer' => ResearchLeads::DISCLAIMER]);
    }

    public function lead(Request $request, string $id): View
    {
        $catalogue = $this->catalogue($request);
        $lead = $this->leads->publicLead($catalogue, $id) ?? abort(404);

        return view('discover.research-leads.show', ['view' => $catalogue->view, 'lead' => $lead, 'unit' => $catalogue->find('records', $lead->record_resource_id),
            'links' => ResearchLeads::links($lead->evidence), 'disclaimer' => ResearchLeads::DISCLAIMER]);
    }

    private function catalogue(Request $request): PublicCatalogue
    {
        return $request->attributes->get('catalogue');
    }

    private function attempt(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['contribution' => $e->getMessage()]);
        }
    }
}
