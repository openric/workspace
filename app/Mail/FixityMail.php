<?php

// OpenRiC Workspace - a fixity run found files missing or changed (roadmap phase 12a), to the account its schedule names.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Mail;

use App\Models\FixityRun;
use App\Models\User;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class FixityMail extends WorkspaceMail
{
    public function __construct(User $recipient, public FixityRun $run)
    {
        parent::__construct($recipient);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Fixity: :count files missing or changed', ['count' => $this->run->files_changed + $this->run->files_missing]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.fixity');
    }
}
