<?php

/**
 * EmailSuppressionGate
 *
 * Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
 * License: AGPL-3.0-or-later
 *
 * OpenRiC Workspace - ported from Heratio, app/Services/EmailSuppressionGate.php at commit 77a0cf787
 * (governance/CODE_PROVENANCE.md; PSIS ahgEmailDeliveryPlugin): canSend, isSuppressed and clear.
 * The suppression sources are Heratio's: an account with the address has email_bounced_at set (a
 * hard bounce, or soft ones promoted), or the address complained within 12 months. Changed: the
 * tables are the Workspace's (users, email_bounces with its type a concept), so the Schema guards
 * are not needed; a suppressed message is written to the sent-mail log here rather than through
 * Heratio's MailSuppressed event and its listener, which did only that.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Mail;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\EmailBounce;
use App\Models\SentEmail;
use App\Models\User;
use App\Support\Vocab;

class EmailSuppressionGate
{
    /**
     * May mail go to the address? When not, the held message is logged as suppressed.
     * Returns true when the dispatch should proceed, false when blocked.
     */
    public static function canSend(?string $email, ?string $mailableClass = null, ?string $subject = null, ?string $institutionId = null): bool
    {
        if (! self::isSuppressed($email)) {
            return true;
        }
        $address = strtolower(trim((string) $email));
        SentEmail::create([
            'institution_id' => $institutionId, 'mailable' => mb_substr($mailableClass ?? 'unknown', 0, 255), 'recipient_email' => mb_substr($address, 0, 255),
            'user_id' => User::whereRaw('LOWER(email) = ?', [$address])->value('id'), 'subject' => $subject ? mb_substr($subject, 0, 512) : null,
            'locale' => app()->getLocale(), 'status_id' => self::status('suppressed'), 'error' => 'bounce/complaint suppression list', 'queued_at' => now(),
        ]);

        return false;
    }

    public static function isSuppressed(?string $email): bool
    {
        $email = strtolower(trim((string) $email));
        if ($email === '') {
            return true;
        }
        if (User::whereRaw('LOWER(email) = ?', [$email])->whereNotNull('email_bounced_at')->exists()) {
            return true;
        }

        return EmailBounce::whereRaw('LOWER(email) = ?', [$email])->where('occurred_at', '>=', now()->subMonths(12))
            ->whereHas('type', fn ($q) => $q->where('iri', Vocab::BASE.'email-bounce-type/complaint'))->exists();
    }

    /**
     * Clear a bounce hold (admin action), once the recipient has confirmed the address works again.
     * A complaint still holds the address until it is 12 months old, as in Heratio.
     */
    public static function clear(string $email, ?User $actor = null): int
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return 0;
        }
        $held = User::whereRaw('LOWER(email) = ?', [$email])->whereNotNull('email_bounced_at')->get();
        // One by one, so each change is audited.
        AuditLog::actingAs($actor, fn () => $held->each->update(['email_bounced_at' => null]));

        return $held->count();
    }

    public static function status(string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.'email-status/'.$notation)->valueOrFail('id');
    }
}
