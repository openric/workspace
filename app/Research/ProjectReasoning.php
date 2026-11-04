<?php

// OpenRiC Workspace - a research project's decision log, its researchers' research memory, and the results of analysis
// done outside the Workspace (roadmap phase 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's DecisionLogService (the decisions that shaped a project: scope changes, exclusions, hypothesis
// revisions, method pivots, questions put differently, supervisors' instructions, with the reason, who decided and
// when), ResearchMemoryService (what a researcher carries from one project to the next: unresolved questions, future
// articles, unused sources, abandoned hypotheses, reusable data, collaborators, conferences, grants; suggested from the
// decision log, and gathered across projects when carried forward) and AnalysisBridgeService (a chart, table, theme or
// statistic made elsewhere, with what data and method made it, the code, when, the researcher's decision, its file,
// and the claims it supports, weakens or puts in context; and the project's theme tags and memos). Heratio's
// suggestCaption is AI, in phase 17.

namespace App\Research;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\ResearchAnalysisCode;
use App\Models\ResearchAnalysisResult;
use App\Models\ResearchAnalysisResultClaim;
use App\Models\ResearchClaim;
use App\Models\ResearchDecision;
use App\Models\ResearchMemoryItem;
use App\Models\ResearchProject;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ProjectReasoning
{
    /** Heratio's suggestions: a decision of these kinds becomes a memory of this kind. */
    private const SUGGEST = ['question-reformulation' => 'unresolved-question', 'hypothesis-revision' => 'abandoned-hypothesis', 'exclusion' => 'unused-source'];

    public function __construct(private Projects $projects, private ResearchFiles $files) {}

    // The decision log.

    public function saveDecision(User $user, ResearchProject $project, array $data, ?ResearchDecision $decision = null): ResearchDecision
    {
        $this->projects->must($user, $project, 'contributor');
        if (trim((string) ($data['summary'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Say what was decided.'));
        }
        $fields = ['decision_type_id' => $this->concept('decision-type', (string) ($data['decision_type'] ?? 'other')), 'summary' => mb_substr(trim($data['summary']), 0, 500),
            'reason' => trim((string) ($data['reason'] ?? '')) ?: null, 'related_ref' => trim((string) ($data['related_ref'] ?? '')) ?: null,
            'decided_by' => trim((string) ($data['decided_by'] ?? '')) ?: $user->name, 'decided_at' => ($data['decided_at'] ?? '') ?: now()];

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => $decision ? tap($decision)->update($fields) : $project->decisions()->create($fields + ['created_by_id' => $user->id])));
    }

    public function deleteDecision(User $user, ResearchDecision $decision): void
    {
        $this->projects->must($user, $decision->project, $decision->created_by_id === $user->id ? 'contributor' : 'editor');
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $decision->delete()));
    }

    // Research memory.

    public function saveMemory(User $user, array $data, ?ResearchMemoryItem $item = null): ResearchMemoryItem
    {
        if ($item && $item->user_id !== $user->id) {
            throw new InvalidArgumentException(__('That is not yours.'));
        }
        if (trim((string) ($data['title'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give it a title.'));
        }
        $project = ($data['project_id'] ?? null) ? ResearchProject::find($data['project_id']) : null;
        if ($project) {
            $this->projects->must($user, $project, 'viewer');
        }
        $fields = ['project_id' => $project?->id, 'kind_id' => $this->concept('memory-kind', (string) ($data['kind'] ?? 'other')), 'title' => mb_substr(trim($data['title']), 0, 500),
            'body' => trim((string) ($data['body'] ?? '')) ?: null, 'source_ref' => trim((string) ($data['source_ref'] ?? '')) ?: $item?->source_ref,
            'status_id' => $this->concept('memory-status', (string) ($data['status'] ?? $item?->status->notation ?? 'open'))];

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => $item ? tap($item)->update($fields)
            : ResearchMemoryItem::create($fields + ['institution_id' => $user->institution_id, 'user_id' => $user->id])));
    }

    public function deleteMemory(User $user, ResearchMemoryItem $item): void
    {
        if ($item->user_id !== $user->id) {
            throw new InvalidArgumentException(__('That is not yours.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $item->delete()));
    }

    /** Heratio's suggestionsForProject: decisions that leave something for later, not yet taken into memory. @return list<array> */
    public function suggestions(User $user, ResearchProject $project): array
    {
        $taken = ResearchMemoryItem::where(['user_id' => $user->id, 'project_id' => $project->id])->whereNotNull('source_ref')->pluck('source_ref')
            ->map(fn ($r) => preg_match('/^(decision:[0-9a-f-]{36})/', $r, $m) ? $m[1] : null)->filter()->all();

        return $project->decisions()->with('decisionType')->whereHas('decisionType', fn ($t) => $t->whereIn('iri', array_map(fn ($k) => Vocab::BASE.'decision-type/'.$k, array_keys(self::SUGGEST))))
            ->limit(50)->get()->reject(fn ($d) => in_array('decision:'.$d->id, $taken, true))
            ->map(fn ($d) => ['kind' => self::SUGGEST[$d->decisionType->notation], 'title' => $d->summary, 'body' => $d->reason,
                'source_ref' => 'decision:'.$d->id.($d->related_ref ? ' ('.mb_substr($d->related_ref, 0, 200).')' : ''), 'signature' => 'decision:'.$d->id])->values()->all();
    }

    public function acceptSuggestion(User $user, ResearchProject $project, string $signature): ResearchMemoryItem
    {
        $suggestion = collect($this->suggestions($user, $project))->firstWhere('signature', $signature) ?? throw new InvalidArgumentException(__('There is no such suggestion.'));

        return $this->saveMemory($user, $suggestion + ['project_id' => $project->id]);
    }

    /** Heratio's carryForwardForResearcher: what one carries forward, from every project. */
    public function carriedForward(User $user): Collection
    {
        return ResearchMemoryItem::where('user_id', $user->id)->whereHas('status', fn ($s) => $s->where('iri', Vocab::BASE.'memory-status/carried-forward'))
            ->with('kind', 'project')->orderByDesc('updated_at')->get();
    }

    // The analysis bridge.

    public function saveResult(User $user, ResearchProject $project, array $data, ?UploadedFile $artifact = null, ?ResearchAnalysisResult $result = null): ResearchAnalysisResult
    {
        $this->projects->must($user, $project, 'contributor');
        if (trim((string) ($data['title'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give the result a title.'));
        }
        $text = fn (string $k) => trim((string) ($data[$k] ?? '')) ?: null;
        $fields = ['result_type_id' => $this->concept('analysis-result-type', (string) ($data['result_type'] ?? 'other')), 'title' => mb_substr(trim($data['title']), 0, 500),
            'source_data_ref' => $text('source_data_ref'), 'source_data_version' => $text('source_data_version'), 'method' => $text('method'), 'code_ref' => $text('code_ref'),
            'generated_at' => ($data['generated_at'] ?? '') ?: null, 'researcher_decision' => $text('researcher_decision')];

        return DB::transaction(fn () => AuditLog::actingAs($user, function () use ($user, $project, $artifact, $result, $fields) {
            if ($artifact) {
                $fields['artifact_file_id'] = $this->files->keep($artifact, $project->institution_id, $user)->id;
            }

            return $result ? tap($result)->update($fields) : $project->analysisResults()->create($fields + ['created_by_id' => $user->id]);
        }));
    }

    public function deleteResult(User $user, ResearchAnalysisResult $result): void
    {
        $this->projects->must($user, $result->project, $result->created_by_id === $user->id ? 'contributor' : 'editor');
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $result->delete()));
    }

    public function linkClaim(User $user, ResearchAnalysisResult $result, string $claimId, string $relationship, ?string $note = null): ResearchAnalysisResultClaim
    {
        $this->projects->must($user, $result->project, 'contributor');
        $claim = ResearchClaim::where(['id' => $claimId, 'project_id' => $result->project_id])->first() ?? throw new InvalidArgumentException(__('Choose a claim of this project.'));

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => ResearchAnalysisResultClaim::updateOrCreate(['result_id' => $result->id, 'claim_id' => $claim->id],
            ['relationship_id' => $this->concept('analysis-relationship', $relationship), 'note' => trim((string) $note) ?: null, 'created_at' => now()])));
    }

    public function unlinkClaim(User $user, ResearchAnalysisResultClaim $link): void
    {
        $this->projects->must($user, $link->result->project, 'contributor');
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $link->delete()));
    }

    public function addCode(User $user, ResearchProject $project, string $kind, string $label, ?string $body = null): ResearchAnalysisCode
    {
        $this->projects->must($user, $project, 'contributor');
        if (trim($label) === '') {
            throw new InvalidArgumentException(__('Give it a label.'));
        }

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => $project->analysisCodes()->create(['kind_id' => $this->concept('analysis-code-kind', $kind),
            'label' => mb_substr(trim($label), 0, 255), 'body' => trim((string) $body) ?: null, 'created_by_id' => $user->id, 'created_at' => now()])));
    }

    public function deleteCode(User $user, ResearchAnalysisCode $code): void
    {
        $this->projects->must($user, $code->project, $code->created_by_id === $user->id ? 'contributor' : 'editor');
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $code->delete()));
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::notated(Vocab::BASE.$vocabulary.'/', $notation)?->id ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
