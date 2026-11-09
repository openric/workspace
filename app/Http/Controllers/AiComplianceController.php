<?php

// OpenRiC Workspace - AI compliance (roadmap phase 17f), after Heratio's ahg-ai-compliance controllers: the register of
// the institution's AI systems with the oversight each is under and how reviewers dealt with its output, halting and
// resuming one; a system's page with its details, risks and incidents and its technical documentation (Annex IV, as a
// page and as Markdown); the models seen; and reviewers' attestations. For who may manage the institution, but the
// attestation, which every reviewer gives.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Ai\Compliance;
use App\Audit\AuditLog;
use App\Models\AiAttestation;
use App\Models\AiIncident;
use App\Models\AiModel;
use App\Models\AiRisk;
use App\Models\AiSystem;
use App\Models\Concept;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AiComplianceController extends Controller
{
    public function __construct(private Compliance $compliance) {}

    public function index(Request $request): View
    {
        $institution = $this->admin($request);

        return view('ai.compliance', ['systems' => $this->compliance->systems($institution), 'oversight' => $this->compliance->oversight($institution),
            'models' => $this->compliance->refreshModels($institution),
            'incidents' => AiIncident::where('institution_id', $institution->id)->whereNull('resolved_at')->with('system', 'severity.labels')->latest()->get(),
            'attestations' => AiAttestation::where('institution_id', $institution->id)->where('expires_at', '>', now())->with('user')->latest('attested_at')->get()]);
    }

    public function system(Request $request, AiSystem $system): View
    {
        $this->own($request, $system);
        $system->load('service.labels', 'risks.severity.labels', 'risks.likelihood.labels', 'risks.status.labels', 'risks.residual.labels', 'risks.use.labels', 'incidents.severity.labels');
        $choices = fn (string $v) => Concept::choices(Vocab::BASE."$v/")->get();

        return view('ai.system', ['system' => $system, 'annex' => $this->compliance->annexIv($system), 'classes' => $choices('ai-risk-class'),
            'lifecycles' => $choices('ai-lifecycle'), 'roles' => $choices('ai-role'), 'levels' => $choices('risk-level'), 'statuses' => $choices('ai-risk-status'), 'uses' => $choices('ai-risk-use')]);
    }

    public function update(Request $request, AiSystem $system): RedirectResponse
    {
        $this->own($request, $system);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'], 'purpose' => ['nullable', 'string', 'max:5000'],
            'provider' => ['nullable', 'string', 'max:255'], 'role' => ['required', 'string'], 'risk_class' => ['required', 'string'], 'lifecycle' => ['required', 'string'],
            'deployment_context' => ['nullable', 'string', 'max:5000'], 'human_oversight' => ['nullable', 'string', 'max:5000'], 'owner' => ['nullable', 'string', 'max:255'],
            'last_review_date' => ['nullable', 'date'], 'next_review_date' => ['nullable', 'date'], 'automation_bias_text' => ['nullable', 'string', 'max:512']]);
        DB::transaction(fn () => AuditLog::actingAs($request->user(), fn () => $system->update(['role_id' => $this->concept('ai-role', $data['role']),
            'risk_class_id' => $this->concept('ai-risk-class', $data['risk_class']), 'lifecycle_id' => $this->concept('ai-lifecycle', $data['lifecycle']),
            'requires_human_review' => $request->boolean('requires_human_review'), 'dual_review_required' => $request->boolean('dual_review_required')]
            + array_diff_key($data, array_flip(['role', 'risk_class', 'lifecycle'])))));

        return back()->with('status', __('Saved.'));
    }

    public function halt(Request $request, AiSystem $system): RedirectResponse
    {
        $this->own($request, $system);
        $system->halted_at
            ? $this->compliance->resume($system, $request->user())
            : $this->compliance->halt($system, (string) $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'], $request->user());

        return back()->with('status', $system->fresh()->halted_at ? __(':name is halted: the Workspace will not call it.', ['name' => $system->name]) : __(':name may be called again.', ['name' => $system->name]));
    }

    public function storeRisk(Request $request, AiSystem $system): RedirectResponse
    {
        $this->own($request, $system);
        $data = $this->riskData($request);
        DB::transaction(fn () => AuditLog::actingAs($request->user(), fn () => AiRisk::create(['institution_id' => $system->institution_id, 'system_id' => $system->id] + $data)));

        return back()->with('status', __('Saved.'));
    }

    public function updateRisk(Request $request, AiRisk $risk): RedirectResponse
    {
        $this->own($request, $risk->system);
        $data = $this->riskData($request);
        DB::transaction(fn () => AuditLog::actingAs($request->user(), fn () => $risk->update($data + ['last_reviewed_at' => now(), 'reviewer_id' => $request->user()->id])));

        return back()->with('status', __('Saved.'));
    }

    public function storeIncident(Request $request, AiSystem $system): RedirectResponse
    {
        $this->own($request, $system);
        $data = $request->validate(['description' => ['required', 'string', 'max:5000'], 'severity' => ['required', 'string'], 'risk_id' => ['nullable', 'uuid']]);
        $risk = ($data['risk_id'] ?? null) ? AiRisk::where('system_id', $system->id)->findOrFail($data['risk_id'])->id : null;
        DB::transaction(fn () => AuditLog::actingAs($request->user(), fn () => AiIncident::create(['institution_id' => $system->institution_id, 'system_id' => $system->id,
            'risk_id' => $risk, 'reporter_id' => $request->user()->id, 'description' => $data['description'], 'severity_id' => $this->concept('risk-level', $data['severity'])])));

        return back()->with('status', __('Saved.'));
    }

    public function resolveIncident(Request $request, AiIncident $incident): RedirectResponse
    {
        $this->own($request, $incident->system);
        $data = $request->validate(['resolution' => ['required', 'string', 'max:5000']]);
        DB::transaction(fn () => AuditLog::actingAs($request->user(), fn () => $incident->update(['resolved_at' => now(), 'resolution' => $data['resolution']])));

        return back()->with('status', __('Saved.'));
    }

    public function updateModel(Request $request, AiModel $model): RedirectResponse
    {
        abort_unless($model->institution_id === $this->admin($request)->id, 404);
        $data = $request->validate(['training_data_summary' => ['nullable', 'string', 'max:5000'], 'known_limits' => ['nullable', 'string', 'max:5000'], 'intended_purpose' => ['nullable', 'string', 'max:5000']]);
        DB::transaction(fn () => AuditLog::actingAs($request->user(), fn () => $model->update($data + ['retired_at' => $request->boolean('retired') ? ($model->retired_at ?? now()) : null])));

        return back()->with('status', __('Saved.'));
    }

    /** A system's technical documentation as Markdown, to keep or send (Heratio's ahg:ai-annex-iv). */
    public function annex(Request $request, AiSystem $system): Response
    {
        $this->own($request, $system);
        $text = '# '.__('Technical documentation: :name', ['name' => $system->name])."\n\n".collect($this->compliance->annexIv($system))
            ->map(fn ($s) => '## '.$s[0]."\n\n".$s[1])->join("\n\n")."\n\n".__('Made by the OpenRiC Workspace on :date.', ['date' => now()->toDateString()])."\n";

        return response($text, 200, ['Content-Type' => 'text/markdown; charset=utf-8', 'Content-Disposition' => 'attachment; filename="annex-iv-'.$system->service->notation.'.md"']);
    }

    /** A reviewer attests that they have read how to review AI output (Heratio's operator attestation). */
    public function attestation(Request $request): View
    {
        abort_unless($request->user()->mayAnywhere('review-proposals', 'description'), 403);

        return view('ai.attestation', ['current' => $this->compliance->attestation($request->user())]);
    }

    public function attest(Request $request): RedirectResponse
    {
        abort_unless($request->user()->mayAnywhere('review-proposals', 'description'), 403);
        $request->validate(['read' => ['accepted']]);
        $this->compliance->attest($request->user());

        return back()->with('status', __('Thank you. Your attestation holds for a year.'));
    }

    private function riskData(Request $request): array
    {
        $data = $request->validate(['description' => ['required', 'string', 'max:512'], 'severity' => ['required', 'string'], 'likelihood' => ['required', 'string'],
            'use' => ['required', 'string'], 'affected_group' => ['nullable', 'string', 'max:255'], 'mitigation' => ['nullable', 'string', 'max:5000'],
            'residual' => ['required', 'string'], 'status' => ['required', 'string']]);

        return ['description' => $data['description'], 'severity_id' => $this->concept('risk-level', $data['severity']), 'likelihood_id' => $this->concept('risk-level', $data['likelihood']),
            'use_id' => $this->concept('ai-risk-use', $data['use']), 'affected_group' => $data['affected_group'] ?? null, 'mitigation' => $data['mitigation'] ?? null,
            'residual_id' => $this->concept('risk-level', $data['residual']), 'status_id' => $this->concept('ai-risk-status', $data['status'])];
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::current()->where('iri', Vocab::BASE."$vocabulary/$notation")->value('id') ?? abort(422, __('Choose a kind from the list.'));
    }

    private function admin(Request $request)
    {
        abort_unless($request->user()->hasPermission(Vocab::permission('manage-institution')), 403);

        return $request->user()->institution;
    }

    private function own(Request $request, AiSystem $system): void
    {
        abort_unless($system->institution_id === $this->admin($request)->id, 404);
    }
}
