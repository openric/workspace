<?php

// OpenRiC Workspace - a notice of research services to a researcher or to staff (roadmap phase 19, ADR 0017), after
// Heratio's research mails (BookingCreatedMail, BookingCancelledMail and the registration notices): a subject and a
// few lines, with a link. A WorkspaceMail, sent through Outbox behind the suppression gate, in the recipient's
// language, to an account of the institution only.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ResearchMail extends WorkspaceMail
{
    /**
     * @param  string  $subject  an interface string, translated in the recipient's language
     * @param  list<string>  $lines  interface strings, each with its replacements as [string, replacements]
     */
    public function __construct(User $recipient, public string $subjectLine, public array $subjectReplace, public array $lines, public ?string $link = null, public ?string $linkText = null)
    {
        parent::__construct($recipient);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __($this->subjectLine, $this->subjectReplace));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.research');
    }
}
