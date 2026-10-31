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
use App\Description\DeprecationService;
use App\Description\Elements;
use App\Description\InstantiationService;
use App\Description\TreeService;
use App\Discovery\Graph;
use App\Discovery\Links;
use App\Files\FileResponse;
use App\Iiif\Presentation;
use App\Models\Concept;
use App\Models\DigitalFile;
use App\Models\RecordResource;
use App\Models\Relation;
use App\Models\Repository;
use App\Relations\EntityKinds;
use App\Relations\RelationService;
use App\Reports\UsageService;
use App\Search\SearchIndex;
use App\Search\SearchService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        // Full-text search of what the public may read (ADR 0009), with facets.
        $search = null;
        if ($query !== '') {
            $filters = ['kind' => $kind, 'level' => $this->uuidOrNull($request->query('level')), 'repository' => $this->uuidOrNull($request->query('repository')),
                'from' => $this->yearOrNull($request->query('from')), 'to' => $this->yearOrNull($request->query('to'))];
            $search = app(SearchService::class)->search($catalogue->institution, $query, true, $filters, max(1, (int) $request->query('page', 1)));
            $search['filters'] = $filters;
            $search['page'] = max(1, (int) $request->query('page', 1));
            $byTable = collect($search['results'])->groupBy('table');
            $entities = $byTable->flatMap(fn ($rows, $table) => collect(SearchIndex::KINDS)->first(fn ($c) => (new $c)->getTable() === $table)::whereIn('id', $rows->pluck('id'))->get())->keyBy('id');
            $search['results'] = collect($search['results'])->map(fn ($row) => $row + ['entity' => $entities->get($row['id'])])->filter(fn ($row) => $row['entity'] !== null)->values();
            $search['levels'] = Concept::whereIn('id', array_keys($search['facets']['level']))->with('labels')->get()->keyBy('id');
            $search['repositories'] = Repository::whereIn('id', array_keys($search['facets']['repository']))->with(['names.nameType', 'names.state'])->get()->keyBy('id');
        }
        $records = $query === '' ? $catalogue->visible('records', '', false, ['level.labels']) : collect();

        return view('discover.home', ['institution' => $catalogue->institution, 'query' => $query, 'kind' => $kind, 'search' => $search, 'kinds' => self::FOUND,
            'featured' => $query === '' ? $this->featured($catalogue, $records) : collect(),
            'view' => $catalogue->view, 'tops' => $records->whereNull('parent_id')->values(), 'counts' => $query === '' ? collect(array_keys(self::FOUND))->mapWithKeys(fn ($k) => [$k => $k === 'records' ? $records->count() : $catalogue->visible($k)->count()]) : collect(),
            'repositories' => $query === '' ? $catalogue->visible('repositories') : collect()]);
    }

    /**
     * The collections the home page's carousel shows: each published unit at the top that has a picture the public may
     * see, in itself or in a unit inside it, newest first, with how many published units it holds and the start of its
     * scope and content. The picture is the thumbnail, else the reference copy, of the first such file.
     *
     * @param  Collection<int, RecordResource>  $records  every published unit
     * @return Collection<int, array{unit: RecordResource, file: DigitalFile, count: int, summary: ?string}>
     */
    private function featured(PublicCatalogue $catalogue, Collection $records): Collection
    {
        $parents = $records->pluck('parent_id', 'id');
        $top = function (string $id) use ($parents): string {
            for ($seen = 0; ($parents[$id] ?? null) !== null && $seen < 100; $seen++) {
                $id = $parents[$id];
            }

            return $id;
        };
        $inside = $records->countBy(fn ($unit) => $top($unit->id));
        $service = app(InstantiationService::class);
        $pictures = [];
        DigitalFile::query()->whereHas('use', fn ($q) => $q->whereIn('notation', ['thumbnail', 'reference']))->where('mime_type', 'like', 'image/%')
            ->whereHas('instantiation', fn ($q) => $q->whereIn('record_resource_id', $parents->keys())->whereNull('deleted_at'))
            ->with('use', 'instantiation.record', 'instantiation.availability')->orderBy('created_at')->get()
            ->sortBy(fn (DigitalFile $f) => $f->use->notation === 'thumbnail' ? 0 : 1)
            ->each(function (DigitalFile $file) use (&$pictures, $top, $service) {
                $key = $top($file->instantiation->record_resource_id);
                if (! isset($pictures[$key]) && $service->deliverable(null, $file)) {
                    $pictures[$key] = $file;
                }
            });

        return $records->whereNull('parent_id')->filter(fn ($unit) => isset($pictures[$unit->id]))->sortByDesc('created_at')->take(12)->values()
            ->map(fn ($unit) => ['unit' => $unit, 'file' => $pictures[$unit->id], 'count' => max(0, ($inside[$unit->id] ?? 1) - 1),
                'summary' => ($text = $catalogue->view->value($unit, 'scope-and-content')) ? Str::limit(trim(preg_replace('/\s+/', ' ', $text)), 140) : null]);
    }

    public function record(Request $request, string $id): View
    {
        $catalogue = $this->catalogue($request);
        $unit = $catalogue->find('records', $id, ['level.labels', 'repository.names.nameType', 'repository.names.state', 'instantiations.files.use', 'instantiations.names.nameType',
            'instantiations.names.state', 'instantiations.availability', 'instantiations.carrierType.labels']) ?? $this->gone($catalogue, 'record_resources', $id);
        app(UsageService::class)->record($request, $catalogue->institution, 'view', $unit);
        $view = $catalogue->view;
        $ancestors = array_values(array_filter($unit->ancestors(), fn ($a) => $view->readable($a)));
        $children = $unit->children()->with('names.nameType', 'names.state', 'level.labels', 'identifiers.identifierType', 'dates.property')->get()->filter(fn ($c) => $view->readable($c))->values();
        $service = app(InstantiationService::class);
        $instantiations = $unit->instantiations->filter(fn ($i) => $view->label($i) !== null)->map(fn ($i) => [
            'instantiation' => $i, 'files' => $i->files->filter(fn (DigitalFile $f) => $service->deliverable(null, $f->setRelation('instantiation', $i)))->keyBy(fn ($f) => $f->use->notation),
        ])->values();

        [$childThumbnails, $childThumbnailTotal] = $this->childThumbnails($catalogue, $unit);

        return view('discover.record', ['unit' => $unit, 'view' => $view, 'ancestors' => $ancestors, 'children' => $children, 'instantiations' => $instantiations,
            'relations' => $this->relations($unit, $catalogue), 'institution' => $catalogue->institution,
            'childThumbnails' => $childThumbnails, 'childThumbnailTotal' => $childThumbnailTotal,
            'tree' => (new TreeService(null, $catalogue))->window($unit), 'iiif' => (new Presentation($catalogue))->hasManifest($unit)]);
    }

    /**
     * Heratio's imageflow strip (InformationObjectController childThumbnails): a thumbnail for each published unit inside
     * this one that has a picture the public may see, linking to that unit; at most 24, with how many there are.
     *
     * @return array{0: Collection<int, array{unit: RecordResource, file: DigitalFile}>, 1: int}
     */
    private function childThumbnails(PublicCatalogue $catalogue, RecordResource $unit): array
    {
        // "View children as compound?" No: the units inside are reached one by one.
        if (! $unit->display_as_compound) {
            return [collect(), 0];
        }
        $records = $catalogue->visible('records');
        $parents = $records->pluck('parent_id', 'id');
        $inside = $records->filter(function ($r) use ($parents, $unit) {
            for ($id = $parents[$r->id] ?? null, $seen = 0; $id !== null && $seen < 100; $id = $parents[$id] ?? null, $seen++) {
                if ($id === $unit->id) {
                    return true;
                }
            }

            return false;
        })->keyBy('id');
        if ($inside->isEmpty()) {
            return [collect(), 0];
        }
        $service = app(InstantiationService::class);
        $found = [];
        DigitalFile::query()->whereHas('use', fn ($q) => $q->where('notation', 'thumbnail'))
            ->whereHas('instantiation', fn ($q) => $q->whereIn('record_resource_id', $inside->keys())->whereNull('deleted_at'))
            ->with('use', 'instantiation.record', 'instantiation.availability')->orderBy('created_at')->get()
            ->each(function (DigitalFile $file) use (&$found, $service) {
                $owner = $file->instantiation->record_resource_id;
                if (! isset($found[$owner]) && $service->deliverable(null, $file)) {
                    $found[$owner] = $file;
                }
            });
        $tiles = collect($found)->map(fn ($file, $id) => ['unit' => $inside[$id], 'file' => $file])->values();

        return [$tiles->take(24), $tiles->count()];
    }

    /** A branch of the hierarchy tree in the public catalogue: published units only. */
    public function treeChildren(Request $request, string $id): JsonResponse
    {
        $catalogue = $this->catalogue($request);
        $unit = $catalogue->find('records', $id) ?? abort(404);

        return response()->json((new TreeService(null, $catalogue))->children($unit->id, max(0, (int) $request->query('offset', 0))));
    }

    public function agent(Request $request, string $id): View
    {
        return $this->entity($request, 'agent', $id, ['agentType.labels']);
    }

    public function repository(Request $request, string $id): View
    {
        // Its holdings, as AtoM lists them: the published units at the top that it holds, with what each holds inside.
        $holdings = $this->catalogue($request)->visible('records', '', false, ['level.labels'])
            ->where('repository_id', $id)->whereNull('parent_id')->sortBy(fn ($unit) => mb_strtolower((string) $this->catalogue($request)->view->label($unit)))->values();

        return $this->entity($request, 'repository', $id, ['contactPoints'], ['holdings' => $holdings]);
    }

    /** A published repository's banner or logo (its theme). */
    public function repositoryImage(Request $request, string $id, string $part): BinaryFileResponse
    {
        $repository = $this->catalogue($request)->find('repositories', $id) ?? abort(404);
        $path = $repository->getAttribute("theme_{$part}_path");
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'public, max-age=86400']);
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
        // The public's files, and a signed-in reader's too: a file on request opens to who may have it, as the IIIF viewer
        // asks for it once they have signed in (Auth Flow 2.0, phase 14b). Every file is sent private, no-store.
        abort_unless($file->institution_id === $this->catalogue($request)->institution->id
            && ($service->deliverable(null, $file) || ($request->user() !== null && $service->deliverable($request->user(), $file))), 404);
        $path = $service->path($file);
        abort_unless(is_file($path), 404);
        // A picture shown in a page is not a download; a file asked for as one, or opened on its own, is.
        if ($request->boolean('download') || ! str_starts_with($file->mime_type, 'image/')) {
            app(UsageService::class)->record($request, $this->catalogue($request)->institution, 'download', $file->instantiation->record, $file);
        }

        return FileResponse::make($file, $path, $request->boolean('download'));
    }

    private function entity(Request $request, string $kind, string $id, array $with, array $more = []): View
    {
        $catalogue = $this->catalogue($request);
        $entity = $catalogue->find(Links::CATALOGUE[$kind], $id, $with) ?? $this->gone($catalogue, ['agent' => 'agents', 'repository' => 'repositories', 'place' => 'places', 'activity' => 'activities'][$kind] ?? null, $id);
        app(UsageService::class)->record($request, $catalogue->institution, 'view', $entity);

        return view('discover.entity', ['entity' => $entity, 'kind' => $kind, 'standard' => $entity instanceof RecordResource ? $entity->standard() : self::STANDARDS[$kind], 'view' => $catalogue->view,
            'relations' => $this->relations($entity, $catalogue), 'institution' => $catalogue->institution, ...$more]);
    }

    /** @return Collection<int, Relation> the relations that stand, whose other end the public may read */
    private function relations(Model $entity, PublicCatalogue $catalogue): Collection
    {
        return app(RelationService::class)->of($entity)
            ->filter(fn (Relation $r) => in_array($r->standing(), ['accepted', 'disputed'], true) && $catalogue->view->readable($r->other))
            ->sortBy(fn (Relation $r) => [$r->phrase(), mb_strtolower((string) $catalogue->view->label($r->other))])->values();
    }

    /** A page the public saw, now deleted or merged: 410, saying why and what replaces it (ADR 0013); else 404. */
    private function gone(PublicCatalogue $catalogue, ?string $table, string $id): never
    {
        $deprecation = $table === null ? null : app(DeprecationService::class)->known($catalogue->institution, $table, $id);
        abort($deprecation ? response()->view('discover.withdrawn', ['deprecation' => $deprecation, 'institution' => $catalogue->institution], 410) : 404);
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

    private function uuidOrNull(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) ? $value : null;
    }

    private function yearOrNull(mixed $value): ?int
    {
        return is_string($value) && preg_match('/^\d{1,4}$/', $value) ? (int) $value : null;
    }
}
