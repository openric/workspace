<?php

// OpenRiC Workspace - proposals through the API (ADR 0007): POST /records/{key}/proposals and GET /proposals,
// the contract of Heratio's ProposalController (ported in App\Proposals\ProposalService).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers\Api;

use App\Api\Problem;
use App\Api\PublicCatalogue;
use App\Http\Controllers\Controller;
use App\Models\RecordResource;
use App\Proposals\ProposalException;
use App\Proposals\ProposalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProposalController extends Controller
{
    public function __construct(private ProposalService $proposals) {}

    /**
     * Propose names, places, dates, values, transcripts and relationships for a record. Needs a
     * key with the write scope (route middleware), of an account that may update the record.
     * A record the public may not see is found only by an account that may see drafts of it.
     */
    public function store(Request $request, string $key): JsonResponse
    {
        $user = $request->attributes->get('api_user');
        $institution = $request->attributes->get('institution');
        $record = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $key)
            ? RecordResource::where('institution_id', $institution->id)->whereNull('deleted_at')->find($key) : null;
        if ($record === null || (! (new PublicCatalogue($institution))->find('records', $key) && ! $user->may('view-draft', 'description', $record))) {
            return Problem::response($request, 404, "Record with key '$key' does not exist.");
        }
        if (! $user->may('update', 'description', $record)) {
            return Problem::response($request, 403, 'This account may not change this record, so it may not propose changes to it.');
        }
        try {
            $result = $this->proposals->propose($record, $user, $request->attributes->get('api_key'), $request->json()->all());
        } catch (ProposalException $e) {
            return Problem::response($request, 400, $e->getMessage());
        }

        return response()->json($result['body'], $result['created'] ? 201 : 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** GET /proposals?mine=1|0&status=&count=&start= : the caller's proposals, or (mine=0) everyone's, for those who review them. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->attributes->get('api_user');
        $mine = (string) $request->query('mine', '1') !== '0';
        if (! $mine && ! $user->mayAnywhere('review-proposals', 'description')) {
            return Problem::response($request, 403, "Only those who review proposals may list everyone's proposals.");
        }
        $count = max(1, min((int) $request->query('count', 50), ProposalService::LIST_MAX));
        $start = max(0, (int) $request->query('start', 0));
        $list = $this->proposals->list($request->attributes->get('institution'), $mine ? $user : null,
            strtolower(trim((string) $request->query('status', ''))), $count, $start);

        return response()->json(['total' => $list['total'], 'count' => $count, 'start' => $start, 'results' => $list['results']],
            200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
