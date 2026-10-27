<?php

// OpenRiC Workspace - what every message the Workspace sends shares (roadmap phase 7).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Sent in the background, once the change that caused it is committed, in the
// recipient's interface language (User::preferredLocale). The class is named in a
// header for the sent-mail log, which takes it off again; a message the mail server
// refuses is logged as failed.

namespace App\Mail;

use App\Models\SentEmail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Throwable;

abstract class WorkspaceMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public User $recipient)
    {
        $this->afterCommit();
    }

    public function headers(): Headers
    {
        return new Headers(text: [EmailAuditListener::MAILABLE_HEADER => static::class]);
    }

    public function failed(Throwable $e): void
    {
        SentEmail::create([
            'institution_id' => $this->recipient->institution_id, 'mailable' => static::class, 'recipient_email' => mb_substr(strtolower($this->recipient->email), 0, 255),
            'user_id' => $this->recipient->id, 'locale' => $this->recipient->preferredLocale(), 'status_id' => EmailSuppressionGate::status('failed'),
            'error' => mb_substr($e->getMessage(), 0, 4000), 'queued_at' => now(),
        ]);
    }
}
