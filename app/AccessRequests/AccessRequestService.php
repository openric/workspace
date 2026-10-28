<?php

/**
 * AccessRequestService - Service for Heratio
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * This file is part of Heratio.
 *
 * Heratio is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * OpenRiC Workspace - ported from Heratio, packages/ahg-access-request/src/Services/AccessRequestService.php
 * at commit 77a0cf787 (governance/CODE_PROVENANCE.md; PSIS ahgAccessRequestPlugin): createRequest,
 * approveRequest, denyRequest, cancelRequest, the pending, my and all requests, and the four mails
 * through trySend (here Outbox, behind the suppression gate). Changed: a request is for the files of
 * one instantiation available on request, as Heratio's object request with a single scope row; the
 * security-clearance request types and classifications wait for phase 18; status and urgency are
 * concepts; approving sets until when the requester may open the files, and a request whose time is
 * over is expired (expire()), where Heratio keeps expires_at unused; the approvers are the accounts
 * that may review access requests for the record, not a table; every change is audited through the
 * model, replacing access_request_log; requests belong to an institution. The approvers screen and
 * the mail switch are not copied: roles grant the permission, and mail is always sent. The general
 * request (Heratio's store from new.blade.php: subject, request type, scope of everything, a collection
 * or an item) is createGeneralRequest; once approved, it opens the files available on request of what
 * it covers, which in Heratio waits on the security clearance. The access type of the object form,
 * which Heratio asks for but does not keep, is kept.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\AccessRequests;

use App\Access\AccessControl;
use App\Audit\AuditLog;
use App\Mail\AccessRequestApprovedMail;
use App\Mail\AccessRequestDeniedMail;
use App\Mail\AccessRequestPendingMail;
use App\Mail\AccessRequestSubmittedMail;
use App\Mail\Outbox;
use App\Models\AccessRequest;
use App\Models\Concept;
use App\Models\Instantiation;
use App\Models\RecordResource;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AccessRequestService
{
    public const STATUSES = Vocab::BASE.'access-request-status/';

    public const URGENCIES = Vocab::BASE.'access-request-urgency/';

    public const SCOPES = Vocab::BASE.'access-request-scope/';

    public const TYPES = Vocab::BASE.'access-request-type/';

    public const ACCESS_TYPES = Vocab::BASE.'access-type/';

    /** The levels a collection may be asked for at, as Heratio's COLLECTION_LEVELS. */
    public const COLLECTION_LEVELS = ['fonds', 'collection', 'record-group', 'subfonds', 'series', 'subseries'];

    /** Days of access an approval gives unless the reviewer says otherwise, and the most it may give. */
    public const DEFAULT_DAYS = 30;

    public const MAX_DAYS = 365;

    public function __construct(private Outbox $outbox, private AccessControl $access) {}

    /** May the account ask for this instantiation's files? It must see the record, and the files must be on request. */
    public function mayRequest(User $user, Instantiation $instantiation): bool
    {
        $unit = $instantiation->record;

        return $instantiation->institution_id === $user->institution_id && $instantiation->deleted_at === null && $unit->deleted_at === null
            && $instantiation->availability->notation === 'on-request' && $user->may('read', 'description', $unit);
    }

    /** The account's request for the instantiation that is waiting or still gives access, if any. */
    public function open(User $user, Instantiation $instantiation): ?AccessRequest
    {
        return AccessRequest::where(['user_id' => $user->id, 'instantiation_id' => $instantiation->id])->with('status')->latest()->get()
            ->first(fn (AccessRequest $r) => $r->hasStatus('pending') || $r->grantsAccess());
    }

    /**
     * Does an approved request of the account, whose time is not over, open the instantiation's files? One for them, for
     * their record, for a collection above it, or for everything does, while the account may still see the record.
     */
    public function grants(User $user, Instantiation $instantiation): bool
    {
        $unit = $instantiation->record;
        $above = array_map(fn ($r) => $r->id, $unit->ancestors());

        return $user->may('read', 'description', $unit) && AccessRequest::where(['user_id' => $user->id, 'status_id' => $this->concept(self::STATUSES, 'approved')])
            ->where('expires_at', '>', now())
            ->where(fn ($q) => $q->where('instantiation_id', $instantiation->id)->orWhere(fn ($q) => $q->whereNull('instantiation_id')
                ->where(fn ($q) => $q->whereNull('record_resource_id')->orWhere('record_resource_id', $unit->id)
                    ->orWhere(fn ($q) => $q->where('scope_id', $this->concept(self::SCOPES, 'collection'))->whereIn('record_resource_id', $above)))))
            ->exists();
    }

    /** May the account ask for a collection or an item? It must see the record; a collection must be at a collection level. */
    public function mayAsk(User $user, string $scope, ?RecordResource $record): bool
    {
        return match ($scope) {
            'all' => $record === null,
            'item' => $record !== null && $this->sees($user, $record),
            'collection' => $record !== null && $this->sees($user, $record) && in_array($record->level?->notation, self::COLLECTION_LEVELS, true),
            default => false,
        };
    }

    public function createRequest(User $user, Instantiation $instantiation, array $data): AccessRequest
    {
        if (! $this->mayRequest($user, $instantiation)) {
            throw new InvalidArgumentException(__('Only the files of an instantiation available on request can be asked for.'));
        }
        if ($this->open($user, $instantiation)) {
            throw new InvalidArgumentException(__('You have asked for these files already.'));
        }
        $request = DB::transaction(fn () => AuditLog::actingAs($user, fn () => AccessRequest::create([
            'institution_id' => $user->institution_id, 'user_id' => $user->id, 'instantiation_id' => $instantiation->id,
            'record_resource_id' => $instantiation->record_resource_id, 'scope_id' => $this->concept(self::SCOPES, 'item'),
            'access_type_id' => $this->concept(self::ACCESS_TYPES, $data['access_type'] ?? 'view'),
            'reason' => trim((string) $data['reason']), 'justification' => trim((string) ($data['justification'] ?? '')) ?: null,
            'urgency_id' => $this->concept(self::URGENCIES, $data['urgency'] ?? 'normal'), 'status_id' => $this->concept(self::STATUSES, 'pending'),
        ])));
        $this->announce($request);

        return $request;
    }

    /**
     * Heratio's general request: a subject, what is asked for, and everything, a collection or an item; the description is
     * the reason, as in Heratio.
     *
     * @param  array{subject: string, request_type: string, description: string, justification?: ?string, urgency?: ?string, scope: string}  $data
     */
    public function createGeneralRequest(User $user, array $data, ?RecordResource $record): AccessRequest
    {
        if (! $this->mayAsk($user, $data['scope'], $record)) {
            throw new InvalidArgumentException($data['scope'] === 'collection' ? __('Choose which collection you need access to.') : __('Choose which item you need access to.'));
        }
        $scope = $this->concept(self::SCOPES, $data['scope']);
        $asked = AccessRequest::where(['user_id' => $user->id, 'scope_id' => $scope, 'record_resource_id' => $record?->id])->whereNull('instantiation_id')->with('status')->get();
        if ($asked->contains(fn (AccessRequest $r) => $r->hasStatus('pending') || $r->grantsAccess())) {
            throw new InvalidArgumentException(__('You have asked for this already.'));
        }
        $request = DB::transaction(fn () => AuditLog::actingAs($user, fn () => AccessRequest::create([
            'institution_id' => $user->institution_id, 'user_id' => $user->id, 'record_resource_id' => $record?->id, 'scope_id' => $scope,
            'request_type_id' => $this->concept(self::TYPES, $data['request_type']), 'subject' => trim($data['subject']),
            'reason' => trim($data['description']), 'justification' => trim((string) ($data['justification'] ?? '')) ?: null,
            'urgency_id' => $this->concept(self::URGENCIES, $data['urgency'] ?? 'normal'), 'status_id' => $this->concept(self::STATUSES, 'pending'),
        ])));
        $this->announce($request);

        return $request;
    }

    /** The collections an account may ask for, by title. @return Collection<int, RecordResource> */
    public function collections(User $user): Collection
    {
        return RecordResource::where('institution_id', $user->institution_id)->whereNull('deleted_at')
            ->whereHas('level', fn ($q) => $q->whereIn('notation', self::COLLECTION_LEVELS))->with('level.labels')->get()
            ->filter(fn (RecordResource $r) => $user->may('read', 'description', $r))
            ->sortBy(fn (RecordResource $r) => mb_strtolower((string) $r->described('title', [app()->getLocale(), 'en'])))->values();
    }

    /** Tell the requester (acknowledgement) and everyone who may review it (review queue heads-up). */
    private function announce(AccessRequest $request): void
    {
        $this->outbox->send($request->user, new AccessRequestSubmittedMail($request->user, $request));
        foreach ($this->reviewersFor($request) as $reviewer) {
            if ($reviewer->id !== $request->user_id) {
                $this->outbox->send($reviewer, new AccessRequestPendingMail($reviewer, $request));
            }
        }
    }

    private function sees(User $user, RecordResource $record): bool
    {
        return $record->institution_id === $user->institution_id && $record->deleted_at === null && $user->may('read', 'description', $record);
    }

    public function approveRequest(AccessRequest $request, User $reviewer, int $days = self::DEFAULT_DAYS, ?string $notes = null): void
    {
        $this->reviewable($request, $reviewer);
        if ($days < 1 || $days > self::MAX_DAYS) {
            throw new InvalidArgumentException(__('Give access for 1 to :max days.', ['max' => self::MAX_DAYS]));
        }
        $this->review($request, $reviewer, 'approved', $notes, now()->addDays($days));
        $this->outbox->send($request->user, new AccessRequestApprovedMail($request->user, $request->fresh()));
    }

    public function denyRequest(AccessRequest $request, User $reviewer, ?string $reason = null): void
    {
        $this->reviewable($request, $reviewer);
        if (trim((string) $reason) === '') {
            throw new InvalidArgumentException(__('Say why the request is refused: the requester is told.'));
        }
        $this->review($request, $reviewer, 'denied', $reason, null);
        $this->outbox->send($request->user, new AccessRequestDeniedMail($request->user, $request->fresh()));
    }

    /** The requester withdraws a request that is waiting or gives access; access given ends now. */
    public function cancelRequest(AccessRequest $request, User $user): bool
    {
        if ($request->user_id !== $user->id || ! ($request->hasStatus('pending') || $request->hasStatus('approved'))) {
            return false;
        }
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $request->update(['status_id' => $this->concept(self::STATUSES, 'cancelled'),
            'expires_at' => $request->expires_at?->isFuture() ? now() : $request->expires_at])));

        return true;
    }

    /** Approved requests whose time is over become expired. The number expired. */
    public function expire(): int
    {
        $due = AccessRequest::where('status_id', $this->concept(self::STATUSES, 'approved'))->where('expires_at', '<=', now())->get();
        AuditLog::actingAs(null, fn () => $due->each->update(['status_id' => $this->concept(self::STATUSES, 'expired')]));

        return $due->count();
    }

    /**
     * The institution's requests a reviewer may review, newest first: those waiting (pending), or all.
     *
     * @param  array{status?: ?string}  $filters
     */
    public function forReviewer(User $reviewer, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $ids = AccessRequest::where('institution_id', $reviewer->institution_id)->with('record')->get(['id', 'institution_id', 'record_resource_id'])
            ->filter(fn (AccessRequest $r) => $this->mayReview($reviewer, $r))->modelKeys();

        return AccessRequest::whereKey($ids)
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status_id', $this->concept(self::STATUSES, $s)))
            ->with('user', 'status.labels', 'urgency.labels', 'instantiation', 'record', 'reviewer')->latest()->latest('id')->paginate($perPage)->withQueryString();
    }

    public function getMyRequests(User $user, int $perPage = 25): LengthAwarePaginator
    {
        return AccessRequest::where('user_id', $user->id)->with('status.labels', 'urgency.labels', 'instantiation', 'record', 'reviewer')
            ->latest()->latest('id')->paginate($perPage);
    }

    /** @return Collection<int, User> the institution's active accounts that may review the request */
    public function reviewersFor(AccessRequest $request): Collection
    {
        return User::where('institution_id', $request->institution_id)->whereNull('disabled_at')->get()
            ->filter(fn (User $u) => $this->mayReview($u, $request))->values();
    }

    /** Who may review requests for the record asked for; for everything, who may review them across the institution. */
    public function mayReview(User $reviewer, AccessRequest $request): bool
    {
        return $request->institution_id === $reviewer->institution_id
            && $this->access->allows($reviewer, 'review-access-requests', 'description', $request->record);
    }

    private function reviewable(AccessRequest $request, User $reviewer): void
    {
        if (! $this->mayReview($reviewer, $request)) {
            throw new InvalidArgumentException(__('You may not review requests for this record.'));
        }
        if (! $request->hasStatus('pending')) {
            throw new InvalidArgumentException(__('This request was answered already.'));
        }
        if ($request->user_id === $reviewer->id) {
            throw new InvalidArgumentException(__('A request is reviewed by someone other than its requester.'));
        }
    }

    private function review(AccessRequest $request, User $reviewer, string $status, ?string $notes, $expiresAt): void
    {
        DB::transaction(fn () => AuditLog::actingAs($reviewer, fn () => $request->update([
            'status_id' => $this->concept(self::STATUSES, $status), 'reviewed_by_id' => $reviewer->id, 'reviewed_at' => now(),
            'review_notes' => trim((string) $notes) ?: null, 'expires_at' => $expiresAt,
        ])));
        $request->unsetRelation('status');
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', $vocabulary.$notation)->whereNull('deprecated_at')->value('id')
            ?? throw new InvalidArgumentException("$notation is not a current concept of $vocabulary");
    }
}
