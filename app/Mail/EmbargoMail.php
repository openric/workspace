<?php

// OpenRiC Workspace - an embargo ending soon, ended or lifted (roadmap phase 13a, ADR 0015), after Heratio's
// EmbargoNotificationService (sendExpiryNotification, sendLiftedNotification). A WorkspaceMail, sent through Outbox
// behind the suppression gate, in the recipient's language, to an account of the institution only.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Mail;

use App\Models\Embargo;
use App\Models\User;
use App\Relations\EntityKinds;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class EmbargoMail extends WorkspaceMail
{
    public const EVENTS = ['ending' => 'Embargo ending on :date: :record', 'ended' => 'Embargo ended: :record', 'lifted' => 'Embargo lifted: :record'];

    public function __construct(User $recipient, public Embargo $embargo, public string $event)
    {
        parent::__construct($recipient);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __(self::EVENTS[$this->event], ['record' => EntityKinds::label($this->embargo->record), 'date' => $this->embargo->ends_on?->format('Y-m-d')]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.embargo');
    }
}
