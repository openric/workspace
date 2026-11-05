<?php

// OpenRiC Workspace - the one way the Workspace sends mail (roadmap phase 7).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Heratio's AccessRequestService::trySend, for every message: the suppression gate
// first, so an address that bounced or complained is not written to again, and a
// mailer that fails is logged, never thrown, so it cannot undo what the message tells
// of. A disabled account gets no mail. A research notice goes through the researcher's notices first (phase 19j),
// which keep it in the Workspace and mail it now, later in a digest, or not, as the researcher prefers.

namespace App\Mail;

use App\Models\User;
use App\Research\Notifications;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class Outbox
{
    public function send(User $to, WorkspaceMail $mail): bool
    {
        return $mail instanceof ResearchMail ? app(Notifications::class)->deliver($to, $mail) : $this->mail($to, $mail);
    }

    /** Mail it now, past the suppression gate. */
    public function mail(User $to, WorkspaceMail $mail): bool
    {
        if ($to->isDisabled() || ! EmailSuppressionGate::canSend($to->email, $mail::class, null, $to->institution_id)) {
            return false;
        }
        try {
            Mail::to($to)->queue($mail);

            return true;
        } catch (Throwable $e) {
            Log::warning('mail could not be queued', ['mailable' => $mail::class, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /** Mail someone without an account (a repatriation claimant, phase 17d2), past the same suppression gate. */
    public function address(string $email, Mailable $mail, ?string $institutionId = null): bool
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || ! EmailSuppressionGate::canSend($email, $mail::class, null, $institutionId)) {
            return false;
        }
        try {
            Mail::to($email)->queue($mail);

            return true;
        } catch (Throwable $e) {
            Log::warning('mail could not be queued', ['mailable' => $mail::class, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
