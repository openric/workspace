<?php

// OpenRiC Workspace - what the evaluators know of a candidate (roadmap phase 17b2), read from the Workspace's RiC
// description where Heratio's evaluators read AtoM's tables: an agent's years (its dates of existence as RiC dates,
// else its ISAAR(CPF) 5.2.1 text), the places it is related to and its ISAAR(CPF) 5.2.3 places, the agents it is
// related to, the text of its history, functions, mandates and legal status; a place's broader places, the agents
// related to it and its years. Relations that are rejected are left out. Each answer is kept for the request.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Authority\Evidence;

use App\Models\Agent;
use App\Models\Place;
use App\Models\Relation;
use App\Relations\RelationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Facts
{
    private const LANGUAGES = ['en', 'af'];

    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(private RelationService $relations) {}

    public function entity(string $table, string $id): ?Model
    {
        return $this->memo["entity:$table:$id"] ??= ($table === 'agents' ? Agent::class : Place::class)::with('dates', 'descriptionValues.element', 'names.nameType')->find($id);
    }

    /** The years it existed in, and whence they were read. @return array{span: ?array{start:int,end:int}, text: ?string} */
    public function years(string $table, string $id): array
    {
        return $this->memo["years:$table:$id"] ??= (function () use ($table, $id) {
            $entity = $this->entity($table, $id);
            if ($entity === null) {
                return ['span' => null, 'text' => null];
            }
            $bounds = $entity->dates->map(fn ($d) => [$d->earliest?->year ?? null, $d->latest?->year ?? null]);
            $earliest = $bounds->pluck(0)->filter()->min();
            $latest = $bounds->pluck(1)->filter()->max();
            if ($earliest || $latest) {
                return ['span' => ['start' => (int) ($earliest ?? $latest), 'end' => (int) ($latest ?? $earliest)], 'text' => $entity->dates->map->display()->filter()->join('; ')];
            }
            $text = $table === 'agents' ? $entity->described('agent-dates-of-existence', self::LANGUAGES) : null;

            return ['span' => $text ? Years::parseYearSpan($text) : null, 'text' => $text];
        })();
    }

    /** The places an agent is related to, and those its description names. @return list<string> */
    public function agentPlaces(string $agentId): array
    {
        return $this->memo["places:$agentId"] ??= (function () use ($agentId) {
            $agent = $this->entity('agents', $agentId);
            if ($agent === null) {
                return [];
            }
            $related = $this->related($agent)->filter(fn ($r) => $r->other instanceof Place)->map(fn ($r) => (string) $r->other->described('authorised-name', self::LANGUAGES));
            $text = preg_split('/[,;\n|]/u', (string) $agent->described('agent-places', self::LANGUAGES));

            return array_values(array_unique(array_filter(array_map('trim', [...$related->all(), ...$text]))));
        })();
    }

    /** The agents an agent or a place is related to, by name. @return array<string, string> agent id => name */
    public function associates(string $table, string $id): array
    {
        return $this->memo["associates:$table:$id"] ??= (function () use ($table, $id) {
            $entity = $this->entity($table, $id);

            return $entity === null ? [] : $this->related($entity)->filter(fn ($r) => $r->other instanceof Agent)
                ->mapWithKeys(fn ($r) => [$r->other->id => (string) $r->other->described('authorised-name', self::LANGUAGES)])->filter()->all();
        })();
    }

    /** An agent's history, functions, mandates and legal status, as one text. */
    public function history(string $agentId): ?string
    {
        $agent = $this->entity('agents', $agentId);
        $text = $agent === null ? '' : trim(implode(' ', array_filter(array_map(fn ($e) => $agent->described($e, self::LANGUAGES),
            ['agent-history', 'agent-functions', 'agent-mandates', 'agent-legal-status']))));

        return $text === '' ? null : $text;
    }

    /** A place's broader places, nearest first. @return list<array{id: string, name: string}> */
    public function ancestors(string $placeId): array
    {
        return $this->memo["ancestors:$placeId"] ??= (function () use ($placeId) {
            $out = [];
            $seen = [$placeId => true];
            $place = Place::find($placeId);
            for ($depth = 0; $depth < 16 && $place?->parent_id && ! isset($seen[$place->parent_id]); $depth++) {
                $seen[$place->parent_id] = true;
                $place = Place::find($place->parent_id);
                if ($place && ($name = (string) $place->described('authorised-name', self::LANGUAGES)) !== '') {
                    $out[] = ['id' => $place->id, 'name' => $name];
                }
            }

            return $out;
        })();
    }

    /** @return Collection<int, Relation> */
    private function related(Model $entity): Collection
    {
        return $this->memo['related:'.$entity->getKey()] ??= $this->relations->of($entity)->filter(fn ($r) => $r->standing() !== 'rejected')->values();
    }
}
