<?php

// OpenRiC Workspace - the researcher's inbox: what they capture as it comes to them, to sort later (roadmap phase
// 19j, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's InboxService (its quick capture). A note, a clipping from the web, a photo or another file, kept
// with when and where it came from, and only ever the researcher's own. Sorting later: into one of their projects,
// marked sorted, archived, or brought back. A file is kept as every research file is, scanned and outside the public
// directory.

namespace App\Research;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\ResearchInboxItem;
use App\Models\ResearchProject;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Inbox
{
    public function __construct(private ResearchFiles $files, private Projects $projects) {}

    public function items(User $user, string $status = 'inbox'): Collection
    {
        return ResearchInboxItem::where('user_id', $user->id)->whereHas('status', fn ($q) => $q->where('iri', Vocab::BASE.'inbox-status/'.$status))
            ->with('kind', 'origin', 'file', 'project')->orderByDesc('created_at')->get();
    }

    public function counts(User $user): array
    {
        return ResearchInboxItem::where('user_id', $user->id)->with('status')->get()->countBy(fn ($i) => $i->status->notation)->all();
    }

    public function capture(User $user, array $data, ?UploadedFile $upload = null): ResearchInboxItem
    {
        $title = trim((string) ($data['title'] ?? ''));
        $body = trim((string) ($data['body'] ?? ''));
        $url = trim((string) ($data['source_url'] ?? ''));
        if ($title === '' && $body === '' && $url === '' && ! $upload) {
            throw new InvalidArgumentException(__('Capture something: a note, a web address or a file.'));
        }
        if ($url !== '' && ! preg_match('#^https?://#i', $url)) {
            throw new InvalidArgumentException(__('Give a web address.'));
        }
        $kind = (string) ($data['kind'] ?? ($upload ? (str_starts_with((string) $upload->getMimeType(), 'image/') ? 'photo' : 'file') : ($url !== '' ? 'clip' : 'note')));

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => ResearchInboxItem::create(['user_id' => $user->id, 'kind_id' => $this->concept('inbox-kind', $kind),
            'origin_id' => $this->concept('inbox-origin', (string) ($data['origin'] ?? 'web')), 'status_id' => $this->concept('inbox-status', 'inbox'),
            'title' => $title !== '' ? mb_substr($title, 0, 500) : null, 'body' => $body ?: null, 'source_url' => $url ?: null,
            'file_id' => $upload ? $this->files->keep($upload, $user->institution_id, $user)->id : null])));
    }

    /** sort (marked sorted), archive, restore (back to the inbox), or project (sorted into one of the researcher's projects). */
    public function step(User $user, ResearchInboxItem $item, string $step, ?string $projectId = null): void
    {
        if ($item->user_id !== $user->id) {
            throw new InvalidArgumentException(__('That is not yours.'));
        }
        $project = null;
        if ($step === 'project') {
            $project = ResearchProject::find($projectId);
            if (! $project || $this->projects->level($user, $project) < Projects::LEVELS['contributor']) {
                throw new InvalidArgumentException(__('Choose one of your projects.'));
            }
        }
        $fields = match ($step) {
            'sort', 'project' => ['status_id' => $this->concept('inbox-status', 'triaged'), 'triaged_at' => now()] + ($project ? ['project_id' => $project->id] : []),
            'archive' => ['status_id' => $this->concept('inbox-status', 'archived')],
            'restore' => ['status_id' => $this->concept('inbox-status', 'inbox'), 'triaged_at' => null],
            'delete' => null,
        };
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $fields === null ? $item->delete() : $item->update($fields)));
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
