<?php

// OpenRiC Workspace - a unit's DOI was registered, or DataCite refused it (Heratio's DoiMintedMail and DoiFailedMail;
// roadmap phase 20c), to the person who asked for it.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Mail;

use App\Models\RecordDoi;
use App\Models\RecordResource;
use App\Models\User;
use App\Relations\EntityKinds;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class DoiMail extends WorkspaceMail
{
    public function __construct(User $recipient, public RecordResource $unit, public ?RecordDoi $doi, public ?string $error)
    {
        parent::__construct($recipient);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->doi ? __('DOI registered: :doi', ['doi' => $this->doi->doi]) : __('DOI not registered: :title', ['title' => EntityKinds::label($this->unit)]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.doi');
    }
}
