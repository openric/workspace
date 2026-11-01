<?php

// OpenRiC Workspace - the time machine of a research project (roadmap phase 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's TimeMachineService, "the honesty engine": how a project's research developed, read only from what its
// other parts already date - versions of the research brief, claims made and revised, decisions, hypotheses, steps of
// the argument, method protocols, milestones reached, outputs, versions of its writing, snapshots - as one timeline by
// month; and the project as it stood on a day: the brief then current, and the claims, decisions, argument steps and
// protocols that existed by then. It writes nothing. Heratio's research inbox comes with notifications, in 19j.

namespace App\Research;

use App\Models\ResearchProject;
use App\Models\ResearchQuestionBriefVersion;
use App\Models\ResearchWritingVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class TimeMachine
{
    /**
     * Every dated event of the project, newest first.
     *
     * @return Collection<int, array{when: CarbonImmutable, kind: string, label: string, detail: ?string}>
     */
    public function timeline(ResearchProject $project): Collection
    {
        $event = fn ($when, string $kind, string $label, ?string $detail = null) => ['when' => CarbonImmutable::parse($when), 'kind' => $kind, 'label' => $label, 'detail' => $detail];
        $brief = $project->questionBrief;

        return collect()
            ->concat($brief ? $brief->versions()->get()->map(fn ($v) => $event($v->created_at, 'brief', __('Research brief, version :n', ['n' => $v->version_no]), $v->change_reason ?? $v->primary_question)) : [])
            ->concat($project->claims()->get()->flatMap(fn ($c) => array_filter([$event($c->created_at, 'claim', __('Claim made'), mb_substr($c->object_value, 0, 200)),
                $c->version > 1 ? $event($c->updated_at, 'claim', __('Claim revised (version :n)', ['n' => $c->version]), mb_substr($c->object_value, 0, 200)) : null])))
            ->concat($project->decisions()->get()->map(fn ($d) => $event($d->decided_at ?? $d->created_at, 'decision', __('Decision: :summary', ['summary' => $d->summary]), $d->reason)))
            ->concat($project->hypotheses()->with('status')->get()->map(fn ($h) => $event($h->created_at, 'hypothesis', __('Hypothesis put forward'), mb_substr($h->statement, 0, 200))))
            ->concat($project->argument ? $project->argument->steps()->with('slot')->get()->map(fn ($s) => $event($s->created_at, 'argument', __('Argument step: :slot', ['slot' => $s->slot->label(['en'])]), $s->note)) : [])
            ->concat($project->methodProtocols()->get()->map(fn ($m) => $event($m->created_at, 'method', __('Method protocol: :title', ['title' => $m->title]))))
            ->concat($project->milestones()->whereNotNull('completed_date')->get()->map(fn ($m) => $event($m->completed_date, 'milestone', __('Milestone reached: :title', ['title' => $m->title]))))
            ->concat($project->outputs()->whereNotNull('output_date')->get()->map(fn ($o) => $event($o->output_date, 'output', __('Output: :title', ['title' => $o->title]), $o->venue)))
            ->concat(ResearchWritingVersion::whereIn('doc_id', $project->writingDocs()->select('id'))->with('doc')->get()->map(fn ($v) => $event($v->created_at, 'writing',
                __(':doc, version :n', ['doc' => $v->doc->title, 'n' => $v->version_no]), $v->note)))
            ->concat($project->snapshots()->get()->map(fn ($s) => $event($s->created_at, 'snapshot', __('Snapshot: :title', ['title' => $s->title]))))
            ->sortByDesc('when')->values();
    }

    /** @return array<string, Collection> the timeline by month, "YYYY-MM" */
    public function byMonth(Collection $events): array
    {
        return $events->groupBy(fn ($e) => $e['when']->format('Y-m'))->all();
    }

    /** The project as it stood at the end of a day. */
    public function asOf(ResearchProject $project, string $day): array
    {
        $cutoff = CarbonImmutable::parse($day, config('workspace.timezone'))->endOfDay();
        $brief = $project->questionBrief;

        return ['as_of' => $cutoff,
            'brief' => $brief ? ResearchQuestionBriefVersion::where('brief_id', $brief->id)->where('created_at', '<=', $cutoff)->orderByDesc('version_no')->first() : null,
            'claims' => $project->claims()->where('created_at', '<=', $cutoff)->get(),
            'decisions' => $project->decisions()->where(fn ($q) => $q->where('decided_at', '<=', $cutoff)->orWhere(fn ($o) => $o->whereNull('decided_at')->where('created_at', '<=', $cutoff)))->get(),
            'argument' => $project->argument ? $project->argument->steps()->where('created_at', '<=', $cutoff)->with('slot', 'claim')->get() : collect(),
            'methods' => $project->methodProtocols()->where('created_at', '<=', $cutoff)->get()];
    }
}
