<?php

/**
 * AccessRequestDeniedMail - Heratio
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * OpenRiC Workspace - ported from Heratio, packages/ahg-access-request/src/Mail/AccessRequestDeniedMail.php at commit
 * 77a0cf787 (governance/CODE_PROVENANCE.md). Sent to the requester when it is refused, with the reason. Changed: a WorkspaceMail, sent through Outbox
 * behind the suppression gate, in the recipient's language; the request is the model, named by the
 * file asked for rather than its number.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Mail;

use App\Models\AccessRequest;
use App\Models\User;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AccessRequestDeniedMail extends WorkspaceMail
{
    public function __construct(User $recipient, public AccessRequest $request)
    {
        parent::__construct($recipient);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Your request for access was refused'));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.access-request.denied', with: ['file' => $this->request->target()]);
    }
}
