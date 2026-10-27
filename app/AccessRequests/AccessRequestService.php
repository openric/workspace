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
 * the mail switch are not copied: roles grant the permission, and mail is always sent.
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

    /** Does an approved request of the account, whose time is not over, open the instantiation's files? */
    public function grants(User $user, Instantiation $instantiation): bool
    {
        return AccessRequest::where(['user_id' => $user->id, 'instantiation_id' => $instantiation->id, 'status_id' => $this->concept(self::STATUSES, 'approved')])
            ->where('expires_at', '>', now())->exists();
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
            'reason' => trim((string) $data['reason']), 'justification' => trim((string) ($data['justification'] ?? '')) ?: null,
            'urgency_id' => $this->concept(self::URGENCIES, $data['urgency'] ?? 'normal'), 'status_id' => $this->concept(self::STATUSES, 'pending'),
        ])));
        // Notify the requester (acknowledgement) and everyone who may review it (review queue heads-up).
        $this->outbox->send($user, new AccessRequestSubmittedMail($user, $request));
        foreach ($this->reviewersFor($instantiation) as $reviewer) {
            if ($reviewer->id !== $user->id) {
                $this->outbox->send($reviewer, new AccessRequestPendingMail($reviewer, $request));
            }
        }

        return $request;
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
        $ids = AccessRequest::where('institution_id', $reviewer->institution_id)->with('instantiation.record')->get(['id', 'instantiation_id'])
            ->filter(fn (AccessRequest $r) => $this->access->allows($reviewer, 'review-access-requests', 'description', $r->instantiation->record))->modelKeys();

        return AccessRequest::whereKey($ids)
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status_id', $this->concept(self::STATUSES, $s)))
            ->with('user', 'status.labels', 'urgency.labels', 'instantiation.record', 'reviewer')->latest()->latest('id')->paginate($perPage)->withQueryString();
    }

    public function getMyRequests(User $user, int $perPage = 25): LengthAwarePaginator
    {
        return AccessRequest::where('user_id', $user->id)->with('status.labels', 'urgency.labels', 'instantiation.record', 'reviewer')
            ->latest()->latest('id')->paginate($perPage);
    }

    /** @return Collection<int, User> the institution's active accounts that may review requests for the instantiation's record */
    public function reviewersFor(Instantiation $instantiation): Collection
    {
        return User::where('institution_id', $instantiation->institution_id)->whereNull('disabled_at')->get()
            ->filter(fn (User $u) => $this->access->allows($u, 'review-access-requests', 'description', $instantiation->record))->values();
    }

    public function mayReview(User $reviewer, AccessRequest $request): bool
    {
        return $request->institution_id === $reviewer->institution_id
            && $this->access->allows($reviewer, 'review-access-requests', 'description', $request->instantiation->record);
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
