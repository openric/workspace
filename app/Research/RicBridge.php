<?php

// OpenRiC Workspace - a research project described in the catalogue, as RiC (roadmap phase 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's RiCBridgeService: a research project becomes a rico:Activity of the catalogue, named by its title,
// described by its description, dated from its start to its end, and performed by the researcher's authority record
// (rico:isOrWasPerformedBy); each published output becomes an activity that is part of it (rico:isOrWasSubeventOf).
// Done again, it updates what it made, through a map of what describes what (Heratio's research_ric_map). Changed:
// Heratio does this for every project as soon as it is made, which would put a researcher's working data into the
// catalogue unasked; here it is done when someone who may create authority records chooses to, for a project the
// institution may read, and the activities are drafts until published, as any description is. Heratio relates an
// output to its project with rico:isAssociatedWith, and itself flags it "refine later"; here, part of an activity.

namespace App\Research;

use App\Audit\AuditLog;
use App\Description\ActivityService;
use App\Description\DateService;
use App\Models\Activity;
use App\Models\Agent;
use App\Models\Institution;
use App\Models\Relation;
use App\Models\ResearchProject;
use App\Models\ResearchRicMapping;
use App\Models\User;
use App\Relations\RelationService;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RicBridge
{
    public function __construct(private ActivityService $activities, private RelationService $relations) {}

    public function may(User $user, ResearchProject $project): bool
    {
        return $user->institution_id === $project->institution_id && $user->may('create', 'authority') && $project->visibility->notation === 'public';
    }

    /** Describe the project, and its published outputs, in the catalogue. @return Activity the project's activity */
    public function describe(User $user, ResearchProject $project, ?string $performerIri = null, string $language = 'en'): Activity
    {
        if (! $this->may($user, $project)) {
            throw new InvalidArgumentException(__('Only who may create authority records describes a public project in the catalogue.'));
        }
        $performer = $performerIri ? Agent::where(['institution_id' => $project->institution_id, 'iri' => trim($performerIri)])->whereNull('deleted_at')->first()
            ?? throw new InvalidArgumentException(__('No authority record has that IRI.')) : null;
        $institution = Institution::findOrFail($project->institution_id);

        return DB::transaction(fn () => AuditLog::actingAs($user, function () use ($user, $project, $performer, $institution, $language) {
            $activity = $this->activity($user, $institution, $project, $project->title, $project->description, $project->start_date?->toDateString(),
                ($project->actual_end_date ?? $project->expected_end_date)?->toDateString(), $language);
            if ($performer) {
                $this->relate($user, $activity, 'performance', $performer);
            }
            foreach ($project->outputs()->whereHas('status', fn ($s) => $s->where('iri', Vocab::BASE.'output-status/published'))->with('outputType')->get() as $output) {
                $part = $this->activity($user, $institution, $output, $output->title, trim(implode('. ', array_filter([$output->outputType->label([$language, 'en']), $output->authors, $output->venue,
                    Projects::outputUrl($output)]))) ?: null, $output->output_date?->toDateString(), null, $language);
                $this->relate($user, $activity, 'subevent', $part);
            }

            return $activity;
        }));
    }

    /** The activity that describes the project, when there is one. */
    public function of(Model $thing): ?Activity
    {
        return ResearchRicMapping::where(['entity_table' => $thing->getTable(), 'entity_id' => $thing->getKey()])->first()?->activity;
    }

    private function activity(User $user, Institution $institution, Model $thing, string $name, ?string $description, ?string $from, ?string $to, string $language): Activity
    {
        $activity = $this->of($thing);
        if ($activity === null) {
            $activity = $this->activities->create($institution, $name, $language, null, $user);
            ResearchRicMapping::create(['institution_id' => $institution->id, 'entity_table' => $thing->getTable(), 'entity_id' => $thing->getKey(), 'activity_id' => $activity->id]);
        } else {
            $this->activities->describe($activity, ['activity-name' => $name], $language, $user);
        }
        if ($description) {
            $this->activities->describe($activity, ['activity-description' => $description], $language, $user);
        }
        if ($from && ! $activity->dates()->exists()) {
            app(DateService::class)->add($activity, ['property' => 'associated', 'normalized' => $to ? "$from/$to" : $from], $user);
        }

        return $activity;
    }

    private function relate(User $user, Model $source, string $type, Model $target): void
    {
        $exists = Relation::whereHas('relationType', fn ($q) => $q->where('iri', Vocab::BASE.'relation-type/'.$type))
            ->where(['source_table' => $source->getTable(), 'source_id' => $source->getKey(), 'target_table' => $target->getTable(), 'target_id' => $target->getKey()])->exists();
        if (! $exists) {
            $this->relations->create($source, $type, $target, [], $user);
        }
    }
}
