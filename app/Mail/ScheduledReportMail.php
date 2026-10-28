<?php

// OpenRiC Workspace - a scheduled report, sent with its file (roadmap phase 8), after PSIS's ReportScheduler::sendEmail
// (ahgReportBuilderPlugin). Changed: a WorkspaceMail, sent through Outbox behind the suppression gate, in the recipient's
// language, to an account of the institution only.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Mail;

use App\Models\CustomReport;
use App\Models\ReportArchive;
use App\Models\User;
use App\Reports\ReportExport;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;

class ScheduledReportMail extends WorkspaceMail
{
    public function __construct(User $recipient, public CustomReport $report, public ReportArchive $archive)
    {
        parent::__construct($recipient);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Report: :name', ['name' => $this->report->name]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.scheduled-report');
    }

    public function attachments(): array
    {
        [$extension, $type] = ReportExport::FORMATS[$this->archive->format];

        return [Attachment::fromStorageDisk('local', $this->archive->path)->as((Str::slug($this->report->name) ?: 'report').'.'.$extension)->withMime(explode(';', $type)[0])];
    }
}
