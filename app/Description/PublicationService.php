<?php

// OpenRiC Workspace - review, publish and withdraw what an entity says (logical model, section 7).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Publication is a state on each language value and name, not a flag on a
// page (language-value-state vocabulary): a draft is reviewed, a draft or a
// reviewed value is published, a published one is withdrawn and may be
// published again. A changed value goes back to draft (DescriptionService), so
// what the public sees is always what someone published. As AtoM's "update
// publication status" does, a unit can be published with the units inside it.

namespace App\Description;

use App\Audit\AuditLog;
use App\Models\Activity;
use App\Models\Concept;
use App\Models\DescriptionValue;
use App\Models\Instantiation;
use App\Models\Name;
use App\Models\RecordResource;
use App\Models\User;
use App\Relations\RelationService;
use App\Support\Vocab;
use App\Webhooks\Webhooks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PublicationService
{
    /** action => [the states it applies to, the state it gives] */
    public const ACTIONS = [
        'review' => [['draft'], 'reviewed'],
        'publish' => [['draft', 'reviewed', 'withdrawn'], 'published'],
        'withdraw' => [['published'], 'withdrawn'],
    ];

    /**
     * Apply an action to the entity's values and names, in one language or all (null), and with
     * $inside, to every unit inside a unit of description. Returns how many values changed.
     */
    public function apply(Model $entity, string $action, ?string $language = null, bool $inside = false, ?User $actor = null): int
    {
        [$from, $to] = self::ACTIONS[$action] ?? throw new InvalidArgumentException("$action is not a publication action");
        if ($action === 'publish' && $entity instanceof Activity) {
            $this->mustBeProvenance($entity);
        }
        $fromIds = Concept::whereIn('iri', array_map(fn ($s) => Vocab::valueState($s), $from))->pluck('id');
        $toId = Concept::where('iri', Vocab::valueState($to))->value('id');
        $ids = [$entity->getKey()];
        if ($inside && $entity instanceof RecordResource) {
            $ids = [...$ids, ...RecordResource::whereKey($entity->descendantIds())->whereNull('deleted_at')->pluck('id')->all()];
        }

        // A unit's instantiations are part of its description: they are published, and withdrawn, with it.
        $subjects = [$entity->getTable() => $ids];
        if ($entity instanceof RecordResource) {
            $subjects['instantiations'] = Instantiation::whereIn('record_resource_id', $ids)->whereNull('deleted_at')->pluck('id')->all();
        }

        // Webhooks are told the entities that became public, or stopped being (phase 22c).
        if (in_array($action, ['publish', 'withdraw'], true)) {
            foreach ($subjects as $table => $subjectIds) {
                foreach ($subjectIds as $subjectId) {
                    Webhooks::mark($action === 'publish' ? 'published' : 'withdrawn', $table, $subjectId);
                }
            }
        }

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($subjects, $fromIds, $toId, $language) {
            $changed = 0;
            foreach ($subjects as $table => $subjectIds) {
                foreach ([DescriptionValue::class, Name::class] as $class) {
                    $rows = $class::where('subject_table', $table)->whereIn('subject_id', $subjectIds)->whereIn('state_id', $fromIds)
                        ->when($language, fn ($q) => $q->where('language', $language))->get();
                    // One by one, so each change is audited.
                    $rows->each(fn (Model $row) => $row->update(['state_id' => $toId]));
                    $changed += $rows->count();
                }
            }

            return $changed;
        }));
    }

    /**
     * The Provenance & Event profile (roadmap phase 24) holds what the public reads of an event to this: it has a date;
     * a production also names what it resulted in and who took part (or performed it); an accumulation what it resulted
     * in. An activity described as a function is not an event, and is not held to it.
     */
    private function mustBeProvenance(Activity $activity): void
    {
        if ($activity->is_function) {
            return;
        }
        $missing = [];
        if ($activity->dates()->doesntExist()) {
            $missing[] = __('a date');
        }
        $type = $activity->activityType?->iri;
        $has = fn (array $types) => app(RelationService::class)->of($activity)->contains(fn ($r) => in_array($r->kind(), $types, true));
        if (in_array($type, [Activity::TYPES.'production', Activity::TYPES.'accumulation'], true) && ! $has(['result'])) {
            $missing[] = __('what it resulted in');
        }
        if ($type === Activity::TYPES.'production' && ! $has(['participation', 'performance'])) {
            $missing[] = __('who took part in it or performed it');
        }
        if ($missing !== []) {
            throw new InvalidArgumentException(__('Before an activity is published it needs :what, so the public can follow its provenance.', ['what' => Arr::join($missing, ', ', __(' and '))]));
        }
    }

    /** @return array<string, array<string, int>> language => state notation => how many values and names */
    public function summary(Model $entity): array
    {
        $out = [];
        foreach (['description_values', 'names'] as $table) {
            $rows = DB::table($table.' as v')->join('concepts as s', 's.id', '=', 'v.state_id')
                ->where(['v.subject_table' => $entity->getTable(), 'v.subject_id' => $entity->getKey()])
                ->groupBy('v.language', 's.notation')->selectRaw('v.language, s.notation, count(*) as n')->get();
            foreach ($rows as $row) {
                $out[$row->language][$row->notation] = ($out[$row->language][$row->notation] ?? 0) + $row->n;
            }
        }
        ksort($out);

        return $out;
    }
}
