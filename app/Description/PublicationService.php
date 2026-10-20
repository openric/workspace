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
use App\Models\Concept;
use App\Models\DescriptionValue;
use App\Models\Instantiation;
use App\Models\Name;
use App\Models\RecordResource;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Model;
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
