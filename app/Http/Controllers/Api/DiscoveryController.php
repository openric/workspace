<?php

// OpenRiC Workspace - the OpenRiC read API, Core Discovery profile 0.7.0 (spec 0.56.0).
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
use App\Api\PublicCatalogue;
use App\Api\Rdf;
use App\Description\DeprecationService;
use App\Description\InstantiationService;
use App\Exchange\ExportView;
use App\Http\Controllers\Controller;
use App\Iiif\Presentation;
use App\Models\DigitalFile;
use App\Models\Instantiation;
use App\Models\Institution;
use App\Rights\PublicRights;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class DiscoveryController extends Controller
{
    /** The profiles this service declares, with the versions it implements. */
    public const PROFILES = [['id' => 'core-discovery', 'version' => '0.7.0', 'conformance' => 'full'], ['id' => 'export-only', 'version' => '0.10.0', 'conformance' => 'full'],
        ['id' => 'portability', 'version' => '0.43.0', 'conformance' => 'full'], ['id' => 'governance', 'version' => '0.45.0', 'conformance' => 'full']];

    /** The list type of each list. */
    private const LISTS = ['records' => 'openricx:RecordList', 'agents' => 'openricx:AgentList', 'repositories' => 'openricx:RepositoryList'];

    /** What a record needs loaded for its JSON-LD. */
    public const RECORD_WITH = ['level.labels', 'repository.names.nameType', 'repository.names.state', 'parent.names.state', 'children.names.state'];

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
            'properties' => $properties]);
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

    public function record(Request $request, string $key): JsonResponse|Response
    {
        $unit = $this->catalogue($request)->find('records', $key, self::RECORD_WITH);

        return $unit ? $this->json($this->jsonLd($request)->record($unit)) : $this->missing($request, 'Record', $key);
    }

    /**
     * A record's files and their text, as a visitor may have them (ADR 0007): its instantiations the public
     * catalogue shows, each with its published title and transcript and the copies a visitor may open
     * (reference and thumbnail; never a master), at their addresses in the public catalogue. Plain JSON, not
     * JSON-LD: the Core Discovery profile does not describe instantiations.
     */
    public function files(Request $request, string $key): JsonResponse|Response
    {
        $unit = $this->catalogue($request)->find('records', $key);
        if ($unit === null) {
            return $this->missing($request, 'Record', $key);
        }
        $view = $this->view($request);
        $service = app(InstantiationService::class);
        $instantiations = Instantiation::where('record_resource_id', $unit->id)->whereNull('deleted_at')
            ->with([...PublicCatalogue::WITH, 'availability', 'files.use', 'files.instantiation.record', 'files.instantiation.availability'])
            ->orderBy('position')->orderBy('id')->get();
        $public = new PublicRights($view);
        $items = [];
        foreach ($instantiations as $instantiation) {
            // Shown as the public catalogue shows it: with a published title. Its text is the file's content, so only of a file a visitor may open.
            $title = $view->label($instantiation);
            if ($title === null) {
                continue;
            }
            $files = $instantiation->files->filter(fn (DigitalFile $file) => $service->deliverable(null, $file))->sortBy(fn ($file) => $file->use->notation);
            $text = $instantiation->availability->notation !== 'online' ? [] : $view->values($instantiation, 'instantiation-transcript')->map(fn ($v) => ['language' => $v->language, 'value' => $v->value])->values()->all();
            if ($files->isEmpty() && $text === []) {
                continue; // nothing a visitor may have of it
            }
            $items[] = [
                'id' => $instantiation->id, 'title' => $title,
                'files' => $files->map(fn (DigitalFile $file) => ['id' => $file->id, 'use' => $file->use->notation, 'mime_type' => $file->mime_type,
                    'width' => $file->width, 'height' => $file->height, 'size_bytes' => $file->size_bytes,
                    'path' => parse_url(route('discover.file', $file), PHP_URL_PATH), 'url' => route('discover.file', $file)])->values()->all(),
                'text' => $text,
                'rights' => $this->rightsOf($public, $instantiation),
            ];
        }

        $manifest = (new Presentation($this->catalogue($request)))->hasManifest($unit) ? route('iiif.manifest', $unit->id) : null;

        return $this->json(['record' => $unit->id, 'title' => $view->label($unit), 'rights' => $this->rightsOf($public, $unit), 'iiif_manifest' => $manifest, 'instantiations' => $items]);
    }

    /**
     * Rights in force and Local Contexts Labels, as plain JSON for the files API (ADR 0008): statements, licences and
     * Labels by their publishers' IRIs; for each PREMIS act whether it is allowed.
     *
     * @return array{rules: list<array<string, mixed>>, labels: list<array<string, mixed>>}
     */
    private function rightsOf(PublicRights $public, Model $subject): array
    {
        $concept = fn ($c) => $c ? ['iri' => $c->iri, 'code' => $c->notation, 'label' => (string) $c->label(['en', 'und'])] : null;

        return [
            'rules' => $public->rights($subject)->map(fn ($right) => [
                'iri' => $right->iri, 'basis' => $right->basis->notation, 'statement' => $concept($right->statement), 'licence' => $concept($right->licence),
                'copyright_status' => $right->copyrightStatus?->notation, 'jurisdiction' => $right->jurisdiction,
                'starts_on' => $right->starts_on?->toDateString(), 'ends_on' => $right->ends_on?->toDateString(),
                'holder' => $right->holder ? ['iri' => $right->holder->iri, 'name' => $this->view(request())->label($right->holder)] : null,
                'grants' => $right->grants->mapWithKeys(fn ($g) => [$g->act->notation => ['restriction' => $g->restriction->notation, 'note' => $g->note]])->all(),
            ])->values()->all(),
            'labels' => $public->labels($subject)->map(fn ($l) => $concept($l->label) + ['applied_by' => $l->appliedBy->notation])->values()->all(),
        ];
    }

    public function agent(Request $request, string $key): JsonResponse|Response
    {
        $agent = $this->catalogue($request)->find('agents', $key, ['agentType.labels']);

        return $agent ? $this->json($this->jsonLd($request)->agent($agent)) : $this->missing($request, 'Agent', $key);
    }

    public function repository(Request $request, string $key): JsonResponse|Response
    {
        $repository = $this->catalogue($request)->find('repositories', $key, ['contactPoints']);

        return $repository ? $this->json($this->jsonLd($request)->repository($repository)) : $this->missing($request, 'Repository', $key);
    }

    /**
     * One record with the authorities it reaches in one hop, as a download (Export-Only 2.4): JSON-LD with a @graph,
     * or the same triples in Turtle or RDF/XML. ?format= wins over Accept.
     */
    public function export(Request $request, string $key): Response|JsonResponse
    {
        $format = match (mb_strtolower((string) $request->query('format', ''))) {
            '' => null, 'jsonld', 'json-ld', 'json' => 'jsonld', 'ttl', 'turtle' => 'turtle', 'rdf', 'rdfxml', 'rdf+xml', 'xml' => 'rdfxml', default => false,
        };
        if ($format === false) {
            return Problem::response($request, 400, 'format is jsonld, ttl or rdf.');
        }
        $accept = (string) $request->header('Accept', '');
        $format ??= match (true) {
            str_contains($accept, 'text/turtle') => 'turtle',
            str_contains($accept, 'application/rdf+xml') => 'rdfxml',
            $accept === '' || str_contains($accept, 'json') || str_contains($accept, '*/*') => 'jsonld',
            default => null,
        };
        if ($format === null) {
            return Problem::response($request, 406, 'This export is given as application/ld+json, text/turtle or application/rdf+xml.');
        }
        $unit = $this->catalogue($request)->find('records', $key, self::RECORD_WITH);
        if ($unit === null) {
            return $this->missing($request, 'Record', $key, $format);
        }
        $dump = $this->jsonLd($request)->dump($unit);
        [$body, $type, $extension] = match ($format) {
            'jsonld' => [json_encode($dump, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 'application/ld+json', 'jsonld'],
            'turtle' => [Rdf::serialise($dump, 'turtle'), 'text/turtle; charset=utf-8', 'ttl'],
            'rdfxml' => [Rdf::serialise($dump, 'rdfxml'), 'application/rdf+xml; charset=utf-8', 'rdf'],
        };

        return response($body, 200, ['Content-Type' => $type, 'Content-Disposition' => 'attachment; filename="'.$unit->id.'-ric.'.$extension.'"']);
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
            foreach ($this->catalogue($request)->visible($kind, $query, true)->take($limit) as $entity) {
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
        $all = $this->catalogue($request)->visible($kind, trim((string) $request->query('q', '')));
        $link = fn (int $to) => $request->url().'?'.http_build_query([...$request->query(), 'page' => $to, 'limit' => $limit]);

        return $this->json(['@context' => JsonLd::CONTEXT, '@type' => self::LISTS[$kind], 'openric:total' => $all->count(), 'openric:page' => $page,
            'openric:limit' => $limit, 'openric:next' => $page * $limit < $all->count() ? $link($page + 1) : null, 'openric:prev' => $page > 1 ? $link($page - 1) : null,
            'openric:items' => $all->slice(($page - 1) * $limit, $limit)->map($item)->values()->all()]);
    }

    /**
     * Not here: 410 Gone with the deprecated entity, when the public could read it before it was deleted or merged, in
     * the format asked for (Governance profile 4, ADR 0013); else 404.
     */
    private function missing(Request $request, string $what, string $key, string $format = 'jsonld'): JsonResponse|Response
    {
        $table = ['Record' => 'record_resources', 'Agent' => 'agents', 'Repository' => 'repositories'][$what];
        $deprecation = preg_match('/^[0-9a-f-]{36}$/', $key) ? app(DeprecationService::class)->known($this->institution($request), $table, $key) : null;
        if ($deprecation === null) {
            return Problem::response($request, 404, "$what with key '$key' does not exist.");
        }
        $body = app(DeprecationService::class)->jsonLd($deprecation);

        return match ($format) {
            'turtle' => response(Rdf::serialise($body, 'turtle'), 410, ['Content-Type' => 'text/turtle; charset=utf-8']),
            'rdfxml' => response(Rdf::serialise($body, 'rdfxml'), 410, ['Content-Type' => 'application/rdf+xml; charset=utf-8']),
            default => response()->json($body, 410, ['Content-Type' => 'application/ld+json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
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

    private function catalogue(Request $request): PublicCatalogue
    {
        return $request->attributes->get('catalogue') ?? tap(new PublicCatalogue($this->institution($request)), fn ($c) => $request->attributes->set('catalogue', $c));
    }

    private function view(Request $request): ExportView
    {
        return $this->catalogue($request)->view;
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
