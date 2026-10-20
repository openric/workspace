<?php

// OpenRiC Workspace - the relationship graph around an entity, as the public may see it (WS-009).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// From an entity outward, one or two steps: its relations that stand
// (accepted or disputed), and for a unit of description the unit above it,
// the units inside it and its holder. Every node is an entity the public may
// read, typed by its RiC-O class, with its published name and the page it
// opens; a relation to anything else is left out. Nodes are capped so the
// picture stays readable; the list beside it says when some were left out.

namespace App\Discovery;

use App\Exchange\ExportView;
use App\Models\Concept;
use App\Models\RecordResource;
use App\Models\Relation;
use App\Relations\EntityKinds;
use App\Relations\RelationService;
use Illuminate\Database\Eloquent\Model;

class Graph
{
    public const MAX_NODES = 40;

    /** How many units inside a unit are drawn. */
    private const MAX_CHILDREN = 12;

    /** @var array<string, array{entity: Model, depth: int, parent: ?string}> */
    public array $nodes = [];

    /** @var list<array{from: string, to: string, phrase: string, term: ?string}> */
    public array $edges = [];

    public bool $truncated = false;

    public function __construct(private ExportView $view, private RelationService $relations) {}

    public function build(Model $root, int $depth = 1): self
    {
        $this->nodes = [$root->getAttribute('iri') => ['entity' => $root, 'depth' => 0, 'parent' => null]];
        $frontier = [$root];
        for ($step = 1; $step <= $depth; $step++) {
            $next = [];
            foreach ($frontier as $entity) {
                foreach ($this->neighbours($entity) as [$other, $phrase, $term, $outward]) {
                    $from = $entity->getAttribute('iri');
                    $to = $other->getAttribute('iri');
                    if (! isset($this->nodes[$to])) {
                        if (count($this->nodes) >= self::MAX_NODES) {
                            $this->truncated = true;

                            continue;
                        }
                        $this->nodes[$to] = ['entity' => $other, 'depth' => $step, 'parent' => $from];
                        $next[] = $other;
                    }
                    [$a, $b] = $outward ? [$from, $to] : [$to, $from];
                    $this->addEdge($a, $b, $phrase, $term);
                }
            }
            $frontier = $next;
        }

        return $this;
    }

    /** @return list<array{0: Model, 1: string, 2: ?string, 3: bool}> neighbour, phrase from the edge's source, RiC term, whether the edge points away from $entity */
    private function neighbours(Model $entity): array
    {
        $found = [];
        foreach ($this->relations->of($entity) as $relation) {
            /** @var Relation $relation */
            if (! in_array($relation->standing(), ['accepted', 'disputed'], true) || ! $this->view->readable($relation->other)) {
                continue;
            }
            $type = config('workspace.relation_types.'.$relation->relationType->notation);
            $found[] = [$relation->other, $type['forward'] ?? $relation->relationType->label(['en']), $relation->relationType->ricTerm(), $relation->fromSource];
        }
        if ($entity instanceof RecordResource) {
            if ($entity->parent && $this->view->readable($entity->parent)) {
                $found[] = [$entity->parent, 'is or was included in', 'rico:isOrWasIncludedIn', true];
            }
            if ($entity->repository && $this->view->readable($entity->repository)) {
                $found[] = [$entity->repository, 'is or was held by', 'rico:hasOrHadHolder', true];
            }
            $children = $entity->children()->with('names.nameType', 'names.state', 'level.labels')->limit(self::MAX_CHILDREN + 1)->get()->filter(fn ($c) => $this->view->readable($c));
            if ($children->count() > self::MAX_CHILDREN) {
                $this->truncated = true;
            }
            foreach ($children->take(self::MAX_CHILDREN) as $child) {
                $found[] = [$child, 'is or was included in', 'rico:isOrWasIncludedIn', false];
            }
        }

        return $found;
    }

    private function addEdge(string $from, string $to, string $phrase, ?string $term): void
    {
        foreach ($this->edges as $edge) {
            if ($edge['from'] === $from && $edge['to'] === $to && $edge['phrase'] === $phrase) {
                return;
            }
        }
        $this->edges[] = ['from' => $from, 'to' => $to, 'phrase' => $phrase, 'term' => $term];
    }

    /** What a node shows: its published name, its RiC-O class and the public page it opens. */
    public function describe(string $iri): array
    {
        $entity = $this->nodes[$iri]['entity'];

        return ['label' => (string) ($this->view->label($entity) ?? $iri), 'class' => $entity instanceof Concept ? 'rico:Concept' : (string) EntityKinds::ricClass($entity),
            'url' => Links::page($entity), 'kind' => EntityKinds::of($entity)];
    }

    /**
     * Where each node is drawn: the entity in the middle, the first step on a ring around it, the second
     * step on an outer ring beside the node it was reached from.
     *
     * @return array<string, array{x: float, y: float}>
     */
    public function layout(float $inner = 210, float $outer = 390): array
    {
        $positions = [];
        $byDepth = [];
        foreach ($this->nodes as $iri => $node) {
            $byDepth[$node['depth']][] = $iri;
        }
        $angles = [];
        foreach ($byDepth[0] ?? [] as $iri) {
            $positions[$iri] = ['x' => 0.0, 'y' => 0.0];
        }
        foreach ($byDepth[1] ?? [] as $i => $iri) {
            $angles[$iri] = 2 * M_PI * $i / max(count($byDepth[1]), 1) - M_PI / 2;
            $positions[$iri] = ['x' => $inner * cos($angles[$iri]), 'y' => $inner * sin($angles[$iri])];
        }
        $outerNodes = $byDepth[2] ?? [];
        usort($outerNodes, fn ($a, $b) => ($angles[$this->nodes[$a]['parent']] ?? 0) <=> ($angles[$this->nodes[$b]['parent']] ?? 0));
        foreach ($outerNodes as $i => $iri) {
            $angle = 2 * M_PI * $i / max(count($outerNodes), 1) - M_PI / 2;
            $positions[$iri] = ['x' => $outer * cos($angle), 'y' => $outer * sin($angle)];
        }

        return $positions;
    }
}
