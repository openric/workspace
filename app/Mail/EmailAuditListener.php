<?php

/**
 * EmailAuditListener
 *
 * Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
 * License: AGPL-3.0-or-later
 *
 * OpenRiC Workspace - ported from Heratio, app/Listeners/EmailAuditListener.php at commit 77a0cf787
 * (governance/CODE_PROVENANCE.md): handleMessageSending stamps an audit id on the message and logs
 * it as queued, handleMessageSent marks it sent; firstAddress, extractAuditId and lookupUserId.
 * Changed: the Workspace's sent_emails table with its status a concept; the institution is the
 * recipient account's, in place of Heratio's tenant; the mailable is the mailable's own header
 * (Outbox stamps it), since Laravel passes no class name with a raw message. Not copied: the
 * email_audit_enabled switch (the log is always kept), handleMessageFailed (Laravel has no such
 * event; Outbox records a failed send), handleMailSuppressed (EmailSuppressionGate logs it).
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Mail;

use App\Models\SentEmail;
use App\Models\User;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

class EmailAuditListener
{
    /** Header stamped on outgoing mail so MessageSent can find the queued row again. */
    public const AUDIT_HEADER = 'X-Workspace-Audit-Id';

    /** Header naming the mailable class, set by Outbox and removed before the message leaves. */
    public const MAILABLE_HEADER = 'X-Workspace-Mailable';

    public function handleMessageSending(MessageSending $event): void
    {
        try {
            $email = $event->message;
            $headers = $email->getHeaders();
            $auditId = (string) Str::uuid();
            if (! $headers->has(self::AUDIT_HEADER)) {
                $headers->addTextHeader(self::AUDIT_HEADER, $auditId);
            } else {
                $auditId = $headers->get(self::AUDIT_HEADER)->getBodyAsString();
            }
            $mailable = $headers->has(self::MAILABLE_HEADER) ? $headers->get(self::MAILABLE_HEADER)->getBodyAsString() : 'unknown';
            $headers->remove(self::MAILABLE_HEADER);
            $recipient = $this->firstAddress($email->getTo());
            if ($recipient === null) {
                return;
            }
            $user = $this->lookupUser($recipient);
            SentEmail::create([
                'institution_id' => $user?->institution_id, 'mailable' => mb_substr($mailable, 0, 255), 'recipient_email' => mb_substr($recipient, 0, 255),
                'user_id' => $user?->id, 'subject' => mb_substr((string) ($email->getSubject() ?? ''), 0, 512), 'locale' => app()->getLocale(),
                'audit_id' => $auditId, 'status_id' => EmailSuppressionGate::status('queued'), 'queued_at' => now(),
            ]);
        } catch (Throwable) {
            // Audit failure must never break the actual send.
        }
    }

    public function handleMessageSent(MessageSent $event): void
    {
        try {
            $auditId = $this->extractAuditId($event->message);
            if ($auditId !== null) {
                SentEmail::where('audit_id', $auditId)->update(['status_id' => EmailSuppressionGate::status('sent'), 'sent_at' => now()]);
            }
        } catch (Throwable) {
            // swallow
        }
    }

    protected function extractAuditId(Email $email): ?string
    {
        $headers = $email->getHeaders();

        return $headers->has(self::AUDIT_HEADER) ? trim($headers->get(self::AUDIT_HEADER)->getBodyAsString()) : null;
    }

    /** @param  array<Address>  $addresses */
    protected function firstAddress(array $addresses): ?string
    {
        foreach ($addresses as $address) {
            if ($value = $address->getAddress()) {
                return strtolower($value);
            }
        }

        return null;
    }

    protected function lookupUser(string $email): ?User
    {
        return User::whereRaw('LOWER(email) = ?', [strtolower(trim($email))])->first();
    }
}
