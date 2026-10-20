<?php

// OpenRiC Workspace - the public catalogue: search, pages and the relationship graph, without signing in (WS-009).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Everything here reads through PublicCatalogue, as the read API and OAI-PMH
// do: an entity appears once its name or title is published and the
// institution's Public role may read it, with its published values only,
// and its relations that stand. An entity the public may not see does not
// exist here (404). Files come through InstantiationService::deliverable().

namespace App\Http\Controllers;

use App\Api\PublicCatalogue;
use App\Description\Elements;
use App\Description\InstantiationService;
use App\Discovery\Graph;
use App\Discovery\Links;
use App\Files\FileResponse;
use App\Models\DigitalFile;
use App\Models\Relation;
use App\Relations\EntityKinds;
use App\Relations\RelationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DiscoverController extends Controller
{
    /** The description standard each kind of page shows. */
    private const STANDARDS = ['agent' => Elements::ISAAR_CPF, 'repository' => Elements::ISDIAH, 'place' => Elements::PLACE, 'activity' => Elements::ACTIVITY];

    /** What a search finds, by catalogue key, and what the results call it. */
    private const FOUND = ['records' => 'Archival descriptions', 'agents' => 'People, families and organisations', 'activities' => 'Activities', 'places' => 'Places', 'repositories' => 'Repositories'];

    public function home(Request $request): View
    {
        $catalogue = $this->catalogue($request);
        $query = trim((string) $request->query('q', ''));
        $kind = array_key_exists($request->query('kind'), self::FOUND) ? $request->query('kind') : null;
        $results = [];
        if ($query !== '') {
            foreach (array_keys(self::FOUND) as $key) {
                if ($kind === null || $kind === $key) {
                    $results[$key] = $catalogue->visible($key, $query);
                }
            }
        }
        $records = $query === '' ? $catalogue->visible('records', '', false, ['level.labels']) : collect();

        return view('discover.home', ['institution' => $catalogue->institution, 'query' => $query, 'kind' => $kind, 'results' => $results, 'kinds' => self::FOUND,
            'view' => $catalogue->view, 'tops' => $records->whereNull('parent_id')->values(), 'counts' => $query === '' ? collect(array_keys(self::FOUND))->mapWithKeys(fn ($k) => [$k => $k === 'records' ? $records->count() : $catalogue->visible($k)->count()]) : collect(),
            'repositories' => $query === '' ? $catalogue->visible('repositories') : collect()]);
    }

    public function record(Request $request, string $id): View
    {
        $catalogue = $this->catalogue($request);
        $unit = $catalogue->find('records', $id, ['level.labels', 'repository.names.nameType', 'repository.names.state', 'instantiations.files.use', 'instantiations.names.nameType',
            'instantiations.names.state', 'instantiations.availability', 'instantiations.carrierType.labels']) ?? abort(404);
        $view = $catalogue->view;
        $ancestors = array_values(array_filter($unit->ancestors(), fn ($a) => $view->readable($a)));
        $children = $unit->children()->with('names.nameType', 'names.state', 'level.labels', 'identifiers.identifierType', 'dates.property')->get()->filter(fn ($c) => $view->readable($c))->values();
        $service = app(InstantiationService::class);
        $instantiations = $unit->instantiations->filter(fn ($i) => $view->label($i) !== null)->map(fn ($i) => [
            'instantiation' => $i, 'files' => $i->files->filter(fn (DigitalFile $f) => $service->deliverable(null, $f->setRelation('instantiation', $i)))->keyBy(fn ($f) => $f->use->notation),
        ])->values();

        return view('discover.record', ['unit' => $unit, 'view' => $view, 'ancestors' => $ancestors, 'children' => $children, 'instantiations' => $instantiations,
            'relations' => $this->relations($unit, $catalogue), 'institution' => $catalogue->institution]);
    }

    public function agent(Request $request, string $id): View
    {
        return $this->entity($request, 'agent', $id, ['agentType.labels']);
    }

    public function repository(Request $request, string $id): View
    {
        return $this->entity($request, 'repository', $id, ['contactPoints']);
    }

    public function place(Request $request, string $id): View
    {
        return $this->entity($request, 'place', $id, ['placeType.labels', 'parent.names.state']);
    }

    public function activity(Request $request, string $id): View
    {
        return $this->entity($request, 'activity', $id, ['activityType.labels', 'activityType.vocabulary']);
    }

    /** The relationship graph around an entity, one or two steps out, drawn and as a list. */
    public function graph(Request $request, string $kind, string $id): View
    {
        $catalogue = $this->catalogue($request);
        $type = array_search($kind, Links::CATALOGUE, true);
        abort_if($type === false, 404);
        $entity = $catalogue->find($kind, $id) ?? abort(404);
        $depth = $request->query('depth') === '2' ? 2 : 1;
        $graph = (new Graph($catalogue->view, app(RelationService::class)))->build($entity, $depth);

        return view('discover.graph', ['entity' => $entity, 'graph' => $graph, 'depth' => $depth, 'kind' => $kind, 'view' => $catalogue->view, 'institution' => $catalogue->institution]);
    }

    public function file(Request $request, DigitalFile $file): BinaryFileResponse
    {
        $file->load('instantiation.record', 'instantiation.availability', 'use');
        $service = app(InstantiationService::class);
        abort_unless($file->institution_id === $this->catalogue($request)->institution->id && $service->deliverable(null, $file), 404);
        $path = $service->path($file);
        abort_unless(is_file($path), 404);

        return FileResponse::make($file, $path, $request->boolean('download'));
    }

    private function entity(Request $request, string $kind, string $id, array $with): View
    {
        $catalogue = $this->catalogue($request);
        $entity = $catalogue->find(Links::CATALOGUE[$kind], $id, $with) ?? abort(404);

        return view('discover.entity', ['entity' => $entity, 'kind' => $kind, 'standard' => self::STANDARDS[$kind], 'view' => $catalogue->view,
            'relations' => $this->relations($entity, $catalogue), 'institution' => $catalogue->institution]);
    }

    /** @return Collection<int, Relation> the relations that stand, whose other end the public may read */
    private function relations(Model $entity, PublicCatalogue $catalogue): Collection
    {
        return app(RelationService::class)->of($entity)
            ->filter(fn (Relation $r) => in_array($r->standing(), ['accepted', 'disputed'], true) && $catalogue->view->readable($r->other))
            ->sortBy(fn (Relation $r) => [$r->phrase(), mb_strtolower((string) $catalogue->view->label($r->other))])->values();
    }

    private function catalogue(Request $request): PublicCatalogue
    {
        return $request->attributes->get('catalogue');
    }

    /** For the views: the noun of an entity. */
    public static function noun(Model $entity): string
    {
        return EntityKinds::noun($entity);
    }
}
