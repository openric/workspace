<?php

// OpenRiC Workspace - a researcher's notebooks (roadmap phase 19d, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ResearchNotebooksController and NotebookService (getItems, addItem, touch) and research_notebook with
// research_notebook_item: a notebook gathers notes, records pinned to it, and saved searches, each item pinned to the
// top or not and kept in order, with a record as its cover. An item of AI output (Heratio's ai_output) is written by
// the AI services of phase 17, through the gateway; until then a notebook shows what it has. Heratio's promotion of a
// notebook to a research project comes with projects, in 19f.

namespace App\Research;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\RecordResource;
use App\Models\ResearchNotebook;
use App\Models\ResearchNotebookItem;
use App\Models\SavedSearch;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Notebooks
{
    public const TYPES = Vocab::BASE.'notebook-item-type/';

    public function save(User $user, array $data, ?ResearchNotebook $notebook = null): ResearchNotebook
    {
        if ($notebook) {
            $this->mustOwn($user, $notebook);
        }
        if (trim((string) ($data['title'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give the notebook a title.'));
        }
        $cover = ($data['cover_iri'] ?? '') !== '' ? $this->record($user, (string) $data['cover_iri']) : null;
        $fields = ['title' => trim($data['title']), 'summary' => trim((string) ($data['summary'] ?? '')) ?: null, 'cover_record_id' => $cover?->id,
            'sort_order' => (int) ($data['sort_order'] ?? $notebook?->sort_order ?? 0)];

        return DB::transaction(fn () => AuditLog::actingAs($user, function () use ($user, $notebook, $fields) {
            if ($notebook) {
                $notebook->update($fields);

                return $notebook;
            }

            return ResearchNotebook::create($fields + ['institution_id' => $user->institution_id, 'user_id' => $user->id]);
        }));
    }

    public function delete(User $user, ResearchNotebook $notebook): void
    {
        $this->mustOwn($user, $notebook);
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $notebook->delete()));
    }

    /** Add a note, a record (by its IRI) or a saved search of one's own. */
    public function addItem(User $user, ResearchNotebook $notebook, array $data): ResearchNotebookItem
    {
        $this->mustOwn($user, $notebook);
        $type = (string) ($data['item_type'] ?? 'note');
        $fields = ['title' => trim((string) ($data['title'] ?? '')) ?: null, 'body' => trim((string) ($data['body'] ?? '')) ?: null, 'pinned' => ! empty($data['pinned'])];
        match ($type) {
            'note' => $fields['body'] !== null || $fields['title'] !== null ?: throw new InvalidArgumentException(__('Write the note.')),
            'source-pin' => $fields['source_record_id'] = $this->record($user, (string) ($data['iri'] ?? ''))->id,
            'saved-query' => $fields['saved_search_id'] = SavedSearch::where(['id' => $data['saved_search_id'] ?? null, 'user_id' => $user->id])->value('id')
                ?? throw new InvalidArgumentException(__('Choose one of your saved searches.')),
            default => throw new InvalidArgumentException(__('That kind of item is written by the AI services.')),
        };

        return DB::transaction(fn () => AuditLog::actingAs($user, function () use ($notebook, $type, $fields) {
            $item = $notebook->items()->create($fields + ['item_type_id' => Concept::where('iri', self::TYPES.$type)->value('id'), 'sort_order' => (int) $notebook->items()->max('sort_order') + 1]);
            $notebook->touch();

            return $item;
        }));
    }

    public function updateItem(User $user, ResearchNotebookItem $item, array $data): void
    {
        $this->mustOwn($user, $item->notebook);
        DB::transaction(fn () => AuditLog::actingAs($user, function () use ($item, $data) {
            $item->update(['title' => trim((string) ($data['title'] ?? '')) ?: null, 'body' => trim((string) ($data['body'] ?? '')) ?: $item->body,
                'pinned' => ! empty($data['pinned']), 'sort_order' => (int) ($data['sort_order'] ?? $item->sort_order)]);
            $item->notebook->touch();
        }));
    }

    public function removeItem(User $user, ResearchNotebookItem $item): void
    {
        $this->mustOwn($user, $item->notebook);
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $item->delete()));
    }

    public function mustOwn(User $user, ResearchNotebook $notebook): void
    {
        if ($notebook->user_id !== $user->id) {
            throw new InvalidArgumentException(__('That is not yours.'));
        }
    }

    private function record(User $user, string $iri): RecordResource
    {
        $record = RecordResource::where(['institution_id' => $user->institution_id, 'iri' => trim($iri)])->whereNull('deleted_at')->first();

        return $record && EntityKinds::readable($user, $record) ? $record : throw new InvalidArgumentException(__('No record has that IRI.'));
    }
}
