<?php

/*
 * OpenRiC Workspace - copied from CallHub, app/Services/WhatsAppNotifier.php (5 November 2026, governance/CODE_PROVENANCE.md):
 * the notices the Workspace mails its staff, also handed to the estate's WhatsApp sender for those who ask. The
 * Workspace holds no WhatsApp credential: it writes a JSON payload into the spool, under a dot-name and renamed into
 * place so the watcher never reads half a file, and ahg-whatsapp-watcher sends it. Staff only, never a researcher or
 * the public; templates only, as Meta allows a business to open a conversation with nothing else; never to the
 * sending number itself; and never failing what it tells of. e164, send and spool as CallHub has them. Changed: one
 * template for every notice (ahg_system_notice: the institution, the subject, the link), the subject and link taken
 * from the mail; staff are accounts with a role beyond signed-in; the namespace and code style.
 * Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Mail;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class WhatsApp
{
    public static function enabled(): bool
    {
        return (bool) config('workspace.whatsapp.enabled');
    }

    /** A South African or international number in E.164 digits, without the plus; null where it cannot be trusted. */
    public static function e164(?string $number): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', (string) $number);
        if ($digits === '') {
            return null;
        }
        // 0821234567 -> 27821234567: a leading 0 is a national trunk code, and the default country the only assumption.
        if (str_starts_with($digits, '0')) {
            $digits = config('workspace.whatsapp.country_code', '27').substr($digits, 1);
        }

        return strlen($digits) >= 10 && strlen($digits) <= 15 ? $digits : null;
    }

    /** Staff: an account with a role beyond the signed-in and public templates. */
    public static function isStaff(User $user): bool
    {
        return $user->roles->contains(fn (Role $r) => ! $r->isTemplate('signed-in') && ! $r->isTemplate('public'));
    }

    /** Queue the notice a mail carries for its recipient, where they asked; false where nothing was queued. */
    public function notify(User $to, WorkspaceMail $mail): bool
    {
        if (! self::enabled() || ! $to->whatsapp_notices || $mail instanceof ResearchMail || $to->isDisabled() || ! self::isStaff($to)) {
            return false;
        }
        $number = self::e164($to->whatsapp_number);
        if ($number === null) {
            return false;
        }
        // Never to ourselves: the business number is on the Cloud API, not a handset, so a message to it reaches nobody.
        if ($number === self::e164(config('workspace.whatsapp.sender'))) {
            Log::warning('whatsapp: refused for user '.$to->id.' - the number given is the sending number, which reaches nobody.');

            return false;
        }
        // WhatsApp refuses a parameter with a new line, a tab or more than four spaces in a row.
        $text = fn (string $v) => mb_substr(trim((string) preg_replace('/\s+/u', ' ', $v)), 0, 500);
        try {
            $subject = (string) $mail->envelope()->subject;
        } catch (Throwable) {
            $subject = '';
        }

        return $this->spool(['to' => $number, 'purpose' => 'transactional', 'template' => (string) config('workspace.whatsapp.template'),
            'language' => (string) config('workspace.whatsapp.language', 'en'), 'components' => [['type' => 'body', 'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => $text($v)],
                [$to->institution?->name ?? 'OpenRiC Workspace', $subject, $mail->whatsappLink()])]]], $to->id);
    }

    /** Write the payload into the spool: a dot-name first, then renamed, so the watcher never sees part of it. */
    private function spool(array $payload, string $userId): bool
    {
        $dir = rtrim((string) config('workspace.whatsapp.spool'), '/');
        $name = 'openric-workspace-'.$userId.'-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        try {
            if (file_put_contents("$dir/.$name", json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
                throw new RuntimeException('could not write to the WhatsApp spool');
            }
            if (! rename("$dir/.$name", "$dir/$name")) {
                @unlink("$dir/.$name");
                throw new RuntimeException('could not move the payload into the WhatsApp spool');
            }

            return true;
        } catch (Throwable $e) {
            // Logged, not swallowed; the number is personal data and left out of the log.
            Log::warning('whatsapp: not queued for user '.$userId.' - '.$e->getMessage());

            return false;
        }
    }
}
