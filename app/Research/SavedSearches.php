<?php

// OpenRiC Workspace - saved searches (roadmap phase 19c, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ResearchSavedSearchesController (store, snapshot, diff, run, destroy) and research_saved_search
// and research_search_alert_log: a search of the Workspace's index (its words and filters) is kept with a name; it
// runs again; a snapshot of its results is taken, and what came and went since is shown. Heratio keeps whether a
// search alerts and how often but sends nothing; here a daily run takes each search whose turn has come and tells
// its owner, through the Outbox, of the results that are new, logging each run. A search runs with its owner's
// access: the public index for who may not see drafts, and only what they may read.

namespace App\Research;

use App\Audit\AuditLog;
use App\Mail\Outbox;
use App\Mail\ResearchMail;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\SavedSearch;
use App\Models\SearchAlertLogEntry;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Search\SearchIndex;
use App\Search\SearchService;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SavedSearches
{
    /** Results kept in a snapshot, at most. */
    private const SNAPSHOT_MAX = 2000;

    private const DAYS = ['daily' => 1, 'weekly' => 7, 'monthly' => 30];

    public function __construct(private SearchService $search, private Outbox $outbox) {}

    public function save(User $user, array $data, ?SavedSearch $search = null): SavedSearch
    {
        if ($search) {
            $this->mustOwn($user, $search);
        }
        if (trim((string) ($data['search_query'] ?? '')) === '' || trim((string) ($data['name'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give the search a name and its words.'));
        }
        $filters = array_filter(array_intersect_key((array) ($data['search_filters'] ?? []), array_flip(['kind', 'level', 'repository', 'from', 'to'])), fn ($v) => $v !== null && $v !== '');
        $frequency = ! empty($data['alert_enabled']) ? $this->concept('alert-frequency', $data['alert_frequency'] ?? 'weekly') : null;

        return DB::transaction(fn () => AuditLog::actingAs($user, function () use ($user, $search, $data, $filters, $frequency) {
            $fields = ['name' => trim($data['name']), 'description' => trim((string) ($data['description'] ?? '')) ?: null, 'search_query' => trim($data['search_query']),
                'search_filters' => $filters, 'alert_enabled' => $frequency !== null, 'alert_frequency_id' => $frequency];
            if ($search) {
                $search->update($fields);

                return $search;
            }
            $search = SavedSearch::create($fields + ['institution_id' => $user->institution_id, 'user_id' => $user->id]);
            $ids = $this->results($user, $search);
            $search->update(['result_snapshot' => $ids, 'total_results_at_save' => count($ids), 'last_result_count' => count($ids), 'last_run_at' => now()]);
            app(Diary::class)->auto($user, 'auto-search', __('Saved the search ":name"', ['name' => $search->name]), $search);

            return $search;
        }));
    }

    public function delete(User $user, SavedSearch $search): void
    {
        $this->mustOwn($user, $search);
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $search->delete()));
    }

    /** The results now, as "table:id", with the owner's access; at most the snapshot's size. @return list<string> */
    public function results(User $user, SavedSearch $search): array
    {
        $public = ! $user->mayAnywhere('view-draft', 'description');
        $found = $this->search->search(Institution::find($search->institution_id), $search->search_query, $public, (array) $search->search_filters, 1, self::SNAPSHOT_MAX);
        $kinds = collect(SearchIndex::KINDS)->mapWithKeys(fn ($c) => [(new $c)->getTable() => $c]);

        return collect($found['results'])->groupBy('table')->flatMap(fn ($rows, $table) => isset($kinds[$table]) ? $kinds[$table]::whereIn('id', $rows->pluck('id'))->get() : collect())
            ->filter(fn ($entity) => EntityKinds::readable($user, $entity))->map(fn ($entity) => $entity->getTable().':'.$entity->getKey())->values()->all();
    }

    /** Heratio's searchSnapshot: keep the results now. */
    public function snapshot(User $user, SavedSearch $search): int
    {
        $this->mustOwn($user, $search);
        $ids = $this->results($user, $search);
        $search->update(['result_snapshot' => $ids, 'last_result_count' => count($ids), 'last_run_at' => now(), 'new_results_count' => 0]);

        return count($ids);
    }

    /**
     * Heratio's searchDiff: what came and went since the snapshot.
     *
     * @return array{added: list<string>, removed: list<string>, unchanged: int}
     */
    public function diff(User $user, SavedSearch $search): array
    {
        $this->mustOwn($user, $search);
        $before = (array) $search->result_snapshot;
        $now = $this->results($user, $search);
        $search->update(['last_run_at' => now()]);

        return ['added' => array_values(array_diff($now, $before)), 'removed' => array_values(array_diff($before, $now)), 'unchanged' => count(array_intersect($now, $before))];
    }

    /** The daily run: each alerting search whose turn has come tells its owner of new results. @return int how many owners were told */
    public function alert(): int
    {
        $told = 0;
        foreach (SavedSearch::where('alert_enabled', true)->with('user', 'alertFrequency')->get() as $search) {
            $days = self::DAYS[$search->alertFrequency?->notation ?? 'weekly'] ?? 7;
            if ($search->last_alert_at && $search->last_alert_at->addDays($days)->isFuture() || ! $search->user || $search->user->disabled_at !== null) {
                continue;
            }
            $before = (array) $search->result_snapshot;
            $now = $this->results($search->user, $search);
            $new = array_values(array_diff($now, $before));
            $sent = false;
            if ($new !== []) {
                $sent = $this->outbox->send($search->user, new ResearchMail($search->user, 'New results for your saved search :name', ['name' => $search->name],
                    [['Your saved search ":name" has :count new results since you last looked.', ['name' => $search->name, 'count' => count($new)]]], route('research.searches.index'), 'Open your saved searches'));
                $told += $sent ? 1 : 0;
            }
            SearchAlertLogEntry::create(['saved_search_id' => $search->id, 'previous_count' => count($before), 'new_count' => count($now), 'new_items_count' => count($new),
                'notification_sent' => $sent, 'created_at' => now()]);
            $search->update(['last_alert_at' => now(), 'new_results_count' => count($new), 'last_result_count' => count($now)]);
        }

        return $told;
    }

    private function mustOwn(User $user, SavedSearch $search): void
    {
        if ($search->user_id !== $user->id) {
            throw new InvalidArgumentException(__('That is not yours.'));
        }
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
