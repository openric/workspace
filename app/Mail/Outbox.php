<?php

// OpenRiC Workspace - the one way the Workspace sends mail (roadmap phase 7).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Heratio's AccessRequestService::trySend, for every message: the suppression gate
// first, so an address that bounced or complained is not written to again, and a
// mailer that fails is logged, never thrown, so it cannot undo what the message tells
// of. A disabled account gets no mail.

namespace App\Mail;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class Outbox
{
    public function send(User $to, WorkspaceMail $mail): bool
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
}
