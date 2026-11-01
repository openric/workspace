<?php

// OpenRiC Workspace - the reasoning of a research project: its claim ledger and assertions, the knowledge graph,
// hypotheses, contradictions, decision log, research memory and analysis results (roadmap phase 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ClaimLedgerController, ContradictionEngineController, DecisionLogController, ResearchMemoryController,
// AnalysisBridgeController and the assertions, assertion batch review, knowledge graph and hypotheses pages of
// ResearchProjectOutputsController.

namespace App\Http\Controllers;

use App\Models\Concept;
use App\Models\ResearchAnalysisCode;
use App\Models\ResearchAnalysisResult;
use App\Models\ResearchAnalysisResultClaim;
use App\Models\ResearchClaim;
use App\Models\ResearchClaimEvidence;
use App\Models\ResearchContradiction;
use App\Models\ResearchDecision;
use App\Models\ResearchHypothesis;
use App\Models\ResearchHypothesisEvidence;
use App\Models\ResearchMemoryItem;
use App\Models\ResearchProject;
use App\Research\Claims;
use App\Research\Contradictions;
use App\Research\ProjectReasoning;
use App\Research\Projects;
use App\Research\ResearchFiles;
use App\Support\Vocab;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class ReasoningController extends Controller
{
    public function __construct(private Projects $projects, private Claims $claims, private ProjectReasoning $reasoning) {}

    // The claim ledger.

    public function claims(Request $request, ResearchProject $project): View
    {
        $level = $this->read($request, $project);
        $filters = $request->only(['status', 'type', 'q']);

        return view('reasoning.claims', ['project' => $project, 'level' => $level, 'claims' => $this->claims->claims($project, $filters), 'filters' => $filters,
            'counts' => $this->claims->statusCounts($project), 'unsupported' => $this->claims->withoutEvidence($project), 'dependent' => $this->claims->overDependent($project),
            'proposed' => $project->claims()->whereHas('status', fn ($s) => $s->where('iri', Claims::STATUSES.'proposed'))->get()] + $this->claimChoices());
    }

    public function claim(Request $request, ResearchProject $project, ResearchClaim $claim): View
    {
        $level = $this->read($request, $project);
        abort_unless($claim->project_id === $project->id, 404);

        return view('reasoning.claim', ['project' => $project, 'level' => $level, 'claim' => $claim->load('claimType', 'status', 'evidenceType', 'confidenceLevel', 'provenanceKind', 'user',
            'evidence.sourceType', 'evidence.relationship', 'evidence.addedBy'), 'service' => $this->claims, 'available' => $this->claims->available($request->user(), $project),
            'sources' => Concept::choices(Claims::SOURCES)->get(), 'relationships' => Concept::choices(Vocab::BASE.'evidence-relationship/')->get()] + $this->claimChoices());
    }

    public function saveClaim(Request $request, ResearchProject $project, ?ResearchClaim $claim = null): RedirectResponse
    {
        $data = $request->validate(['object_value' => ['required', 'string', 'max:20000'], 'claim_type' => ['nullable', 'string', 'max:64'], 'subject_iri' => ['nullable', 'string', 'max:2048'],
            'subject_label' => ['nullable', 'string', 'max:500'], 'predicate' => ['nullable', 'string', 'max:255'], 'object_iri' => ['nullable', 'string', 'max:2048'],
            'object_label' => ['nullable', 'string', 'max:500'], 'status' => ['nullable', 'string', 'max:64'], 'confidence' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'evidence_type' => ['nullable', 'string', 'max:64'], 'confidence_level' => ['nullable', 'string', 'max:64'], 'provenance_kind' => ['nullable', 'string', 'max:64'],
            ...array_fill_keys(Claims::LEDGER, ['nullable', 'string', 'max:20000'])]);
        try {
            $saved = $this->claims->save($request->user(), $project, $data, $claim);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['research' => $e->getMessage()]);
        }

        return redirect()->route('research.reasoning.claim', [$project, $saved])->with('status', __('Saved.'));
    }

    public function claimStep(Request $request, ResearchProject $project, ResearchClaim $claim, string $step): RedirectResponse
    {
        abort_unless($claim->project_id === $project->id, 404);
        $data = $request->validate(['status' => ['nullable', 'string', 'max:64'], 'source_type' => ['nullable', 'string', 'max:64'], 'source_id' => ['nullable', 'uuid'],
            'iri' => ['nullable', 'string', 'max:2048'], 'relationship' => ['nullable', 'string', 'max:64'], 'note' => ['nullable', 'string', 'max:5000']]);

        return $this->attempt(fn () => match ($step) {
            'status' => $this->claims->setStatus($request->user(), $claim, (string) ($data['status'] ?? '')),
            'evidence' => $this->claims->attach($request->user(), $claim, $data),
            'delete' => $this->claims->delete($request->user(), $claim),
        }, __('Saved.'), $step === 'delete' ? ['research.reasoning.claims', $project] : null);
    }

    public function detach(Request $request, ResearchClaimEvidence $evidence): RedirectResponse
    {
        return $this->attempt(fn () => $this->claims->detach($request->user(), $evidence), __('Removed.'));
    }

    /** Heratio's assertion batch review. */
    public function review(Request $request, ResearchProject $project): RedirectResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['uuid'], 'status' => ['required', 'string', 'max:64']]);
        try {
            $n = $this->claims->review($request->user(), $project, $data['ids'], $data['status']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['research' => $e->getMessage()]);
        }

        return back()->with('status', trans_choice('{0} No assertion was changed.|{1} :count assertion was changed.|[2,*] :count assertions were changed.', $n, ['count' => $n]));
    }

    public function graph(Request $request, ResearchProject $project): View|JsonResponse
    {
        $this->read($request, $project);
        $graph = $this->claims->graph($project);

        return $request->wantsJson() || $request->query('format') === 'json' ? response()->json($graph) : view('reasoning.graph', ['project' => $project, 'graph' => $graph]);
    }

    // Hypotheses.

    public function hypotheses(Request $request, ResearchProject $project): View
    {
        $level = $this->read($request, $project);

        return view('reasoning.hypotheses', ['project' => $project, 'level' => $level, 'hypotheses' => $project->hypotheses()->with('status', 'user', 'evidence.sourceType', 'evidence.relationship')->get(),
            'service' => $this->claims, 'available' => $this->claims->available($request->user(), $project), 'statuses' => Concept::choices(Vocab::BASE.'hypothesis-status/')->get(),
            'sources' => Concept::choices(Claims::SOURCES)->get(), 'relationships' => Concept::choices(Vocab::BASE.'hypothesis-evidence-relationship/')->get()]);
    }

    public function saveHypothesis(Request $request, ResearchProject $project, ?ResearchHypothesis $hypothesis = null): RedirectResponse
    {
        abort_if($hypothesis && $hypothesis->project_id !== $project->id, 404);
        $data = $request->validate(['statement' => ['required', 'string', 'max:20000'], 'status' => ['nullable', 'string', 'max:64'], 'tags' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(fn () => $this->claims->saveHypothesis($request->user(), $project, $data, $hypothesis), __('Saved.'));
    }

    public function hypothesisStep(Request $request, ResearchHypothesis $hypothesis, string $step): RedirectResponse
    {
        $data = $request->validate(['source_type' => ['nullable', 'string', 'max:64'], 'source_id' => ['nullable', 'uuid'], 'iri' => ['nullable', 'string', 'max:2048'],
            'relationship' => ['nullable', 'string', 'max:64'], 'confidence' => ['nullable', 'numeric', 'min:0', 'max:1'], 'note' => ['nullable', 'string', 'max:5000']]);

        return $this->attempt(fn () => match ($step) {
            'evidence' => $this->claims->attachToHypothesis($request->user(), $hypothesis, $data),
            'delete' => $this->claims->deleteHypothesis($request->user(), $hypothesis),
        }, __('Saved.'));
    }

    public function detachHypothesis(Request $request, ResearchHypothesisEvidence $evidence): RedirectResponse
    {
        return $this->attempt(fn () => $this->claims->detachFromHypothesis($request->user(), $evidence), __('Removed.'));
    }

    // Contradictions.

    public function contradictions(Request $request, ResearchProject $project): View
    {
        $level = $this->read($request, $project);
        $status = $request->query('status', 'open');

        return view('reasoning.contradictions', ['project' => $project, 'level' => $level, 'status' => $status,
            'findings' => $project->contradictions()->with('kind', 'severity', 'status', 'claimA', 'claimB')->when($status !== 'all', fn ($q) => $q->whereHas('status', fn ($s) => $s->where('iri', Vocab::BASE.'finding-status/'.$status)))->get(),
            'statuses' => Concept::choices(Vocab::BASE.'finding-status/')->get()]);
    }

    public function scan(Request $request, ResearchProject $project): RedirectResponse
    {
        try {
            $result = app(Contradictions::class)->scan($request->user(), $project);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['research' => $e->getMessage()]);
        }

        return back()->with('status', __(':scanned claims scanned; :found findings.', $result));
    }

    public function finding(Request $request, ResearchContradiction $finding, string $status): RedirectResponse
    {
        return $this->attempt(fn () => app(Contradictions::class)->setStatus($request->user(), $finding, $status), __('Saved.'));
    }

    // The decision log.

    public function decisions(Request $request, ResearchProject $project): View
    {
        $level = $this->read($request, $project);
        $type = $request->query('type');

        return view('reasoning.decisions', ['project' => $project, 'level' => $level, 'type' => $type, 'types' => Concept::choices(Vocab::BASE.'decision-type/')->get(),
            'decisions' => $project->decisions()->with('decisionType', 'createdBy')->when($type, fn ($q) => $q->whereHas('decisionType', fn ($t) => $t->where('iri', Vocab::BASE.'decision-type/'.$type)))->get(),
            'counts' => $project->decisions()->with('decisionType')->get()->countBy(fn ($d) => $d->decisionType->notation)->all()]);
    }

    public function saveDecision(Request $request, ResearchProject $project, ?ResearchDecision $decision = null): RedirectResponse
    {
        abort_if($decision && $decision->project_id !== $project->id, 404);
        $data = $request->validate(['decision_type' => ['nullable', 'string', 'max:64'], 'summary' => ['required', 'string', 'max:500'], 'reason' => ['nullable', 'string', 'max:20000'],
            'related_ref' => ['nullable', 'string', 'max:500'], 'decided_by' => ['nullable', 'string', 'max:255'], 'decided_at' => ['nullable', 'date']]);

        return $this->attempt(fn () => $this->reasoning->saveDecision($request->user(), $project, $data, $decision), __('Saved.'));
    }

    public function deleteDecision(Request $request, ResearchDecision $decision): RedirectResponse
    {
        return $this->attempt(fn () => $this->reasoning->deleteDecision($request->user(), $decision), __('Deleted.'));
    }

    // Research memory.

    public function memory(Request $request): View
    {
        $user = $request->user();
        $project = $request->filled('project') ? ResearchProject::find($request->query('project')) : null;
        if ($project) {
            $this->read($request, $project);
        }

        return view('reasoning.memory', ['items' => ResearchMemoryItem::where('user_id', $user->id)->when($project, fn ($q) => $q->where('project_id', $project->id))->with('kind', 'status', 'project')
            ->orderByDesc('updated_at')->get(), 'project' => $project, 'carried' => $this->reasoning->carriedForward($user),
            'suggestions' => $project ? $this->reasoning->suggestions($user, $project) : [], 'projects' => $this->projects->visible($user)->orderBy('title')->get(),
            'kinds' => Concept::choices(Vocab::BASE.'memory-kind/')->get(), 'statuses' => Concept::choices(Vocab::BASE.'memory-status/')->get()]);
    }

    public function saveMemory(Request $request, ?ResearchMemoryItem $item = null): RedirectResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:500'], 'body' => ['nullable', 'string', 'max:20000'], 'kind' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'string', 'max:64'], 'source_ref' => ['nullable', 'string', 'max:500'], 'project_id' => ['nullable', 'uuid']]);

        return $this->attempt(fn () => $this->reasoning->saveMemory($request->user(), $data, $item), __('Saved.'));
    }

    public function memoryStep(Request $request, string $step, ?ResearchMemoryItem $item = null): RedirectResponse
    {
        return $this->attempt(fn () => match ($step) {
            'delete' => $this->reasoning->deleteMemory($request->user(), $item ?? abort(404)),
            'accept' => $this->reasoning->acceptSuggestion($request->user(), ResearchProject::findOrFail($request->input('project_id')), (string) $request->input('signature')),
        }, __('Saved.'));
    }

    // The analysis bridge.

    public function analysis(Request $request, ResearchProject $project): View
    {
        $level = $this->read($request, $project);

        return view('reasoning.analysis', ['project' => $project, 'level' => $level, 'results' => $project->analysisResults()->with('resultType', 'artifact', 'createdBy', 'claimLinks.claim', 'claimLinks.relationship')->get(),
            'codes' => $project->analysisCodes()->with('kind')->get(), 'claims' => $project->claims()->get(), 'types' => Concept::choices(Vocab::BASE.'analysis-result-type/')->get(),
            'relationships' => Concept::choices(Vocab::BASE.'analysis-relationship/')->get(), 'codeKinds' => Concept::choices(Vocab::BASE.'analysis-code-kind/')->get()]);
    }

    public function saveResult(Request $request, ResearchProject $project, ?ResearchAnalysisResult $result = null): RedirectResponse
    {
        abort_if($result && $result->project_id !== $project->id, 404);
        $data = $request->validate(['title' => ['required', 'string', 'max:500'], 'result_type' => ['nullable', 'string', 'max:64'], 'source_data_ref' => ['nullable', 'string', 'max:1000'],
            'source_data_version' => ['nullable', 'string', 'max:120'], 'method' => ['nullable', 'string', 'max:20000'], 'code_ref' => ['nullable', 'string', 'max:1000'],
            'generated_at' => ['nullable', 'date'], 'researcher_decision' => ['nullable', 'string', 'max:20000'], 'artifact' => ['nullable', 'file', 'max:'.ResearchFiles::MAX_KILOBYTES]]);

        return $this->attempt(fn () => $this->reasoning->saveResult($request->user(), $project, $data, $request->file('artifact'), $result), __('Saved.'));
    }

    public function resultStep(Request $request, ResearchAnalysisResult $result, string $step): RedirectResponse|Response
    {
        if ($step === 'artifact') {
            abort_unless($result->artifact && $this->projects->level($request->user(), $result->project) > 0, 404);

            return app(ResearchFiles::class)->respond($result->artifact, true);
        }
        $data = $request->validate(['claim_id' => ['nullable', 'uuid'], 'relationship' => ['nullable', 'string', 'max:64'], 'note' => ['nullable', 'string', 'max:5000']]);

        return $this->attempt(fn () => match ($step) {
            'claim' => $this->reasoning->linkClaim($request->user(), $result, (string) ($data['claim_id'] ?? ''), (string) ($data['relationship'] ?? 'supports'), $data['note'] ?? null),
            'delete' => $this->reasoning->deleteResult($request->user(), $result),
        }, __('Saved.'));
    }

    public function unlink(Request $request, ResearchAnalysisResultClaim $link): RedirectResponse
    {
        return $this->attempt(fn () => $this->reasoning->unlinkClaim($request->user(), $link), __('Removed.'));
    }

    public function code(Request $request, ResearchProject $project, ?ResearchAnalysisCode $code = null): RedirectResponse
    {
        if ($code) {
            return $this->attempt(fn () => $this->reasoning->deleteCode($request->user(), $code), __('Removed.'));
        }
        $data = $request->validate(['kind' => ['required', 'string', 'max:64'], 'label' => ['required', 'string', 'max:255'], 'body' => ['nullable', 'string', 'max:20000']]);

        return $this->attempt(fn () => $this->reasoning->addCode($request->user(), $project, $data['kind'], $data['label'], $data['body'] ?? null), __('Saved.'));
    }

    private function claimChoices(): array
    {
        return ['types' => Concept::choices(Vocab::BASE.'claim-type/')->get(), 'statuses' => Concept::choices(Claims::STATUSES)->get(),
            'evidenceTypes' => Concept::choices(Vocab::BASE.'claim-evidence-type/')->get(), 'levels' => Concept::choices(Vocab::BASE.'confidence-level/')->get(),
            'provenances' => Concept::choices(Vocab::BASE.'claim-provenance/')->get()];
    }

    /** The account's level on the project; 404 when it may not read it. */
    private function read(Request $request, ResearchProject $project): int
    {
        $level = $this->projects->level($request->user(), $project);
        abort_unless($level > 0, 404);

        return $level;
    }

    private function attempt(callable $do, string $done, ?array $route = null): RedirectResponse
    {
        try {
            $do();
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['research' => $e->getMessage()]);
        } catch (\UnhandledMatchError) {
            abort(404);
        }

        return ($route ? redirect()->route(...$route) : back())->with('status', $done);
    }
}
