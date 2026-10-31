<?php

// OpenRiC Workspace - IIIF for anyone (roadmap phase 14a, ADR 0014): the manifests, collections and curated
// collections of IIIF Presentation 3, Change Discovery 1.0, Content State 1.0, and the viewer page that opens a
// record's manifest in Mirador, after Heratio's IiifCollectionController, IiifChangeDiscoveryController and
// IiifContentStateController. Everything is what the public catalogue shows; a published record that was withdrawn
// answers 410, as its IRI does (ADR 0013).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Api\PublicCatalogue;
use App\Description\DeprecationService;
use App\Iiif\ChangeDiscovery;
use App\Iiif\ContentSearch;
use App\Iiif\ContentState;
use App\Iiif\Presentation;
use App\Models\IiifCollection;
use App\Models\IiifViewerWorkspace;
use App\Models\RecordResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IiifController extends Controller
{
    private const MEDIA_TYPE = 'application/ld+json;profile="http://iiif.io/api/presentation/3/context.json"';

    public function manifest(Request $request, string $id): JsonResponse
    {
        $unit = $this->record($request, $id);

        return $this->json($this->presentation($request)->manifest($unit) ?? abort(404));
    }

    public function collection(Request $request, string $id): JsonResponse
    {
        return $this->json($this->presentation($request)->collection($this->record($request, $id)));
    }

    public function top(Request $request): JsonResponse
    {
        return $this->json($this->presentation($request)->top());
    }

    public function curated(Request $request, IiifCollection $collection): JsonResponse
    {
        abort_unless($collection->is_public && $collection->institution_id === $this->catalogue($request)->institution->id, 404);

        return $this->json($this->presentation($request)->curated($collection));
    }

    /** Content Search 2.0: the hits for ?q= in the record's published transcripts (phase 14b). */
    public function search(Request $request, string $id): JsonResponse
    {
        $unit = $this->record($request, $id);
        $presentation = $this->presentation($request);
        abort_unless($presentation->hasManifest($unit), 404);

        return $this->json(app(ContentSearch::class)->search($presentation, $unit, (string) $request->query('q', '')), 'application/ld+json');
    }

    public function autocomplete(Request $request, string $id): JsonResponse
    {
        $unit = $this->record($request, $id);
        $presentation = $this->presentation($request);
        abort_unless($presentation->hasManifest($unit), 404);

        return $this->json(app(ContentSearch::class)->autocomplete($presentation, $unit, (string) $request->query('q', '')), 'application/ld+json');
    }

    /** Change Discovery: the collection, or one page of it (?page=). */
    public function changes(Request $request): JsonResponse
    {
        $discovery = new ChangeDiscovery($this->catalogue($request));
        $body = $request->has('page') ? ($discovery->page((int) $request->query('page')) ?? abort(404)) : $discovery->collection();

        return response()->json($body, 200, ['Content-Type' => 'application/ld+json;profile="https://www.w3.org/ns/activitystreams"', 'Access-Control-Allow-Origin' => '*'],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** A Content State token for a manifest of this Workspace, a canvas and a region. */
    public function contentState(Request $request): JsonResponse
    {
        $data = $request->validate(['manifest' => ['required', 'url'], 'canvas' => ['nullable', 'url'], 'region' => ['nullable', 'regex:/^xywh=\d+,\d+,\d+,\d+$/']]);
        $token = app(ContentState::class)->encode($data['manifest'], $data['canvas'] ?? null, $data['region'] ?? null);
        app(ContentState::class)->decode($token) ?? abort(422, 'Not a manifest of this Workspace.');

        return response()->json(['iiif-content' => $token], 200, ['Access-Control-Allow-Origin' => '*']);
    }

    /** The record's manifest in Mirador; ?iiif-content= opens it at the canvas a Content State names. */
    public function viewer(Request $request, string $id): View
    {
        $unit = $this->record($request, $id);
        $presentation = $this->presentation($request);
        abort_unless($presentation->hasManifest($unit), 404);
        $state = $request->filled('iiif-content') ? app(ContentState::class)->decode((string) $request->query('iiif-content')) : null;
        $manifest = route('iiif.manifest', $unit->id);
        $windows = [array_filter(['manifestId' => $manifest, 'canvasId' => $state && $state['manifest'] === $manifest ? $state['canvas'] : null])];
        // A layout the account saved opens with all its windows (phase 14b); each is checked again on the way in.
        $user = $request->user();
        if ($user && $request->filled('workspace')) {
            $saved = IiifViewerWorkspace::where(['id' => $request->query('workspace'), 'user_id' => $user->id])->first() ?? abort(404);
            $windows = IiifWorkspaceController::windows($saved->windows) ?: $windows;
        }

        return view('discover.iiif', ['unit' => $unit, 'view' => $this->catalogue($request)->view, 'institution' => $this->catalogue($request)->institution,
            'manifest' => $manifest, 'windows' => $windows, 'signedIn' => $user !== null,
            'default' => $user ? IiifViewerWorkspace::where(['user_id' => $user->id, 'is_default' => true])->first() : null]);
    }

    /** A record the public may read; a withdrawn one answers 410 (ADR 0013). */
    private function record(Request $request, string $id): RecordResource
    {
        $catalogue = $this->catalogue($request);
        $unit = $catalogue->find('records', $id, ['level.labels', 'repository.names.nameType', 'repository.names.state', 'parent']);
        if ($unit === null && preg_match('/^[0-9a-f-]{36}$/', $id) && app(DeprecationService::class)->known($catalogue->institution, 'record_resources', $id)) {
            abort(410);
        }

        return $unit ?? abort(404);
    }

    private function presentation(Request $request): Presentation
    {
        return new Presentation($this->catalogue($request));
    }

    private function catalogue(Request $request): PublicCatalogue
    {
        return $request->attributes->get('catalogue');
    }

    private function json(array $body, string $type = self::MEDIA_TYPE): JsonResponse
    {
        return response()->json($body, 200, ['Content-Type' => $type, 'Access-Control-Allow-Origin' => '*'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
