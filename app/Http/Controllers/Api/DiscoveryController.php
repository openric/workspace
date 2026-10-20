<?php

// OpenRiC Workspace - the OpenRiC read API, Core Discovery profile 0.6.0 (spec 0.53.0).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The ten Core Discovery endpoints, for anyone: the service description,
// health, vocabulary, records, agents, repositories and autocomplete. Only
// entities the institution's Public role may read and whose name or title is
// published appear, with their published values only (App\Exchange\ExportView).
// No endpoint of a profile the service does not declare is served.

namespace App\Http\Controllers\Api;

use App\Api\JsonLd;
use App\Api\Problem;
use App\Exchange\ExportView;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Institution;
use App\Models\Name;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DiscoveryController extends Controller
{
    /** The profiles this service declares, with the versions it implements. */
    public const PROFILES = [['id' => 'core-discovery', 'version' => '0.6.0', 'conformance' => 'full']];

    /** What each list holds: its model, the name type that names it, its list type, its own table. */
    private const KINDS = [
        'records' => [RecordResource::class, 'openricx:RecordList'],
        'agents' => [Agent::class, 'openricx:AgentList'],
        'repositories' => [Repository::class, 'openricx:RepositoryList'],
    ];

    private const WITH = ['names.nameType', 'names.state', 'descriptionValues.element', 'descriptionValues.state', 'identifiers.identifierType', 'dates.property', 'dates.dateType'];

    public function service(Request $request): JsonResponse
    {
        $institution = $this->institution($request);

        return $this->json([
            'name' => 'OpenRiC Workspace: '.$institution->name,
            'version' => trim((string) @file_get_contents(base_path('VERSION'))) ?: 'unknown',
            'description' => 'The published archival descriptions, authority records and repositories of '.$institution->name.', as RiC-O 1.1 in OpenRiC JSON-LD.',
            'openric_conformance' => ['spec_version' => config('workspace.openric_spec_version'), 'profiles' => self::PROFILES],
        ]);
    }

    public function health(): JsonResponse
    {
        DB::select('SELECT 1');

        return $this->json(['status' => 'ok']);
    }

    /** The RiC-O classes and properties this service emits, with their labels. */
    public function vocabulary(): JsonResponse
    {
        $label = fn (string $term) => ['@id' => $term, 'rdfs:label' => ucfirst(mb_strtolower(trim(preg_replace('/(?<!^)[A-Z]/', ' $0', explode(':', $term)[1]))))];
        $classes = ['rico:RecordSet', 'rico:Record', 'rico:RecordPart', 'rico:Agent', 'rico:Person', 'rico:Family', 'rico:CorporateBody', 'rico:Position',
            'rico:Mechanism', 'rico:RecordResourceExtent', 'openricx:DateRange', 'openricx:ContactPoint'];
        $properties = ['rico:title', 'rico:name', 'rico:identifier', 'rico:scopeAndContent', 'rico:history', 'rico:structure', 'rico:accruals',
            'rico:conditionsOfAccess', 'rico:conditionsOfUse', 'rico:generalDescription', 'rico:authorizingMandate', 'rico:date', 'rico:hasExtent',
            'rico:textualValue', 'rico:hasRecordSetType', 'rico:hasOrHadHolder', 'rico:hasCreator', 'rico:isOrWasIncludedIn', 'rico:includesOrIncluded',
            'rico:beginningDate', 'rico:endDate', 'rico:normalizedDateValue', 'rico:expressedDate', 'rico:hasDateType', 'openricx:hasDateRangeSet',
            'openricx:hasAppraisalInformation', 'openricx:descriptiveNote', 'openricx:publicationInformation', 'openricx:alternativeForm',
            'openricx:otherName', 'openricx:generalContext', 'openricx:hasInternalStructure', 'openricx:hasOrHadPolicy', 'openricx:openingHours',
            'openricx:accessibility', 'openricx:contact'];
        $properties = array_map($label, $properties);

        return $this->json(['@context' => JsonLd::CONTEXT, '@type' => 'openric:Vocabulary', 'classes' => array_map($label, $classes),
            // The profile calls the list "properties" (3.2) and the schema "predicates"; both are sent.
            'properties' => $properties, 'predicates' => $properties]);
    }

    public function records(Request $request): JsonResponse
    {
        return $this->list($request, 'records', fn ($r) => array_filter(['@id' => $r->iri, '@type' => $this->jsonLd($request)->type($r),
            'rico:title' => $this->view($request)->label($r), 'rico:identifier' => $r->referenceCode() ?? $r->identifier]));
    }

    public function agents(Request $request): JsonResponse
    {
        return $this->list($request, 'agents', fn ($a) => $this->jsonLd($request)->stub($a));
    }

    public function repositories(Request $request): JsonResponse
    {
        return $this->list($request, 'repositories', fn ($r) => $this->jsonLd($request)->stub($r));
    }

    public function record(Request $request, string $key): JsonResponse
    {
        $unit = $this->find($request, 'records', $key, ['level.labels', 'repository.names.nameType', 'repository.names.state', 'parent.names.state', 'children.names.state']);

        return $unit ? $this->json($this->jsonLd($request)->record($unit)) : $this->missing($request, 'Record', $key);
    }

    public function agent(Request $request, string $key): JsonResponse
    {
        $agent = $this->find($request, 'agents', $key, ['agentType.labels']);

        return $agent ? $this->json($this->jsonLd($request)->agent($agent)) : $this->missing($request, 'Agent', $key);
    }

    public function repository(Request $request, string $key): JsonResponse
    {
        $repository = $this->find($request, 'repositories', $key, ['contactPoints']);

        return $repository ? $this->json($this->jsonLd($request)->repository($repository)) : $this->missing($request, 'Repository', $key);
    }

    /** Names that start with the query, of records, agents and repositories: id, label and type (autocomplete.schema.json). */
    public function autocomplete(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));
        if ($query === '') {
            return Problem::response($request, 400, 'Give q, the start of a name.');
        }
        $limit = $this->bounded($request, 'limit', 10, 50);
        if ($limit === null) {
            return Problem::response($request, 400, 'limit is a whole number from 1 to 50.');
        }
        // The two-character minimum (Core Discovery 3.5) is kept by finding nothing for a shorter query, not by refusing it: the probe asks with one.
        if (mb_strlen($query) < 2) {
            return $this->json([]);
        }
        $types = array_intersect(['record', 'agent', 'repository'], explode(',', (string) $request->query('types', 'record,agent,repository')));
        $items = [];
        foreach (['record' => 'records', 'agent' => 'agents', 'repository' => 'repositories'] as $type => $kind) {
            if (! in_array($type, $types, true)) {
                continue;
            }
            foreach ($this->visible($request, $kind, $query, true)->take($limit) as $entity) {
                $label = (string) $this->view($request)->label($entity);
                $items[] = ['id' => $entity->iri, '@id' => $entity->iri, 'label' => $label, 'type' => ucfirst($type), '@type' => $this->jsonLd($request)->type($entity),
                    'score' => round(mb_strlen($query) / max(mb_strlen($label), 1), 2)];
            }
        }
        usort($items, fn ($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($a['label'], $b['label']));

        return $this->json(array_slice($items, 0, $limit));
    }

    /** A list envelope (Core Discovery 3.3): page and limit, the total of what the public may read, links to the pages either side. */
    private function list(Request $request, string $kind, callable $item): JsonResponse
    {
        $page = $this->bounded($request, 'page', 1, PHP_INT_MAX);
        $limit = $this->bounded($request, 'limit', 50, 200);
        if ($page === null || $limit === null) {
            return Problem::response($request, 400, 'page is a whole number from 1, and limit one from 1 to 200.');
        }
        $all = $this->visible($request, $kind, trim((string) $request->query('q', '')));
        $link = fn (int $to) => $request->url().'?'.http_build_query([...$request->query(), 'page' => $to, 'limit' => $limit]);

        return $this->json(['@context' => JsonLd::CONTEXT, '@type' => self::KINDS[$kind][1], 'openric:total' => $all->count(), 'openric:page' => $page,
            'openric:limit' => $limit, 'openric:next' => $page * $limit < $all->count() ? $link($page + 1) : null, 'openric:prev' => $page > 1 ? $link($page - 1) : null,
            'openric:items' => $all->slice(($page - 1) * $limit, $limit)->map($item)->values()->all()]);
    }

    /**
     * Every entity of a kind the public may read, in name order; with a query, those whose name or identifier contains it
     * (or, for autocomplete, whose name starts with it).
     * ponytail: visibility is decided entity by entity in PHP; past some tens of thousands of entities it wants deciding in SQL.
     */
    private function visible(Request $request, string $kind, string $query = '', bool $prefix = false): Collection
    {
        [$class] = self::KINDS[$kind];
        $table = (new $class)->getTable();
        $like = ($prefix ? '' : '%').addcslashes($query, '%_\\').'%';
        $published = Name::where('subject_table', $table)->whereHas('state', fn ($q) => $q->where('iri', Vocab::valueState('published')))
            ->whereHas('nameType', fn ($q) => $q->where('iri', Vocab::NAME_TYPES.'authorised'));
        $view = $this->view($request);

        return $class::where('institution_id', $this->institution($request)->id)->whereNull('deleted_at')
            ->whereIn('id', (clone $published)->select('subject_id'))
            ->when($query !== '', fn (Builder $q) => $q->where(fn ($q) => $q
                ->whereIn('id', (clone $published)->where('value', 'ilike', $like)->select('subject_id'))
                ->when(! $prefix, fn ($q) => $q->orWhereIn('id', DB::table('identifiers')->where('subject_table', $table)->where('value', 'ilike', $like)->select('subject_id')))))
            ->with(self::WITH)->get()
            ->filter(fn ($entity) => $view->readable($entity))
            ->sortBy(fn ($entity) => mb_strtolower((string) $view->label($entity)))->values();
    }

    private function find(Request $request, string $kind, string $key, array $with): mixed
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $key)) {
            return null;
        }
        [$class] = self::KINDS[$kind];
        $entity = $class::where('institution_id', $this->institution($request)->id)->whereNull('deleted_at')->with([...self::WITH, ...$with])->find($key);

        return $entity && $this->view($request)->readable($entity) ? $entity : null;
    }

    private function missing(Request $request, string $what, string $key): JsonResponse
    {
        return Problem::response($request, 404, "$what with key '$key' does not exist.");
    }

    /** A whole-number query parameter within bounds, its default when absent, or null when it is not one. */
    private function bounded(Request $request, string $name, int $default, int $max): ?int
    {
        $value = $request->query($name);
        if ($value === null || $value === '') {
            return $default;
        }

        return ctype_digit((string) $value) && (int) $value >= 1 && (int) $value <= $max ? (int) $value : null;
    }

    private function institution(Request $request): Institution
    {
        return $request->attributes->get('institution');
    }

    private function view(Request $request): ExportView
    {
        return $request->attributes->get('view') ?? tap(new ExportView(null, false, null, $this->institution($request)), fn ($v) => $request->attributes->set('view', $v));
    }

    private function jsonLd(Request $request): JsonLd
    {
        return new JsonLd($this->view($request));
    }

    private function json(array $body): JsonResponse
    {
        return response()->json($body, 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
