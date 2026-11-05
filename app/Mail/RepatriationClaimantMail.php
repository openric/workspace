<?php

/*
 * OpenRiC Workspace - copied from Heratio, packages/ahg-semantic-search/src/Mail/RepatriationClaimRegisteredMail.php
 * and RepatriationClaimStatusMail.php at commit bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md,
 * roadmap phase 17d2): the claimant's receipt for a claim, and word of each change of its status, at the email the
 * claim gives. Changed: one mailable for both; the claimant has no account, so it is sent through Outbox::address,
 * behind the suppression gate, rather than as a WorkspaceMail; the namespace and code style.
 * Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Mail;

use App\Models\RepatriationClaim;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class RepatriationClaimantMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** $fromStatus and $toStatus are status labels; both null for the receipt. */
    public function __construct(public RepatriationClaim $claim, public ?string $fromStatus = null, public ?string $toStatus = null)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->toStatus === null ? __('Your repatriation claim has been registered') : __('Your repatriation claim has been updated'));
    }

    public function headers(): Headers
    {
        return new Headers(text: [EmailAuditListener::MAILABLE_HEADER => static::class]);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.repatriation-claimant');
    }
}
