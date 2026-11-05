<?php

/*
 * OpenRiC Workspace - heritage at risk and displaced heritage in the public catalogue and to federation peers (roadmap
 * phase 17d3), copied from Heratio, packages/ahg-semantic-search/src/Controllers/EndangeredHeritageController.php
 * (register, global), EndangeredHeritageDashboardController.php (index, json), DisplacedHeritageRegisterController.php
 * (index, show) and packages/ahg-api/src/Controllers/EndangeredApiController.php (index, inbound) at commit
 * bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md). Changed: the register and the network board
 * where the institution shows them; the displaced-heritage register where it shows its register of repatriation
 * claims, a traced description leading to lodging a claim where lodging is allowed; the register answers peers, signed,
 * and takes their pushes, only where the institution allows, at /federation/endangered and at Heratio's
 * /api/v1/endangered; the namespace and code style.
 * Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Http\Controllers;

use App\Api\PublicCatalogue;
use App\Federation\Trust;
use App\Heritage\Displaced;
use App\Heritage\Endangered;
use App\Heritage\EndangeredNetwork;
use App\Models\Concept;
use App\Models\EndangeredSetting;
use App\Models\RepatriationSetting;
use App\Support\Vocab;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class PublicHeritageController extends Controller
{
    public function __construct(private Endangered $endangered, private EndangeredNetwork $network, private Displaced $displaced, private Trust $trust) {}

    /** The register at a glance and the published flags, perhaps of one risk. */
    public function atRisk(Request $request): View
    {
        $catalogue = $this->shown($request);
        $risk = Concept::where('iri', Endangered::RISKS.$request->query('risk'))->exists() ? $request->query('risk') : null;

        return view('discover.heritage.at-risk', ['view' => $catalogue->view, 'dashboard' => $this->endangered->dashboard($catalogue),
            'items' => $this->endangered->publicRegister($catalogue, $risk), 'risk' => $risk, 'risks' => Concept::choices(Endangered::RISKS)->get(), 'disclaimer' => Endangered::DISCLAIMER]);
    }

    public function json(Request $request): JsonResponse
    {
        $catalogue = $this->shown($request);
        $d = $this->endangered->dashboard($catalogue);
        $languages = Vocab::languages();

        return response()->json([...collect($d)->except(['by_risk', 'by_urgency', 'priority'])->all(),
            'by_risk' => array_map(fn ($r) => ['risk' => EndangeredNetwork::wire($r['concept']->notation), 'label' => $r['concept']->label($languages), 'count' => $r['count']], $d['by_risk']),
            'by_urgency' => array_map(fn ($r) => ['urgency' => EndangeredNetwork::wire($r['concept']->notation), 'label' => $r['concept']->label($languages), 'count' => $r['count']], $d['by_urgency']),
            'priority' => $d['priority']->map(fn ($i) => $this->network->row($i, $catalogue))->all(), 'disclaimer' => Endangered::DISCLAIMER]);
    }

    /** The network board: this register, its peers' and the pushes accepted. */
    public function network(Request $request): View
    {
        $catalogue = $this->shown($request);

        return view('discover.heritage.network', ['view' => $catalogue->view, 'board' => $this->network->board($catalogue->institution), 'disclaimer' => Endangered::DISCLAIMER]);
    }

    public function displaced(Request $request): View
    {
        $catalogue = $this->claimsShown($request);
        $report = $this->displaced->scan($catalogue->institution, $catalogue, 0);
        $origin = trim((string) $request->query('origin'));

        return view('discover.heritage.displaced', ['view' => $catalogue->view, 'report' => $report, 'origin' => $origin,
            'records' => $origin === '' ? $report['records'] : array_values(array_filter($report['records'], fn ($r) => strcasecmp($r['origin_region'], $origin) === 0)),
            'lodging' => RepatriationSetting::for($catalogue->institution->id)->public_lodging]);
    }

    public function displacedOne(Request $request, string $id): View
    {
        $catalogue = $this->claimsShown($request);
        $unit = $catalogue->find('records', $id) ?? abort(404);

        return view('discover.heritage.displaced-one', ['view' => $catalogue->view, 'unit' => $unit, 'entry' => $this->displaced->find($unit) ?? abort(404),
            'disclaimer' => Displaced::DISCLAIMER, 'lodging' => RepatriationSetting::for($catalogue->institution->id)->public_lodging]);
    }

    /** The published register as a peer reads it, signed; nothing where the institution does not share it. */
    public function peerRegister(Request $request): Response
    {
        $catalogue = $request->attributes->get('catalogue');
        if (! EndangeredSetting::for($catalogue->institution->id)->share_with_peers) {
            return response()->json(['error' => 'This institution does not share its register of heritage at risk.'], 403);
        }
        $response = response()->json($this->network->answer($catalogue->institution, $request->only(['risk', 'urgency', 'status']), (int) $request->query('limit', 200)),
            200, ['Cache-Control' => 'public, max-age=300', 'Access-Control-Allow-Origin' => '*'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $this->trust->sign($response);
    }

    /** A flag a peer pushes, for a curator to review. */
    public function inbound(Request $request): JsonResponse
    {
        $catalogue = $request->attributes->get('catalogue');
        $outcome = $this->network->ingest($catalogue->institution, (string) ($request->header('X-Federation-Peer') ?: $request->input('peer_base_url', '')),
            (string) $request->getContent(), $request->headers->all());
        $status = $outcome['status'];
        unset($outcome['status']);

        return response()->json($outcome, $status);
    }

    private function shown(Request $request): PublicCatalogue
    {
        $catalogue = $request->attributes->get('catalogue');
        abort_unless(EndangeredSetting::for($catalogue->institution->id)->public_register, 404);

        return $catalogue;
    }

    private function claimsShown(Request $request): PublicCatalogue
    {
        $catalogue = $request->attributes->get('catalogue');
        abort_unless(RepatriationSetting::for($catalogue->institution->id)->public_register, 404);

        return $catalogue;
    }
}
