<?php

// OpenRiC Workspace - the rights report (WS-007, roadmap phase 2), after Heratio's (ahg-extended-rights: getReportData,
// exportReportCsv): rights in force by statement, licence and basis; Labels applied; rights holders; and the units of
// description and instantiations with no right in force. Each list exports as CSV.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Rights;

use App\Models\Agent;
use App\Models\CommunityLabel;
use App\Models\Concept;
use App\Models\Instantiation;
use App\Models\RecordResource;
use App\Models\Right;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use Illuminate\Support\Collection;

final class RightsReport
{
    /** The lists the report has, by their key in the URL. */
    public const LISTS = ['statements', 'licences', 'bases', 'labels', 'holders', 'without'];

    /** @return array{statements: Collection, licences: Collection, bases: Collection, labels: Collection, holders: Collection, without: Collection} */
    public function build(string $institutionId): array
    {
        $rights = Right::where('institution_id', $institutionId)->whereNull('deleted_at')->with(['statement.labels', 'licence.labels', 'basis.labels', 'holder.names'])->get()
            ->filter(fn (Right $r) => $r->inForce());
        $count = fn (string $relation) => $rights->filter(fn ($r) => $r->$relation)->groupBy(fn ($r) => $r->$relation->id)
            ->map(fn ($group) => ['concept' => $group->first()->$relation, 'count' => $group->count()])->sortByDesc('count')->values();

        $labels = CommunityLabel::where('institution_id', $institutionId)->with('label.labels')->get()->groupBy('label_id')
            ->map(fn ($group) => ['concept' => $group->first()->label, 'count' => $group->count()])->sortByDesc('count')->values();

        $holders = $rights->filter(fn ($r) => $r->holder)->groupBy('holder_id')
            ->map(fn ($group) => ['agent' => $group->first()->holder, 'count' => $group->count()])->sortByDesc('count')->values();

        // ponytail: every unit and instantiation is read to find those without a right; page it when a catalogue holds hundreds of thousands.
        $held = $rights->map(fn ($r) => $r->subject_table.':'.$r->subject_id)->flip();
        $without = collect([RecordResource::class, Instantiation::class])->flatMap(fn ($class) => $class::where('institution_id', $institutionId)->whereNull('deleted_at')->get())
            ->reject(fn ($entity) => $held->has($entity->getTable().':'.$entity->getKey()))->values();

        return ['statements' => $count('statement'), 'licences' => $count('licence'), 'bases' => $count('basis'), 'labels' => $labels, 'holders' => $holders, 'without' => $without];
    }

    /** One list as CSV rows, a header first. @return list<list<string>> */
    public function csv(string $institutionId, string $list): array
    {
        $data = $this->build($institutionId)[$list];
        $lang = Vocab::languages();

        return match ($list) {
            'statements', 'licences', 'bases', 'labels' => [[__('Name'), 'IRI', __('Count')],
                ...$data->map(fn ($row) => [$row['concept']->label($lang), $row['concept']->iri, (string) $row['count']])->all()],
            'holders' => [[__('Rights holder'), 'IRI', __('Count')],
                ...$data->map(fn ($row) => [(string) EntityKinds::label($row['agent']), $row['agent']->iri, (string) $row['count']])->all()],
            'without' => [[__('Kind'), __('Name'), 'IRI'],
                ...$data->map(fn ($entity) => [__(EntityKinds::noun($entity)), (string) EntityKinds::label($entity), $entity->iri])->all()],
        };
    }

    /** The rights an authority record holds, in force or not, with what they are on. */
    public function heldBy(Agent $agent): Collection
    {
        return Right::where('holder_id', $agent->id)->whereNull('deleted_at')->with(['statement.labels', 'licence.labels', 'basis.labels'])->orderBy('created_at')->get()
            ->map(fn (Right $right) => ['right' => $right, 'subject' => (EntityKinds::get((string) EntityKinds::byTable($right->subject_table))['class'] ?? RecordResource::class)::find($right->subject_id)])
            ->filter(fn ($row) => $row['subject'] !== null)->values();
    }

    /** The concepts of a vocabulary of rights, for the batch form. */
    public function choices(string $vocabulary): Collection
    {
        return Concept::choices($vocabulary)->get();
    }
}
