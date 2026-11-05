<?php

// OpenRiC Workspace - a mention was given to an archivist to resolve (Heratio's assignment of a mention, roadmap phase
// 17b2), to that archivist.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Mail;

use App\Models\AuthorityMention;
use App\Models\User;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AuthorityAssignmentMail extends WorkspaceMail
{
    public function __construct(User $recipient, public AuthorityMention $mention, public User $by, public ?string $note)
    {
        parent::__construct($recipient);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('A name to resolve: :name', ['name' => $this->mention->value()]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.authority-assignment');
    }
}
