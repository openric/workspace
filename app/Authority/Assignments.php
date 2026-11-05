<?php

// OpenRiC Workspace - giving a mention to an archivist to resolve (roadmap phase 17b2), after Heratio's
// AssignmentService: one or many at once, to an account of the institution that may review proposals, told by mail;
// the queue then shows them as theirs.
// Changed from Heratio: an assignment is kept on the mention and the archivist is told, which is Heratio's path when
// its workflow is not installed; the Workspace's workflows move descriptions, authority records, repositories and
// accessions, not mentions.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Authority;

use App\Audit\AuditLog;
use App\Mail\AuthorityAssignmentMail;
use App\Mail\Outbox;
use App\Models\AuthorityMention;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class Assignments
{
    public function __construct(private Outbox $outbox) {}

    public function assign(AuthorityMention $mention, User $archivist, User $by, ?string $note = null): void
    {
        $archivist->institution_id === $mention->institution_id && $archivist->disabled_at === null
            && $archivist->mayAnywhere('review-proposals', 'description') || throw new InvalidArgumentException(__('Choose an archivist of the institution who may review proposals.'));
        $note = ($note = trim((string) $note)) === '' ? null : $note;
        AuditLog::actingAs($by, fn () => $mention->update(['assigned_to_id' => $archivist->id, 'assigned_by_id' => $by->id, 'assigned_at' => now(), 'assignment_note' => $note]));
        $this->outbox->send($archivist, new AuthorityAssignmentMail($archivist, $mention, $by, $note));
    }

    /** @return array{assigned: int, failed: int} */
    public function assignMany(Collection $mentions, User $archivist, User $by, ?string $note = null): array
    {
        $done = 0;
        foreach ($mentions as $mention) {
            $this->assign($mention, $archivist, $by, $note);
            $done++;
        }

        return ['assigned' => $done, 'failed' => 0];
    }

    /** The accounts a mention may be given to. @return Collection<int, User> */
    public function archivists(string $institutionId): Collection
    {
        return User::where('institution_id', $institutionId)->orderBy('name')->get()->filter(fn (User $u) => $u->disabled_at === null && $u->mayAnywhere('review-proposals', 'description'))->values();
    }
}
