<?php

// OpenRiC Workspace - the park queue (roadmap phase 17b2), after Heratio's ParkQueueService: mentions set aside with a
// reason, filtered by who parked them, kind, a new candidate, when and why; taken up again, with their candidates
// generated and scored afresh; and scanned for a new candidate since they were parked, which flags them.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Authority;

use App\Models\AuthorityMention;
use App\Models\AuthorityPark;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ParkQueue
{
    public function __construct(private Candidates $candidates, private Scorer $scorer) {}

    /** @param  array{parked_by?: ?string, kind?: ?string, new_only?: bool, since?: ?string, reason?: ?string, sort?: ?string}  $filters */
    public function list(string $institutionId, array $filters = []): LengthAwarePaginator
    {
        return AuthorityPark::whereHas('mention', fn ($q) => $q->where('institution_id', $institutionId))
            ->with(['mention' => fn ($q) => $q->withCount('candidates'), 'mention.proposal.kind', 'mention.proposal.agentType', 'mention.record', 'parkedBy'])
            ->when($filters['parked_by'] ?? null, fn ($q, $id) => $q->where('parked_by_id', $id))
            ->when(($filters['kind'] ?? '') === 'place', fn ($q) => $q->whereHas('mention.proposal.kind', fn ($k) => $k->where('notation', 'place')))
            ->when(($filters['kind'] ?? '') === 'agent', fn ($q) => $q->whereHas('mention.proposal.kind', fn ($k) => $k->where('notation', 'agent')))
            ->when($filters['new_only'] ?? false, fn ($q) => $q->where('new_candidate_available', true))
            ->when($filters['since'] ?? null, fn ($q, $d) => $q->where('parked_at', '>=', $d))
            ->when($filters['reason'] ?? null, fn ($q, $r) => $q->where('reason', 'ilike', '%'.addcslashes($r, '%_\\').'%'))
            ->when(($filters['sort'] ?? '') === 'oldest', fn ($q) => $q->orderBy('parked_at'), fn ($q) => $q->when(($filters['sort'] ?? '') === 'new', fn ($q) => $q->orderByDesc('new_candidate_available'))->orderByDesc('parked_at'))
            ->paginate(50)->withQueryString();
    }

    /** @return array<string, int> user id => mentions they parked */
    public function countsByArchivist(string $institutionId): array
    {
        return AuthorityPark::whereHas('mention', fn ($q) => $q->where('institution_id', $institutionId))->selectRaw('parked_by_id, count(*) AS n')
            ->groupBy('parked_by_id')->pluck('n', 'parked_by_id')->map(fn ($n) => (int) $n)->all();
    }

    /** Take a parked mention up again: back to waiting, its candidates found and scored afresh. */
    public function unpark(AuthorityMention $mention, User $user): int
    {
        DB::transaction(function () use ($mention) {
            AuthorityPark::where('mention_id', $mention->id)->delete();
            $mention->update(['state_id' => Mentions::state('pending')]);
        });
        $this->candidates->generate($mention);

        return $this->scorer->scoreAll($mention->fresh());
    }

    /** Look again for every parked mention's candidates, and flag those with a candidate that was not there before. @return int newly flagged */
    public function scan(?string $institutionId = null): int
    {
        $flagged = 0;
        AuthorityPark::with('mention.proposal.kind', 'mention.proposal.agentType')->when($institutionId, fn ($q, $id) => $q->whereHas('mention', fn ($m) => $m->where('institution_id', $id)))
            ->get()->each(function (AuthorityPark $park) use (&$flagged) {
                $this->candidates->generate($park->mention);
                $changed = self::fingerprint($park->mention) !== (string) $park->fingerprint;
                $changed && ! $park->new_candidate_available && $flagged++;
                $park->update(['new_candidate_available' => $changed, 'checked_at' => now()]);
            });

        return $flagged;
    }

    /** The mention's candidates as a sorted list, to tell when one is new. */
    public static function fingerprint(AuthorityMention $mention): string
    {
        return $mention->candidates()->get()->map(fn ($c) => $c->candidate_table.'|'.$c->candidate_id.'|'.$c->display_name)->sort()->join("\n");
    }
}
