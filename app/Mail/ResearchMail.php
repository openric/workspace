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
    /** The kind of notice each subject is (notification-type vocabulary). */
    private const KINDS = ['A comment on your research' => 'comment', 'An invitation to the research project :name' => 'invitation', 'An invitation to the research workspace :name' => 'invitation',
        'A request to review :title' => 'collaboration', 'A review of :title' => 'reply', 'New results for your saved search :name' => 'alert',
        'Your reproduction request :reference is :status' => 'alert', 'Your submission :title' => 'alert'];

    /**
     * @param  string  $subject  an interface string, translated in the recipient's language
     * @param  list<string>  $lines  interface strings, each with its replacements as [string, replacements]
     * @param  ?string  $kind  the notification-type it is (phase 19j); by its subject when not given
     */
    public function __construct(User $recipient, public string $subjectLine, public array $subjectReplace, public array $lines, public ?string $link = null, public ?string $linkText = null,
        public ?string $kind = null)
    {
        parent::__construct($recipient);
        $this->kind ??= self::KINDS[$subjectLine] ?? 'system';
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
