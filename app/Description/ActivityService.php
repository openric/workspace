<?php

// OpenRiC Workspace - create and describe activities (rico:Activity; ADR 0006, WS-003).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Who performed an activity, who took part in it and in what role, what it
// resulted in and what documents it are relations (RelationService); this
// keeps the activity itself: its names, values, type and dates.

namespace App\Description;

use App\Audit\AuditLog;
use App\Models\Activity;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ActivityService
{
    public function __construct(private DescriptionService $descriptions) {}

    /** @param  ?string  $typeId  a concept id from types() */
    public function create(Institution $institution, string $name, string $language, ?string $typeId = null, ?User $actor = null): Activity
    {
        $type = $this->type($institution->id, $typeId);

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($institution, $name, $language, $type) {
            $id = (string) Str::uuid7();
            $activity = new Activity(['institution_id' => $institution->id, 'iri' => $institution->base_iri.'/'.Activity::IRI_SEGMENT.'/'.$id, 'activity_type_id' => $type?->id]);
            $activity->id = $id;
            $activity->save();
            $this->describe($activity, ['activity-name' => $name], $language);

            return $activity;
        }));
    }

    /**
     * Set the values of the activity's elements in one language; the authorised name must keep a value.
     *
     * @param  array<string, ?string>  $values  element notation => text
     */
    public function describe(Activity $activity, array $values, string $language, ?User $actor = null): void
    {
        $this->descriptions->describe($activity, Elements::ACTIVITY, $values, $language, 'activity-name', $actor);
    }

    /** The control area (ISDF 5.4.1, 5.4.4, 5.4.5): description identifier, status and level of detail, as an agent has them. */
    public function updateControl(Activity $activity, ?string $descriptionIdentifier, ?string $statusIri, ?string $detailIri, ?User $actor = null): void
    {
        $id = fn (?string $iri) => $iri ? Concept::where('iri', $iri)->value('id') : null;
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $activity->update([
            'description_identifier' => ($descriptionIdentifier ?? '') === '' ? null : $descriptionIdentifier,
            'description_status_id' => $id($statusIri), 'description_detail_id' => $id($detailIri),
        ])));
    }

    public function updateType(Activity $activity, ?string $typeId, ?User $actor = null): void
    {
        $type = $this->type($activity->institution_id, $typeId);
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $activity->update(['activity_type_id' => $type?->id])));
    }

    /**
     * The activity types the institution may give: the OpenRiC activity-type vocabulary, then its own
     * activity-type vocabularies, each by label.
     *
     * @return Collection<int, Concept>
     */
    public function types(string $institutionId): Collection
    {
        $sort = fn (Collection $c) => $c->sortBy(fn (Concept $t) => mb_strtolower($t->anyLabel(['en'])))->values();

        return $sort(Concept::choices(Activity::TYPES)->with('vocabulary')->get())->merge($sort(
            Concept::where('institution_id', $institutionId)->whereNull('deprecated_at')
                ->whereHas('vocabulary.kind', fn ($q) => $q->where('iri', Vocab::SCHEME_KINDS.'activity-type'))->with('labels', 'vocabulary')->get()));
    }

    private function type(string $institutionId, ?string $typeId): ?Concept
    {
        if ($typeId === null || $typeId === '') {
            return null;
        }

        return $this->types($institutionId)->firstWhere('id', $typeId)
            ?? throw new InvalidArgumentException(__('an activity type is a concept of the OpenRiC activity-type vocabulary or of one of the institution\'s activity-type vocabularies'));
    }
}
