<?php

/**
 * AccessRequestController - Heratio
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * OpenRiC Workspace - ported from Heratio, packages/ahg-access-request/src/Controllers/AccessRequestController.php
 * at commit 77a0cf787 (governance/CODE_PROVENANCE.md; PSIS ahgAccessRequestPlugin): requestObject and
 * storeObjectRequest (asking for a file), myRequests, view, cancel, pending and browse (one queue with a
 * status filter), approve and deny. Changed: the route names a Workspace instantiation; the reviewer
 * gives the number of days of access; who may review is the review-access-requests permission on the
 * record, not the admin flag; the legacy /security/* routes, the clearance request and the approvers
 * screen are not copied (phase 18, and roles grant the permission). The general request (new and store)
 * is newRequest and storeGeneral; its item is chosen from the record's page, where Heratio searches for it.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Http\Controllers;

use App\AccessRequests\AccessRequestService;
use App\Models\AccessRequest;
use App\Models\Concept;
use App\Models\Instantiation;
use App\Models\RecordResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class AccessRequestController extends Controller
{
    public function __construct(private AccessRequestService $requests) {}

    public function create(Request $request, Instantiation $instantiation): View|RedirectResponse
    {
        abort_unless($this->requests->mayRequest($request->user(), $instantiation), 404);
        if ($open = $this->requests->open($request->user(), $instantiation)) {
            return redirect()->route('access-requests.show', $open);
        }

        return view('access-requests.create', ['instantiation' => $instantiation->load('record'),
            'urgencies' => Concept::choices(AccessRequestService::URGENCIES)->with('labels')->get(),
            'accessTypes' => Concept::choices(AccessRequestService::ACCESS_TYPES)->with('labels')->get()]);
    }

    /** Heratio's new request: everything, a collection, or the item whose page it was opened from. */
    public function newRequest(Request $request): View
    {
        $user = $request->user();
        $record = $request->query('record') ? RecordResource::find($request->query('record')) : null;
        $item = $record && $this->requests->mayAsk($user, 'item', $record) ? $record : null;

        return view('access-requests.new', ['item' => $item, 'collections' => $this->requests->collections($user),
            'scope' => old('scope', $request->query('scope', $item ? 'item' : 'all')), 'chosen' => old('collection', $record?->id),
            'types' => Concept::choices(AccessRequestService::TYPES)->with('labels')->get(),
            'urgencies' => Concept::choices(AccessRequestService::URGENCIES)->with('labels')->get()]);
    }

    public function storeGeneral(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'request_type' => ['required', 'string', 'in:'.Concept::choices(AccessRequestService::TYPES)->pluck('notation')->implode(',')],
            'description' => ['required', 'string', 'max:4000'],
            'justification' => ['nullable', 'string', 'max:4000'],
            'urgency' => ['required', 'string', 'in:'.Concept::choices(AccessRequestService::URGENCIES)->pluck('notation')->implode(',')],
            'scope' => ['required', 'string', 'in:'.Concept::choices(AccessRequestService::SCOPES)->pluck('notation')->implode(',')],
            'collection' => ['required_if:scope,collection', 'nullable', 'uuid'],
            'item' => ['required_if:scope,item', 'nullable', 'uuid'],
        ], [
            'collection.required_if' => __('Choose which collection you need access to.'),
            'item.required_if' => __('Choose which item you need access to.'),
        ]);
        $id = ['collection' => $data['collection'] ?? null, 'item' => $data['item'] ?? null][$data['scope']] ?? null;
        try {
            $created = $this->requests->createGeneralRequest($request->user(), $data, $id ? RecordResource::find($id) : null);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['scope' => $e->getMessage()]);
        }

        return redirect()->route('access-requests.show', $created)->with('status', __('Your request was sent. You will have an email when it is answered.'));
    }

    public function store(Request $request, Instantiation $instantiation): RedirectResponse
    {
        abort_unless($this->requests->mayRequest($request->user(), $instantiation), 404);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:4000'],
            'justification' => ['nullable', 'string', 'max:4000'],
            'urgency' => ['required', 'string', 'in:'.Concept::choices(AccessRequestService::URGENCIES)->pluck('notation')->implode(',')],
            'access_type' => ['nullable', 'string', 'in:'.Concept::choices(AccessRequestService::ACCESS_TYPES)->pluck('notation')->implode(',')],
        ]);
        try {
            $created = $this->requests->createRequest($request->user(), $instantiation, $data);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('access-requests.show', $created)->with('status', __('Your request was sent. You will have an email when it is answered.'));
    }

    public function mine(Request $request): View
    {
        return view('access-requests.mine', ['requests' => $this->requests->getMyRequests($request->user())]);
    }

    public function index(Request $request): View
    {
        abort_unless($request->user()->mayAnywhere('review-access-requests', 'description'), 403);
        $statuses = Concept::choices(AccessRequestService::STATUSES)->with('labels')->get();
        $status = $request->query('status', 'pending');
        $status = $statuses->pluck('notation')->contains($status) ? $status : null;

        return view('access-requests.index', ['requests' => $this->requests->forReviewer($request->user(), ['status' => $status]),
            'statuses' => $statuses, 'status' => $status]);
    }

    public function show(Request $request, AccessRequest $accessRequest): View
    {
        return view('access-requests.show', ['accessRequest' => $this->visible($request, $accessRequest)->load('user', 'status.labels', 'urgency.labels', 'reviewer', 'instantiation', 'record', 'covers.labels', 'requestType.labels', 'accessType.labels'),
            'mayReview' => $this->requests->mayReview($request->user(), $accessRequest) && $accessRequest->user_id !== $request->user()->id,
            'days' => AccessRequestService::DEFAULT_DAYS, 'maxDays' => AccessRequestService::MAX_DAYS]);
    }

    public function approve(Request $request, AccessRequest $accessRequest): RedirectResponse
    {
        $this->visible($request, $accessRequest);
        $data = $request->validate(['days' => ['required', 'integer', 'between:1,'.AccessRequestService::MAX_DAYS], 'notes' => ['nullable', 'string', 'max:4000']]);

        return $this->answer($accessRequest, fn () => $this->requests->approveRequest($accessRequest, $request->user(), (int) $data['days'], $data['notes'] ?? null),
            __('Approved: the requester may open the files and has been told.'));
    }

    public function deny(Request $request, AccessRequest $accessRequest): RedirectResponse
    {
        $this->visible($request, $accessRequest);
        $data = $request->validate(['notes' => ['required', 'string', 'max:4000']]);

        return $this->answer($accessRequest, fn () => $this->requests->denyRequest($accessRequest, $request->user(), $data['notes']),
            __('Refused: the requester has been told why.'));
    }

    public function cancel(Request $request, AccessRequest $accessRequest): RedirectResponse
    {
        $this->visible($request, $accessRequest);

        return redirect()->route('access-requests.show', $accessRequest)->with('status', $this->requests->cancelRequest($accessRequest, $request->user())
            ? __('Your request is cancelled.') : __('Only a request that is waiting or gives access can be cancelled.'));
    }

    private function answer(AccessRequest $accessRequest, callable $action, string $done): RedirectResponse
    {
        try {
            $action();
        } catch (InvalidArgumentException $e) {
            return redirect()->route('access-requests.show', $accessRequest)->withErrors(['request' => $e->getMessage()]);
        }

        return redirect()->route('access-requests.show', $accessRequest)->with('status', $done);
    }

    /** The requester sees their own; a reviewer, those of the records they may review. Anyone else: not found. */
    private function visible(Request $request, AccessRequest $accessRequest): AccessRequest
    {
        $user = $request->user();
        abort_unless($accessRequest->user_id === $user->id || $this->requests->mayReview($user, $accessRequest), 404);

        return $accessRequest;
    }
}
