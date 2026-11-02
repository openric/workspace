<?php

// OpenRiC Workspace - the Graph Traversal profile (0.6.0): the subgraph around an entity, every relation, the relations
// of one entity, and the hierarchy of a unit of description or a place (roadmap phase 23b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The reference service (openric/service, LinkedDataApiController graph, listRelations, relationsFor and hierarchy)
// reads Heratio's relation table; this reads the Workspace's relations through the same mapping the JSON-LD writes
// (relation_types.ric_property and ric_inverse), as the public sees them: relations that stand (accepted or disputed)
// between entities the public may read. Ids are the Workspace's UUIDs, where the profile's schemas expect integers
// (conformance/README.md, departures).

namespace App\Http\Controllers\Api;

use App\Api\Problem;
use App\Discovery\Graph;
use App\Models\Place;
use App\Models\Relation;
use App\Relations\EntityKinds;
use App\Relations\RelationService;
use App\Relations\RelationTypes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class GraphController extends DiscoveryController
{
    /** The IRI segments /graph accepts, and the catalogue each is found in. */
    private const SEGMENTS = ['record' => 'records', 'agent' => 'agents', 'repository' => 'repositories', 'place' => 'places', 'activity' => 'activities', 'rule' => 'rules',
        'instantiation' => 'instantiations'];

    public function graph(Request $request): JsonResponse
    {
        $uri = (string) $request->query('uri', '');
        $depth = $request->query('depth', '1');
        if ($uri === '') {
            return Problem::response($request, 400, 'uri is required: the @id of the entity the graph is built around.');
        }
        if (! ctype_digit((string) $depth) || (int) $depth < 1 || (int) $depth > 3) {
            return Problem::response($request, 400, 'depth is a whole number from 1 to 3.');
        }
        if (! preg_match('#/([a-z]+)/([0-9a-f-]{36})$#', $uri, $m) || ! isset(self::SEGMENTS[$m[1]])) {
            return Problem::response($request, 400, 'uri names no kind of entity this service has: '.implode(', ', array_keys(self::SEGMENTS)).'.');
        }
        $root = $this->catalogue($request)->find(self::SEGMENTS[$m[1]], $m[2]);
        if ($root === null || $root->getAttribute('iri') !== $uri) {
            return Problem::response($request, 404, "Nothing published here has the IRI $uri.");
        }
        $graph = (new Graph($this->view($request), app(RelationService::class)))->build($root, (int) $depth);

        return $this->json(['@context' => ['rico' => 'https://www.ica.org/standards/RiC/ontology#', 'openric' => 'https://openric.org/ns/v1#'],
            ...$graph->subgraph(), 'openric:root' => $uri, 'openric:depth' => (int) $depth]);
    }

    /** Every relation the public may follow, paginated (3.2). */
    public function relations(Request $request): JsonResponse
    {
        $page = $this->bounded($request, 'page', 1, PHP_INT_MAX);
        $limit = $this->bounded($request, 'limit', 50, 200);
        if ($page === null || $limit === null) {
            return Problem::response($request, 400, 'page is a whole number from 1, and limit one from 1 to 200.');
        }
        $all = $this->followable($request, Relation::where('institution_id', $this->institution($request)->id)->orderBy('created_at')->orderBy('id')->get());

        return response()->json(['data' => $all->slice(($page - 1) * $limit, $limit)->map(fn (array $r) => $this->row($r))->values()->all(),
            'pagination' => ['page' => $page, 'per_page' => $limit, 'total' => $all->count(), 'last_page' => max(1, (int) ceil($all->count() / $limit))]],
            200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** The relations of one entity, outgoing and incoming (3.3). */
    public function relationsFor(Request $request, string $id): JsonResponse
    {
        $entity = $this->entity($request, $id) ?? abort(404);
        $relations = $this->followable($request, Relation::where(fn ($q) => $q->where(['source_table' => $entity->getTable(), 'source_id' => $id])
            ->orWhere(fn ($q) => $q->where(['target_table' => $entity->getTable(), 'target_id' => $id])))->orderBy('created_at')->get());
        $view = $this->view($request);
        $side = function (array $r, bool $outgoing) use ($view) {
            $other = $outgoing ? $r['target'] : $r['source'];

            return array_filter(['id' => $r['relation']->id, 'direction' => $outgoing ? 'outgoing' : 'incoming', 'target_id' => $other->getKey(),
                'target_name' => $view->label($other), 'target_type' => $this->local(EntityKinds::ricClass($other)),
                'rico_predicate' => $outgoing ? $r['type']['ric_property'] : ($r['type']['ric_inverse'] ?? $r['type']['ric_property']),
                'inverse_predicate' => $outgoing ? $r['type']['ric_inverse'] : $r['type']['ric_property'], 'dropdown_code' => $r['type']['kind'],
                'relation_label' => $outgoing ? $r['type']['forward'] : $r['type']['inverse']] + $this->qualifiers($r), fn ($v) => $v !== null);
        };
        $outgoing = $relations->filter(fn ($r) => $r['source']->getKey() === $id)->map(fn ($r) => $side($r, true))->values()->all();
        $incoming = $relations->filter(fn ($r) => $r['target']->getKey() === $id)->map(fn ($r) => $side($r, false))->values()->all();

        return response()->json(['entity_id' => $id, 'total' => count($outgoing) + count($incoming), 'outgoing' => $outgoing, 'incoming' => $incoming],
            200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** The unit above, the units inside and the units beside a unit of description, or the same of a place (3.4). */
    public function hierarchy(Request $request, string $id): JsonResponse
    {
        $catalogue = $this->catalogue($request);
        $entity = $catalogue->find('records', $id, ['level']) ?? $catalogue->find('places', $id, ['placeType']) ?? abort(404);
        $view = $this->view($request);
        $class = $entity::class;
        $parent = $entity->parent_id ? $catalogue->find($entity instanceof Place ? 'places' : 'records', $entity->parent_id) : null;
        $children = $class::where('parent_id', $id)->whereNull('deleted_at')->get()->filter(fn ($c) => $view->readable($c));
        $siblings = $entity->parent_id ? $class::where('parent_id', $entity->parent_id)->whereKeyNot($id)->whereNull('deleted_at')->get()->filter(fn ($c) => $view->readable($c)) : collect();
        $ref = fn (Model $e) => array_filter(['id' => $e->getKey(), 'name' => $view->label($e), 'type_id' => ($e instanceof Place ? $e->placeType : $e->level)?->notation,
            'class' => $this->local(EntityKinds::ricClass($e))], fn ($v) => $v !== null);

        return response()->json(['entity_id' => $id, 'class' => $this->local(EntityKinds::ricClass($entity)), 'parent' => $parent ? $ref($parent) : null,
            'children' => $children->map($ref)->values()->all(), 'siblings' => $siblings->map($ref)->values()->all()], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The relations among these the public may follow, each with its two ends and its type.
     *
     * @return Collection<int, array{relation: Relation, source: Model, target: Model, type: array}>
     */
    private function followable(Request $request, Collection $relations): Collection
    {
        $view = $this->view($request);
        $load = function (string $table, string $id) {
            $definition = EntityKinds::get(EntityKinds::byTable($table));

            return $definition ? $definition['class']::with($definition['with'])->find($id) : null;
        };

        return $relations->map(function (Relation $relation) use ($load, $view) {
            if (! in_array($relation->standing(), ['accepted', 'disputed'], true)) {
                return null;
            }
            $source = $load($relation->source_table, $relation->source_id);
            $target = $load($relation->target_table, $relation->target_id);
            $type = RelationTypes::of($relation);
            if (! $source || ! $target || ! $view->readable($source) || ! $view->readable($target) || ($type['ric_property'] ?? null) === null) {
                return null;
            }

            return ['relation' => $relation, 'source' => $source, 'target' => $target, 'type' => $type];
        })->filter()->values();
    }

    private function row(array $r): array
    {
        return array_filter(['id' => $r['relation']->id, 'subject_id' => $r['source']->getKey(), 'object_id' => $r['target']->getKey(),
            'subject_class' => $r['source']->getTable(), 'object_class' => $r['target']->getTable(), 'rico_predicate' => $r['type']['ric_property'],
            'inverse_predicate' => $r['type']['ric_inverse'], 'dropdown_code' => $r['type']['kind'], 'domain_class' => $this->local(EntityKinds::ricClass($r['source'])),
            'range_class' => $this->local(EntityKinds::ricClass($r['target']))] + $this->qualifiers($r), fn ($v) => $v !== null);
    }

    /** Its dates, certainty and the published units given as its evidence. */
    private function qualifiers(array $r): array
    {
        $relation = $r['relation']->loadMissing('dates.property', 'certainty', 'evidence.record');
        $span = $relation->datedAs('relation')?->normalized_value;
        [$from, $to] = $span !== null && str_contains($span, '/') ? explode('/', $span, 2) : [$span, null];
        $view = $this->view(request());
        $evidence = $relation->evidence->map(fn ($e) => $e->record && $view->readable($e->record) ? $view->label($e->record) : null)->filter()->implode('; ');

        return ['start_date' => $relation->datedAs('beginning')?->normalized_value ?? $from, 'end_date' => $relation->datedAs('end')?->normalized_value ?? $to,
            'certainty' => $relation->certainty?->notation, 'evidence' => $evidence !== '' ? $evidence : null];
    }

    /** An entity of any kind the public may read, by its UUID. */
    private function entity(Request $request, string $id): ?Model
    {
        foreach (array_unique(self::SEGMENTS) as $catalogue) {
            if ($found = $this->catalogue($request)->find($catalogue, $id)) {
                return $found;
            }
        }

        return null;
    }

    /** rico:Person -> Person: the profile's RiC-O local names. */
    private function local(?string $class): ?string
    {
        return $class === null ? null : preg_replace('/^[a-z]+:/', '', $class);
    }
}
