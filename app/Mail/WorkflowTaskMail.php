<?php

// OpenRiC Workspace - a workflow task assigned or decided (roadmap phase 10a), after Heratio's WorkflowTaskApprovedMail
// and WorkflowTaskRejectedMail and PSIS's task notifications. A WorkspaceMail, sent through Outbox behind the
// suppression gate, in the recipient's language, to an account of the institution only.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Mail;

use App\Models\User;
use App\Models\WorkflowTask;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WorkflowTaskMail extends WorkspaceMail
{
    /** What happened: assigned, approved, rejected, returned or completed. */
    public const EVENTS = ['assigned' => 'A task for you: :step', 'approved' => 'Approved: :step', 'rejected' => 'Rejected: :step',
        'returned' => 'Returned for revision: :step', 'completed' => 'Workflow completed: :workflow'];

    public function __construct(User $recipient, public WorkflowTask $task, public string $event, public ?string $comment = null)
    {
        parent::__construct($recipient);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __(self::EVENTS[$this->event], ['step' => $this->task->step->name, 'workflow' => $this->task->workflow->name]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.workflow-task');
    }
}
