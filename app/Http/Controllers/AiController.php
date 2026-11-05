<?php

// OpenRiC Workspace - AI (roadmap phase 17a), after Heratio's ahg-ai-services AiController and ahg-provenance-ai's
// GovernanceController and ProvenanceTraceController: whether the institution uses the AI gateway and how much, the log
// of inferences with each call's trace to the proposals it made and who reviewed them, the chain checked, and a page's
// text read by a vision model. Who administers the institution sets AI up and reads the log; who may update a
// description asks for its pages to be read.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Ai\Gateway;
use App\Ai\Inferences;
use App\Ai\Translate;
use App\Audit\AuditLog;
use App\Jobs\AskModel;
use App\Jobs\JobsService;
use App\Jobs\ReadPageText;
use App\Models\AiInference;
use App\Models\Concept;
use App\Models\Instantiation;
use App\Models\RecordResource;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AiController extends Controller
{
    public function __construct(private Gateway $gateway, private Inferences $inferences) {}

    public function index(Request $request): View
    {
        $institution = $request->user()->institution_id;

        return view('ai.index', ['settings' => $this->gateway->settings($institution), 'configured' => $this->gateway->configured(),
            'refusal' => $this->gateway->refusal($institution), 'used' => $this->inferences->thisMonth($institution),
            'byService' => AiInference::where('ai_inferences.institution_id', $institution)->where('occurred_at', '>=', now()->startOfMonth())
                ->join('concepts as c', 'c.id', '=', 'ai_inferences.service_id')->selectRaw('c.notation, count(*) as n, sum(case when succeeded then 0 else 1 end) as failed')
                ->groupBy('c.notation')->get(), 'chain' => session('chain')]);
    }

    public function save(Request $request): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['nullable', 'boolean'], 'monthly_limit' => ['nullable', 'integer', 'between:0,10000000'],
            'ocr_model' => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9._:\/-]+$/'], 'ocr_fallback_model' => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
            'chat_model' => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
            'translation_target' => ['nullable', Rule::in((array) config('workspace.ai.translation_targets'))], 'translation_elements' => ['nullable', 'array'],
            'translation_elements.*' => [Rule::in(app(Translate::class)->elements()->pluck('notation'))]]);
        $settings = $this->gateway->settings($request->user()->institution_id);
        $settings->fill(['enabled' => $request->boolean('enabled'), 'monthly_limit' => $data['monthly_limit'] ?? null, 'ocr_model' => $data['ocr_model'] ?? null,
            'ocr_fallback_model' => $data['ocr_fallback_model'] ?? null, 'chat_model' => $data['chat_model'] ?? null,
            'translation_target' => $data['translation_target'] ?? null, 'translation_elements' => array_values($data['translation_elements'] ?? []), 'updated_by_id' => $request->user()->id]);
        AuditLog::actingAs($request->user(), fn () => $settings->save());

        return back()->with('status', __('Saved.'));
    }

    /** Heratio's chain verification: every receipt's hash and signature checked, from the first. */
    public function verify(): RedirectResponse
    {
        return back()->with('chain', $this->inferences->verify());
    }

    public function inferences(Request $request): View
    {
        $service = (string) $request->query('service');

        return view('ai.inferences', ['services' => Concept::choices(Vocab::BASE.'ai-service/')->get(), 'service' => $service,
            'inferences' => AiInference::where('institution_id', $request->user()->institution_id)->with('service.labels', 'user')->withCount('proposals')
                ->when($service !== '', fn ($q) => $q->whereHas('service', fn ($s) => $s->where('notation', $service)))->orderByDesc('seq')->paginate(50)->withQueryString()]);
    }

    /** One call's trace: what was asked and answered, its receipt, the proposals it made and who reviewed them, and the other strips of its page. */
    public function inference(Request $request, AiInference $inference): View
    {
        abort_unless($inference->institution_id === $request->user()->institution_id, 404);
        $reading = $inference->details['reading'] ?? null;

        return view('ai.inference', ['inference' => $inference->load('service.labels', 'user'), 'subject' => $inference->subject(),
            'proposals' => $inference->proposals()->with('kind.labels', 'status.labels', 'reviewedBy', 'proposedBy')->get(),
            'siblings' => $reading ? AiInference::where('details->reading', $reading)->whereKeyNot($inference->id)->orderBy('seq')->get() : collect()]);
    }

    /** Ask a model to find a unit's names or summarise its pages (phase 17b1); its answers wait for review. */
    public function ask(Request $request, RecordResource $record, string $task): RedirectResponse
    {
        $user = $request->user();
        abort_unless($record->institution_id === $user->institution_id && $record->deleted_at === null && in_array($task, ['names', 'summary'], true), 404);
        abort_unless($user->may('update', 'description', $record), 403);

        return $this->queue($request, $task, $record, $task === 'names' ? __('Finding names in :title', ['title' => $record->described('title', ['en'])])
            : __('Summarising the pages of :title', ['title' => $record->described('title', ['en'])]));
    }

    /** Ask a vision model to assess the condition of the object an instantiation pictures (phase 17b1). */
    public function condition(Request $request, Instantiation $instantiation): RedirectResponse
    {
        $user = $request->user();
        abort_unless($instantiation->institution_id === $user->institution_id && $instantiation->deleted_at === null, 404);
        abort_unless($user->may('update', 'description', $instantiation->record), 403);
        abort_if(ReadPageText::image($instantiation) === null, 404);

        return $this->queue($request, 'condition', $instantiation, __('Assessing the condition shown in :name', ['name' => $instantiation->file('master')?->original_name ?? $instantiation->id]));
    }

    private function queue(Request $request, string $task, RecordResource|Instantiation $subject, string $name): RedirectResponse
    {
        if ($reason = $this->gateway->refusal($request->user()->institution_id)) {
            return back()->withErrors(['ai' => $reason]);
        }
        $job = app(JobsService::class)->create($request->user()->institution_id, 'ai-assist', $name, $request->user(), $subject);
        AskModel::dispatch($job->id, $task, $subject->id);

        return back()->with('status', __('The model has been asked. What it answers will wait for review in the proposals queue.'));
    }

    /** Read the text of an instantiation's page image, as a proposed transcript. */
    public function readText(Request $request, Instantiation $instantiation): RedirectResponse
    {
        $user = $request->user();
        abort_unless($instantiation->institution_id === $user->institution_id && $instantiation->deleted_at === null, 404);
        abort_unless($user->may('update', 'description', $instantiation->record), 403);
        if ($reason = $this->gateway->refusal($user->institution_id)) {
            return back()->withErrors(['ai' => $reason]);
        }
        abort_if(ReadPageText::image($instantiation) === null, 404);
        $job = app(JobsService::class)->create($user->institution_id, 'read-page-text', __('Reading the text of :name', ['name' => $instantiation->file('master')?->original_name ?? $instantiation->id]), $user, $instantiation);
        ReadPageText::dispatch($job->id, $instantiation->id);

        return back()->with('status', __('The page is being read. Its text will wait for review in the proposals queue.'));
    }
}
