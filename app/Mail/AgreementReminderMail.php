<?php

// OpenRiC Workspace - a reminder of a donor agreement (roadmap phase 9b), after Heratio's DonorAgreementReminderMail.
// Changed: a WorkspaceMail, sent through Outbox behind the suppression gate, in the recipient's language, to an account
// of the institution only, never to the donor.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Mail;

use App\Models\AgreementReminder;
use App\Models\User;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AgreementReminderMail extends WorkspaceMail
{
    /** The date it is due on, as it was when sent: a reminder that recurs has moved on by the time the mail is made. */
    public string $due;

    public function __construct(User $recipient, public AgreementReminder $reminder)
    {
        parent::__construct($recipient);
        $this->due = $reminder->remind_on->format('Y-m-d');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Reminder: :subject', ['subject' => $this->reminder->subject]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.agreement-reminder');
    }
}
