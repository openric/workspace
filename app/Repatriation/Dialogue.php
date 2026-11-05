<?php

/*
 * OpenRiC Workspace - copied from Heratio, packages/ahg-semantic-search/src/Services/RepatriationDialogueService.php
 * at commit bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md, roadmap phase 17d2): postMessage,
 * messages (shared only, for the shared record), grantAccess, resolveToken (active and not expired), touchGrant and
 * revokeGrant. Changed: the parties and visibilities are vocabulary rows; a link's token is shown once and only its
 * SHA-256 kept (Heratio keeps the token); an observer's link may never write; the status history is the claim's
 * statusChanges; the namespace and code style.
 * Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Repatriation;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\RepatriationClaim;
use App\Models\RepatriationMessage;
use App\Models\RepatriationShare;
use App\Models\User;
use App\Support\Vocab;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class Dialogue
{
    public const PARTIES = Vocab::BASE.'repatriation-party/';

    public const VISIBILITIES = Vocab::BASE.'repatriation-message-visibility/';

    /** A staff member speaks for the institution or as a mediator; claimants and observers speak through a link. */
    public const STAFF_ROLES = ['institution', 'mediator'];

    /**
     * Add a message to the dialogue. $role and $visibility are notations; a message through a link is always shared,
     * as the claimant or the mediator the link was given to.
     */
    public function post(RepatriationClaim $claim, string $body, string $role, string $visibility, ?User $author = null, ?RepatriationShare $share = null, ?string $name = null): RepatriationMessage
    {
        ($body = trim($body)) !== '' || throw new InvalidArgumentException(__('Write the message.'));
        if ($share !== null) {
            $share->claim_id === $claim->id && $share->can_message && $share->isActive() || throw new InvalidArgumentException(__('This link is read-only.'));
            [$role, $visibility, $name] = [$share->role->notation === 'mediator' ? 'mediator' : 'claimant', 'shared', $share->grantee_name ?? __('Claimant representative')];
        } else {
            in_array($role, self::STAFF_ROLES, true) || throw new InvalidArgumentException(__('Staff speak for the institution or as a mediator.'));
        }

        return RepatriationMessage::create(['claim_id' => $claim->id, 'author_role_id' => $this->concept(self::PARTIES, $role), 'author_name' => mb_substr((string) ($name ?? $author?->name), 0, 255) ?: null,
            'author_user_id' => $author?->id, 'share_id' => $share?->id, 'body' => mb_substr($body, 0, 60000), 'visibility_id' => $this->concept(self::VISIBILITIES, $visibility)]);
    }

    /** The dialogue, oldest first; $sharedOnly for those holding a link. */
    public function messages(RepatriationClaim $claim, bool $sharedOnly = false): Collection
    {
        return $claim->messages()->with('role.labels', 'visibility.labels')
            ->when($sharedOnly, fn ($q) => $q->where('visibility_id', $this->concept(self::VISIBILITIES, 'shared')))->get();
    }

    /**
     * Make a link to the claim's shared record. $data: grantee_name, grantee_role (claimant, mediator or observer),
     * can_message, expires_at. Returns the link and its token, which is not kept and cannot be shown again.
     *
     * @return array{RepatriationShare, string}
     */
    public function grant(RepatriationClaim $claim, array $data, User $actor): array
    {
        $role = $data['grantee_role'] ?? 'claimant';
        in_array($role, ['claimant', 'mediator', 'observer'], true) || throw new InvalidArgumentException(__('A link is for a claimant, a mediator or an observer.'));
        $expires = ($data['expires_at'] ?? null) ? CarbonImmutable::parse($data['expires_at'])->endOfDay() : null;
        $expires === null || $expires->isFuture() || throw new InvalidArgumentException(__('A link cannot expire in the past.'));
        $token = Str::random(48);
        $share = DB::transaction(fn () => AuditLog::actingAs($actor, fn () => RepatriationShare::create(['claim_id' => $claim->id, 'token_hash' => hash('sha256', $token),
            'grantee_name' => mb_substr(trim((string) ($data['grantee_name'] ?? '')), 0, 255) ?: null, 'grantee_role_id' => $this->concept(self::PARTIES, $role),
            'can_message' => $role !== 'observer' && (bool) ($data['can_message'] ?? true), 'expires_at' => $expires, 'created_by_id' => $actor->id])));

        return [$share, $token];
    }

    /** The active link a token opens, noting that it was used; null for one revoked, expired or unknown. */
    public function resolve(string $token): ?RepatriationShare
    {
        $share = preg_match('/^[A-Za-z0-9]{48}$/', $token) ? RepatriationShare::where('token_hash', hash('sha256', $token))->with('role', 'claim')->first() : null;
        if ($share === null || ! $share->isActive()) {
            return null;
        }
        $share->update(['last_seen_at' => now()]);

        return $share;
    }

    public function revoke(RepatriationShare $share, User $actor): void
    {
        $share->revoked_at === null && DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $share->update(['revoked_at' => now()])));
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', $vocabulary.$notation)->value('id') ?? throw new InvalidArgumentException(__('Unknown value :value.', ['value' => $notation]));
    }
}
