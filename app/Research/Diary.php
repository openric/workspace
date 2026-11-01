<?php

// OpenRiC Workspace - a researcher's diary (roadmap phase 19d, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ResearchJournalEntryController and research_journal_entry: dated entries with the time spent, tags
// and what they are about, filtered by kind and dates, with the time added up. Heratio lists entry types the Workspace
// writes for itself (auto_booking, auto_material, auto_annotation, auto_search, auto_collection) but nothing writes
// them; here a booking, a request for a record, an annotation, a saved search and a collection each leave one, written
// by the Workspace in the researcher's name. An entry is private unless its owner says otherwise; "not private" lets
// the members of the research workspaces they belong to read it.

namespace App\Research;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\ResearchDiaryEntry;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Diary
{
    public const TYPES = Vocab::BASE.'diary-entry-type/';

    /** The kinds a researcher writes; the others the Workspace writes. */
    public const WRITTEN = ['manual', 'reflection', 'milestone'];

    public function save(User $user, array $data, ?ResearchDiaryEntry $entry = null): ResearchDiaryEntry
    {
        if ($entry && $entry->user_id !== $user->id) {
            throw new InvalidArgumentException(__('That is not yours.'));
        }
        if (trim((string) ($data['content'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Write what the entry says.'));
        }
        $type = $entry && ! in_array($entry->entryType->notation, self::WRITTEN, true) ? $entry->entry_type_id
            : $this->type(in_array($data['entry_type'] ?? 'manual', self::WRITTEN, true) ? ($data['entry_type'] ?? 'manual') : 'manual');
        $minutes = ($data['time_spent_minutes'] ?? '') === '' || $data['time_spent_minutes'] === null ? null : max(0, min(1440, (int) $data['time_spent_minutes']));
        $fields = ['entry_date' => $data['entry_date'] ?? now()->toDateString(), 'title' => trim((string) ($data['title'] ?? '')) ?: null, 'content' => trim($data['content']),
            'entry_type_id' => $type, 'time_spent_minutes' => $minutes, 'tags' => self::tags($data['tags'] ?? null), 'is_private' => (bool) ($data['is_private'] ?? true)];

        return DB::transaction(fn () => AuditLog::actingAs($user, function () use ($user, $entry, $fields) {
            if ($entry) {
                $entry->update($fields);

                return $entry;
            }

            return ResearchDiaryEntry::create($fields + ['institution_id' => $user->institution_id, 'user_id' => $user->id]);
        }));
    }

    public function delete(User $user, ResearchDiaryEntry $entry): void
    {
        if ($entry->user_id !== $user->id) {
            throw new InvalidArgumentException(__('That is not yours.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $entry->delete()));
    }

    /** An entry the Workspace writes for the researcher: what they did, about what. Called inside the caller's transaction. */
    public function auto(User $user, string $type, string $title, ?Model $related = null): ResearchDiaryEntry
    {
        return ResearchDiaryEntry::create(['institution_id' => $user->institution_id, 'user_id' => $user->id, 'entry_date' => now()->toDateString(), 'title' => mb_substr($title, 0, 500),
            'content' => $title, 'entry_type_id' => $this->type($type), 'is_private' => true, 'related_table' => $related?->getTable(), 'related_id' => $related?->getKey()]);
    }

    /** One's entries, filtered as Heratio's journal list filters them: by kind, dates and words. */
    public function entries(User $user, array $filters = []): Builder
    {
        $q = trim((string) ($filters['q'] ?? ''));

        return ResearchDiaryEntry::where('user_id', $user->id)->with('entryType')
            ->when($filters['type'] ?? null, fn ($w, $t) => $w->whereHas('entryType', fn ($c) => $c->where('iri', self::TYPES.$t)))
            ->when($filters['from'] ?? null, fn ($w, $d) => $w->where('entry_date', '>=', $d))
            ->when($filters['to'] ?? null, fn ($w, $d) => $w->where('entry_date', '<=', $d))
            ->when($q !== '', fn ($w) => $w->where(fn ($o) => $o->where('title', 'ilike', '%'.addcslashes($q, '%_\\').'%')->orWhere('content', 'ilike', '%'.addcslashes($q, '%_\\').'%')
                ->orWhere('tags', 'ilike', '%'.addcslashes($q, '%_\\').'%')))
            ->orderByDesc('entry_date')->orderByDesc('created_at');
    }

    /** Tags as Heratio keeps them: a comma-separated list, each trimmed, without repeats. */
    public static function tags(?string $tags): ?string
    {
        $list = collect(explode(',', (string) $tags))->map(fn ($t) => trim($t))->filter()->unique()->take(50)->implode(', ');

        return $list === '' ? null : mb_substr($list, 0, 500);
    }

    private function type(string $notation): string
    {
        return Concept::where('iri', self::TYPES.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
