<?php

// OpenRiC Workspace - time-limited links that open one record to someone outside the institution (roadmap phase 19a,
// ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's IssueService, AccessService, RevokeService and PruneService (ahg-share-link): a link is issued for a
// record with an expiry (14 days unless chosen; at most 90, more only for who may manage the institution), a limit
// on how often it may be used, a recipient and a note; every use and every refusal (revoked, expired, used up) is
// logged; it can be revoked with a reason; links ended a year ago and log rows older than 180 days are deleted.
// Changed: the token is kept as a hash and shown once; only who may change the record issues one; the recipient
// sees the record as its issuer may read it, decided again at every use, never more; files only when the issuer
// allowed them and may receive them still. Heratio's clearance check comes with security clearance (18c).

namespace App\Research;

use App\Access\AccessControl;
use App\Audit\AuditLog;
use App\Description\InstantiationService;
use App\Exchange\ExportView;
use App\Models\Concept;
use App\Models\DigitalFile;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\ShareLink;
use App\Models\ShareLinkEvent;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ShareLinks
{
    public const EVENTS = Vocab::BASE.'share-link-event/';

    public const DEFAULT_DAYS = 14;

    public const MAX_DAYS = 90;

    public const MAX_DAYS_ADMINISTRATOR = 365;

    public const KEEP_LINKS_DAYS = 365;

    public const KEEP_EVENTS_DAYS = 180;

    public function mayIssue(User $user, RecordResource $unit): bool
    {
        return $user->institution_id === $unit->institution_id && $unit->deleted_at === null && $user->may('update', 'description', $unit);
    }

    /** @return array{0: ShareLink, 1: string} the link and its token, shown once */
    public function issue(User $user, RecordResource $unit, array $data): array
    {
        if (! $this->mayIssue($user, $unit)) {
            throw new InvalidArgumentException(__('Only who may change the record may share it.'));
        }
        $expires = ($data['expires_on'] ?? '') !== ''
            ? CarbonImmutable::parse($data['expires_on'], config('workspace.timezone'))->endOfDay()
            : CarbonImmutable::now(config('workspace.timezone'))->addDays(self::DEFAULT_DAYS);
        if ($expires->lessThanOrEqualTo(now())) {
            throw new InvalidArgumentException(__('The link must end after today.'));
        }
        $cap = $user->hasPermission(Vocab::permission('manage-institution')) ? self::MAX_DAYS_ADMINISTRATOR : self::MAX_DAYS;
        if ($expires->greaterThan(now()->addDays($cap)->endOfDay())) {
            throw new InvalidArgumentException(__('A link may last at most :days days.', ['days' => $cap]));
        }
        $max = ($data['max_access'] ?? '') !== '' && $data['max_access'] !== null ? (int) $data['max_access'] : null;
        if ($max !== null && $max < 1) {
            throw new InvalidArgumentException(__('It may be used at least once.'));
        }
        $email = trim((string) ($data['recipient_email'] ?? ''));
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException(__('Give a valid email address, or none.'));
        }
        $token = Str::random(48);
        $link = DB::transaction(fn () => AuditLog::actingAs($user, fn () => ShareLink::create([
            'institution_id' => $unit->institution_id, 'record_resource_id' => $unit->id, 'token_hash' => hash('sha256', $token), 'issued_by_id' => $user->id,
            'recipient_email' => $email ?: null, 'recipient_note' => trim((string) ($data['recipient_note'] ?? '')) ?: null, 'expires_at' => $expires, 'max_access' => $max,
            'allows_download' => (bool) ($data['allows_download'] ?? false),
        ])));

        return [$link, $token];
    }

    /**
     * Use a link: the link and its record when it may be used now (the use counted and logged), else the refusal.
     *
     * @return array{0: ?ShareLink, 1: ?string} the link, or the reason it was refused
     */
    public function open(string $token, ?string $ip, ?string $agent, string $event = 'view'): array
    {
        $link = ShareLink::where('token_hash', hash('sha256', $token))->with('record', 'issuedBy')->first();
        if ($link === null) {
            return [null, 'not-found'];
        }
        $refusal = match (true) {
            $link->revoked_at !== null => 'denied-revoked',
            $link->expires_at->lessThanOrEqualTo(now()) => 'denied-expired',
            $link->max_access !== null && $link->access_count >= $link->max_access && $event === 'view' => 'denied-quota',
            default => null,
        };
        // The issuer's reading of the record now: a link never opens more than its issuer may read.
        if ($refusal === null && (! $this->issuerReads($link) || $link->record->deleted_at !== null)) {
            $refusal = 'denied-revoked';
        }
        // A refusal is logged at once; a view is logged and counted; a download only once its file is sent (downloaded()).
        if ($refusal !== null || $event === 'view') {
            DB::transaction(function () use ($link, $refusal, $ip, $agent) {
                $this->log($link, $refusal ?? 'view', $ip, $agent);
                if ($refusal === null) {
                    $link->increment('access_count');
                }
            });
        }

        return $refusal ? [null, $refusal] : [$link, null];
    }

    /** What the recipient reads: the record as its issuer may read it, drafts too when the issuer may see them. */
    public function view(ShareLink $link): ExportView
    {
        return new ExportView($link->issuedBy, $link->issuedBy->may('view-draft', 'description', $link->record), null, Institution::find($link->institution_id));
    }

    /** May the recipient receive this file of the record? Only when the issuer allowed files and may receive it still. */
    public function deliverable(ShareLink $link, DigitalFile $file): bool
    {
        return $link->allows_download && $file->instantiation?->record_resource_id === $link->record_resource_id
            && app(InstantiationService::class)->deliverable($link->issuedBy, $file);
    }

    public function downloaded(ShareLink $link, ?string $ip, ?string $agent): void
    {
        $this->log($link, 'download', $ip, $agent);
    }

    public function revoke(User $user, ShareLink $link, ?string $reason): void
    {
        if ($user->institution_id !== $link->institution_id || ($link->issued_by_id !== $user->id && ! $user->hasPermission(Vocab::permission('manage-institution')))) {
            throw new InvalidArgumentException(__('Only who issued the link, or an administrator, may revoke it.'));
        }
        if ($link->revoked_at !== null) {
            throw new InvalidArgumentException(__('It is revoked already.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $link->update(['revoked_at' => now(), 'revoked_by_id' => $user->id, 'revoke_reason' => trim((string) $reason) ?: null])));
    }

    /** The daily run: log each link that has expired since, then delete what is kept no longer (Heratio's prune). @return array<string, int> */
    public function prune(): array
    {
        $expired = 0;
        ShareLink::whereNull('revoked_at')->where('expires_at', '<=', now())
            ->whereDoesntHave('events', fn ($q) => $q->whereIn('event_id', Concept::whereIn('iri', [self::EVENTS.'auto-expired'])->pluck('id')))
            ->each(function (ShareLink $link) use (&$expired) {
                $this->log($link, 'auto-expired', null, null);
                $expired++;
            });
        $cutoff = now()->subDays(self::KEEP_LINKS_DAYS);
        $links = ShareLink::where(fn ($q) => $q->where('expires_at', '<', $cutoff)->orWhere('revoked_at', '<', $cutoff))->delete();
        $events = ShareLinkEvent::where('happened_at', '<', now()->subDays(self::KEEP_EVENTS_DAYS))->delete();

        return ['expired' => $expired, 'links_deleted' => $links, 'events_deleted' => $events];
    }

    private function issuerReads(ShareLink $link): bool
    {
        $issuer = $link->issuedBy;

        return $issuer !== null && $issuer->disabled_at === null && EntityKinds::readable($issuer, $link->record)
            && app(AccessControl::class)->allows($issuer, 'read', 'description', $link->record, Institution::find($link->institution_id));
    }

    private function log(ShareLink $link, string $event, ?string $ip, ?string $agent): void
    {
        ShareLinkEvent::create(['share_link_id' => $link->id, 'event_id' => Concept::where('iri', self::EVENTS.$event)->value('id'),
            'ip_address' => $ip, 'user_agent' => $agent !== null ? mb_substr($agent, 0, 500) : null, 'happened_at' => now()]);
    }
}
