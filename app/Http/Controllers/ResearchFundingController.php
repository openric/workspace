<?php

// OpenRiC Workspace - a research project's funding, grant applications, data management plans, ethics approvals,
// impact, compliance, replication packs and DOIs; a researcher's funding calls and ORCID link; the institution's
// ORCID and DataCite settings; a DOI's landing page (roadmap phase 19h, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ResearchProjectFundingController, GrantEngineController, DmpController, ResearchEthicsController,
// ImpactTrackingController, ReplicationPackController, OrcidController and the roCrate, mintDoi and
// complianceDashboard actions of ResearchProjectOutputsController.

namespace App\Http\Controllers;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\ResearchDmp;
use App\Models\ResearchDoi;
use App\Models\ResearchEthicsApproval;
use App\Models\ResearchFunding;
use App\Models\ResearchGrantCall;
use App\Models\ResearchGrantDraft;
use App\Models\ResearchImpactSignal;
use App\Models\ResearchProject;
use App\Models\ResearchProjectOutput;
use App\Models\ResearchServiceSettings;
use App\Research\DataPlans;
use App\Research\Dois;
use App\Research\Funding;
use App\Research\Impact;
use App\Research\Journals;
use App\Research\Orcid;
use App\Research\Projects;
use App\Research\Quotas;
use App\Research\ReplicationPack;
use App\Support\Vocab;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;

class ResearchFundingController extends Controller
{
    public function __construct(private Projects $projects, private Funding $funding, private DataPlans $plans, private Impact $impact, private ReplicationPack $pack,
        private Dois $dois, private Orcid $orcid) {}

    // Funding and grant applications.

    public function funding(Request $request, ResearchProject $project): View
    {
        $level = $this->level($request, $project);
        $project->load('funding.funderType', 'funding.status', 'funding.currency', 'funding.dmp', 'grantDrafts.template', 'grantDrafts.status', 'grantDrafts.sections');

        return view('projects.funding', ['project' => $project, 'level' => $level, 'user' => $request->user(), 'summary' => $this->funding->summary($project),
            'funderTypes' => $this->choices('funder-type'), 'statuses' => $this->choices('funding-status'), 'currencies' => $this->choices('currency'),
            'templates' => $this->choices('grant-template'), 'plans' => $project->dmps()->get()]);
    }

    public function saveFunding(Request $request, ResearchProject $project, ?ResearchFunding $funding = null): RedirectResponse
    {
        abort_if($funding && $funding->project_id !== $project->id, 404);
        $data = $request->validate(['title' => ['required', 'string', 'max:512'], 'funder_name' => ['required', 'string', 'max:512'], 'funder_type' => ['nullable', 'string', 'max:64'],
            'award_reference' => ['nullable', 'string', 'max:128'], 'amount' => ['nullable', 'string', 'max:32'], 'currency' => ['nullable', 'string', 'max:8'], 'status' => ['nullable', 'string', 'max:64'],
            'start_date' => ['nullable', 'date_format:Y-m-d'], 'end_date' => ['nullable', 'date_format:Y-m-d'], 'notes' => ['nullable', 'string', 'max:65000'], 'dmp_id' => ['nullable', 'uuid']]);

        return $this->attempt(fn () => $this->funding->save($request->user(), $project, $data, $funding), __('Saved.'));
    }

    public function startGrant(Request $request, ResearchProject $project): RedirectResponse
    {
        $data = $request->validate(['template' => ['required', 'string', 'max:64'], 'title' => ['nullable', 'string', 'max:255']]);
        $draft = null;
        $back = $this->attempt(function () use ($request, $project, $data, &$draft) {
            $draft = $this->funding->startDraft($request->user(), $project, $data['template'], $data['title'] ?? null);
        }, __('Started.'));

        return $draft ? redirect()->route('research.grants.show', $draft)->with('status', __('Started.')) : $back;
    }

    public function grant(Request $request, ResearchGrantDraft $draft): View
    {
        $level = $this->level($request, $draft->project);
        $draft->load('sections', 'template', 'status', 'project');

        return view('projects.grant', ['draft' => $draft, 'level' => $level, 'material' => $this->funding->material($draft->project),
            'completeness' => Funding::completeness($draft->sections), 'statuses' => $this->choices('grant-draft-status')]);
    }

    public function saveGrant(Request $request, ResearchGrantDraft $draft): RedirectResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'status' => ['nullable', 'string', 'max:64'], 'sections' => ['array'], 'sections.*' => ['nullable', 'string', 'max:65000']]);

        return $this->attempt(fn () => $this->funding->saveDraft($request->user(), $draft, $data), __('Saved.'));
    }

    // Data management plans.

    public function plans(Request $request, ResearchProject $project): View
    {
        $level = $this->level($request, $project);
        $project->load('dmps.status', 'dmps.template', 'dmps.sections');

        return view('projects.plans', ['project' => $project, 'level' => $level, 'templates' => $this->choices('dmp-template')]);
    }

    public function startPlan(Request $request, ResearchProject $project): RedirectResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'template' => ['required', 'string', 'max:64'], 'funder' => ['nullable', 'string', 'max:255'],
            'language' => ['nullable', 'string', 'max:12'], 'contact_name' => ['nullable', 'string', 'max:255'], 'contact_email' => ['nullable', 'string', 'max:255']]);
        $plan = null;
        $back = $this->attempt(function () use ($request, $project, $data, &$plan) {
            $plan = $this->plans->createPlan($request->user(), $project, $data);
        }, __('Started.'));

        return $plan ? redirect()->route('research.projects.plans.show', [$project, $plan])->with('status', __('Started.')) : $back;
    }

    public function plan(Request $request, ResearchProject $project, ResearchDmp $plan): View|JsonResponse
    {
        abort_unless($plan->project_id === $project->id, 404);
        $level = $this->level($request, $project);
        if ($request->query('format') === 'madmp') {
            return response()->json($this->plans->madmp($plan), 200, ['Content-Disposition' => 'attachment; filename="dmp-'.str($plan->title)->slug()->limit(60, '').'.json"'],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $plan->load('sections', 'status', 'template');

        return view('projects.plan', ['project' => $project, 'plan' => $plan, 'level' => $level, 'completeness' => Funding::completeness($plan->sections), 'statuses' => $this->choices('dmp-status')]);
    }

    public function savePlan(Request $request, ResearchProject $project, ResearchDmp $plan): RedirectResponse
    {
        abort_unless($plan->project_id === $project->id, 404);
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'status' => ['nullable', 'string', 'max:64'], 'funder' => ['nullable', 'string', 'max:255'],
            'language' => ['nullable', 'string', 'max:12'], 'contact_name' => ['nullable', 'string', 'max:255'], 'contact_email' => ['nullable', 'string', 'max:255'],
            'sections' => ['array'], 'sections.*' => ['nullable', 'string', 'max:65000']]);

        return $this->attempt(fn () => $this->plans->savePlan($request->user(), $plan, $data), __('Saved.'));
    }

    // Ethics approvals.

    public function ethics(Request $request, ResearchProject $project): View
    {
        $level = $this->level($request, $project);
        $project->load('ethicsApprovals.approvalType', 'ethicsApprovals.status', 'ethicsApprovals.consentBasis', 'ethicsApprovals.dataSensitivity', 'ethicsApprovals.dmp');

        return view('projects.ethics', ['project' => $project, 'level' => $level, 'user' => $request->user(), 'types' => $this->choices('ethics-approval-type'),
            'statuses' => $this->choices('ethics-status'), 'bases' => $this->choices('consent-basis'), 'sensitivities' => $this->choices('data-sensitivity'), 'plans' => $project->dmps()->get()]);
    }

    public function saveEthics(Request $request, ResearchProject $project, ?ResearchEthicsApproval $approval = null): RedirectResponse
    {
        abort_if($approval && $approval->project_id !== $project->id, 404);
        $data = $request->validate(['title' => ['required', 'string', 'max:512'], 'approval_type' => ['nullable', 'string', 'max:64'], 'reference_number' => ['nullable', 'string', 'max:128'],
            'committee_name' => ['nullable', 'string', 'max:512'], 'status' => ['nullable', 'string', 'max:64'], 'decision_date' => ['nullable', 'date_format:Y-m-d'],
            'expiry_date' => ['nullable', 'date_format:Y-m-d'], 'consent_basis' => ['nullable', 'string', 'max:64'], 'data_sensitivity' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:65000'], 'dmp_id' => ['nullable', 'uuid']]);

        return $this->attempt(fn () => $this->plans->saveApproval($request->user(), $project, $data, $approval), __('Saved.'));
    }

    // Impact.

    public function impact(Request $request, ResearchProject $project): View
    {
        $level = $this->level($request, $project);

        return view('projects.impact', ['project' => $project, 'level' => $level, 'signals' => $this->impact->signals($project), 'counts' => $this->impact->counts($project),
            'dois' => $this->impact->dois($project), 'types' => $this->choices('impact-signal-type'), 'outputs' => $project->outputs()->get()]);
    }

    public function impactStep(Request $request, ResearchProject $project, string $step): RedirectResponse
    {
        $user = $request->user();

        return match ($step) {
            'scan' => $this->attempt(function () use ($user, $project) {
                $this->projects->must($user, $project, 'contributor');
                session()->flash('raised', $this->impact->scan($project, $user));
            }, __('Looked for signs of impact.')),
            'record' => $this->attempt(fn () => $this->impact->record($user, $project, $request->validate(['title' => ['required', 'string', 'max:500'], 'signal_type' => ['nullable', 'string', 'max:64'],
                'detail' => ['nullable', 'string', 'max:20000'], 'url' => ['nullable', 'string', 'max:1000'], 'output_id' => ['nullable', 'uuid'], 'detected_at' => ['nullable', 'date_format:Y-m-d']])), __('Recorded.')),
        };
    }

    // Compliance, the replication pack and DOIs.

    public function compliance(Request $request, ResearchProject $project): View
    {
        $level = $this->level($request, $project);

        return view('projects.compliance', ['project' => $project->load('visibility'), 'level' => $level, 'compliance' => $this->plans->compliance($project),
            'pack' => $this->pack->assemble($request->user(), $project), 'builds' => $project->replicationBuilds()->with('builtBy')->limit(10)->get(),
            'dois' => $project->dois()->with('state', 'output', 'mintedBy')->get(), 'outputs' => $project->outputs()->with('outputType')->get(),
            'datacite' => $this->dois->settings($project->institution_id)]);
    }

    public function replication(Request $request, ResearchProject $project): Response|RedirectResponse
    {
        $this->level($request, $project);
        try {
            app(Quotas::class)->download($request->user(), $project, $project, __('Replication pack'));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['research' => $e->getMessage()]);
        }
        [$body, $name] = $this->pack->build($request->user(), $project);

        return response($body, 200, ['Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename="'.$name.'"', 'X-Content-SHA256' => hash('sha256', $body)]);
    }

    public function registerDoi(Request $request, ResearchProject $project): RedirectResponse
    {
        $data = $request->validate(['output_id' => ['nullable', 'uuid'], 'publish' => ['nullable', 'boolean']]);
        $output = ($data['output_id'] ?? null) ? ResearchProjectOutput::where('project_id', $project->id)->findOrFail($data['output_id']) : null;

        return $this->attempt(fn () => $this->dois->register($request->user(), $project, $output, (bool) ($data['publish'] ?? false)), __('The DOI is registered.'));
    }

    public function publishDoi(Request $request, ResearchDoi $doi): RedirectResponse
    {
        return $this->attempt(fn () => $this->dois->publish($request->user(), $doi), __('The DOI is findable.'));
    }

    /** A DOI's landing page: what was registered for it. Public, as a DOI must resolve. */
    public function landing(ResearchDoi $doi): View
    {
        return view('research.doi', ['doi' => $doi->load('state'), 'institution' => Institution::find($doi->project->institution_id)]);
    }

    /** Remove a funding record, grant application, plan, ethics approval or impact signal. */
    public function remove(Request $request, string $kind, string $id): RedirectResponse
    {
        $user = $request->user();

        return $this->attempt(fn () => match ($kind) {
            'funding' => $this->funding->delete($user, ResearchFunding::findOrFail($id)),
            'grants' => $this->funding->deleteDraft($user, ResearchGrantDraft::findOrFail($id)),
            'plans' => $this->plans->deletePlan($user, ResearchDmp::findOrFail($id)),
            'ethics' => $this->plans->deleteApproval($user, ResearchEthicsApproval::findOrFail($id)),
            'impact' => $this->impact->delete($user, ResearchImpactSignal::findOrFail($id)),
        }, __('Removed.'));
    }

    // The researcher's funding calls.

    public function calls(Request $request): View
    {
        return view('research.calls', ['calls' => $this->funding->calls($request->user()), 'statuses' => $this->choices('grant-call-status'),
            'projects' => $this->projects->contributing($request->user())]);
    }

    public function saveCall(Request $request, ?ResearchGrantCall $call = null): RedirectResponse
    {
        $data = $request->validate(['funder' => ['required', 'string', 'max:255'], 'title' => ['required', 'string', 'max:255'], 'url' => ['nullable', 'string', 'max:500'],
            'deadline' => ['nullable', 'date_format:Y-m-d'], 'status' => ['nullable', 'string', 'max:64'], 'notes' => ['nullable', 'string', 'max:20000'], 'project_id' => ['nullable', 'uuid']]);

        return $this->attempt(fn () => $this->funding->saveCall($request->user(), $data, $call), __('Saved.'));
    }

    public function removeCall(Request $request, ResearchGrantCall $call): RedirectResponse
    {
        return $this->attempt(fn () => $this->funding->deleteCall($request->user(), $call), __('Removed.'));
    }

    // ORCID.

    public function orcidStep(Request $request, string $step): RedirectResponse
    {
        $user = $request->user();
        if ($step === 'link') {
            try {
                return redirect()->away($this->orcid->authorizeUrl($user));
            } catch (RuntimeException $e) {
                return back()->withErrors(['research' => $e->getMessage()]);
            }
        }

        return match ($step) {
            'unlink' => $this->attempt(fn () => $this->orcid->unlink($user), __('Your ORCID iD is no longer linked; it stays on your profile.')),
            'pull' => $this->attempt(fn () => $this->orcid->pullWorks($user), __('Your works are read from ORCID.')),
        };
    }

    public function orcidCallback(Request $request): RedirectResponse
    {
        if ($request->query('error')) {
            return redirect()->route('research.profile')->withErrors(['research' => __('The ORCID sign-in was not completed.')]);
        }

        return $this->attempt(fn () => $this->orcid->callback($request->user(), $request->query('code'), $request->query('state')), __('Your ORCID iD is linked.'), 'research.profile');
    }

    public function orcidPush(Request $request, ResearchProjectOutput $output): RedirectResponse
    {
        return $this->attempt(fn () => $this->orcid->pushOutput($request->user(), $output, $this->projects), __('Added to your ORCID record.'));
    }

    // The institution's ORCID client and DataCite account.

    public function settings(Request $request): View
    {
        abort_unless(app(Journals::class)->mayRun($request->user()), 403);

        return view('research.service-settings', ['settings' => ResearchServiceSettings::firstOrNew(['institution_id' => $request->user()->institution_id])]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless(app(Journals::class)->mayRun($user), 403);
        $data = $request->validate(['orcid_client_id' => ['nullable', 'string', 'regex:/^APP-[A-Z0-9]{16}$/'], 'orcid_client_secret' => ['nullable', 'string', 'max:255'], 'orcid_sandbox' => ['nullable', 'boolean'],
            'datacite_repository_id' => ['nullable', 'string', 'max:100'], 'datacite_password' => ['nullable', 'string', 'max:255'], 'datacite_prefix' => ['nullable', 'string', 'regex:/^10\.\d{4,9}$/'],
            'datacite_test' => ['nullable', 'boolean']]);
        $settings = ResearchServiceSettings::firstOrNew(['institution_id' => $user->institution_id]);
        // A secret left empty keeps the one stored.
        foreach (['orcid_client_secret', 'datacite_password'] as $secret) {
            if (($data[$secret] ?? '') === '') {
                unset($data[$secret]);
            }
        }
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $settings->fill($data + ['orcid_sandbox' => false, 'datacite_test' => false])->save()));

        return back()->with('status', __('Saved.'));
    }

    private function level(Request $request, ResearchProject $project): int
    {
        $level = $this->projects->level($request->user(), $project);
        abort_unless($level > 0, 404);

        return $level;
    }

    private function choices(string $vocabulary)
    {
        return Concept::choices(Vocab::BASE.$vocabulary.'/')->get();
    }

    private function attempt(callable $do, string $done, ?string $route = null): RedirectResponse
    {
        try {
            $do();
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->withInput()->withErrors(['research' => $e->getMessage()]);
        } catch (\UnhandledMatchError) {
            abort(404);
        }

        return ($route ? redirect()->route($route) : back())->with('status', $done);
    }
}
