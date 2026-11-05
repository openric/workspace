<?php

// OpenRiC Workspace - after Heratio's RepatriationNotifier (ahg-semantic-search, in-app notices to administrators):
// a new repatriation claim, or a change of its status, told to each account that manages claims (roadmap phase 17d2).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Mail;

use App\Models\RepatriationClaim;
use App\Models\User;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class RepatriationClaimMail extends WorkspaceMail
{
    /** $fromStatus and $toStatus are status labels; both null for a new claim. */
    public function __construct(User $recipient, public RepatriationClaim $claim, public ?string $fromStatus = null, public ?string $toStatus = null)
    {
        parent::__construct($recipient);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->toStatus === null ? __('New repatriation claim registered') : __('Repatriation claim status updated'));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.repatriation-claim');
    }
}
