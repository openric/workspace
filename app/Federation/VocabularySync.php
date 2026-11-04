<?php

// OpenRiC Workspace - vocabularies synchronised with federation peers (roadmap phase 16c), after Heratio's ahg-federation
// VocabSyncService: an institution offers the vocabularies it chooses to share, as SKOS Turtle at
// /federation/vocabularies/{id}.ttl with the changes made to each since a time; and it takes a peer's vocabulary into
// one of its own, on the sync's interval or when asked, through the same SKOS import every local vocabulary has. A
// concept taken in gets the institution's IRI and keeps the peer's as an exact match, which is Heratio's term mapping;
// one the institution has already keeps its labels (keep ours) or takes the peer's (take theirs). Each run is kept.
// Changed from Heratio: a vocabulary is offered only where the institution names it; the peer must be at a public address.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Federation;

use App\Models\Concept;
use App\Models\FederationShareSetting;
use App\Models\Institution;
use App\Models\User;
use App\Models\Vocabulary;
use App\Support\Vocab;
use App\Vocabulary\LocalSkos;
use App\Webhooks\Webhooks;
use EasyRdf\Graph;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class VocabularySync
{
    public function __construct(private LocalSkos $skos) {}

    /** @return Collection<int, Vocabulary> the institution's own vocabularies it shares with peers */
    public function shared(Institution $institution)
    {
        $share = FederationShareSetting::for($institution->id);
        $ids = $share->share_enabled ? array_values(array_filter((array) $share->shared_vocabularies)) : [];

        return $ids === [] ? collect() : Vocabulary::where('institution_id', $institution->id)->whereIn('id', $ids)->orderBy('title')->get();
    }

    /** One sync: the peer's vocabulary taken into the institution's own; the log row of it. */
    public function run(object $sync, ?User $actor = null): object
    {
        $peer = DB::table('federation_peers')->find($sync->peer_id);
        $vocabulary = Vocabulary::findOrFail($sync->vocabulary_id);
        $rule = Concept::find($sync->conflict_rule_id)->notation;
        $log = (string) Str::uuid7();
        DB::table('federation_vocabulary_sync_log')->insert(['id' => $log, 'sync_id' => $sync->id, 'status_id' => $this->concept('harvest-status', 'running'),
            'initiated_by_id' => $actor?->id, 'started_at' => now()]);
        $counts = ['terms_added' => 0, 'terms_updated' => 0, 'terms_skipped' => 0, 'conflicts' => 0];
        $error = null;
        try {
            $vocabulary->institution_id !== null || throw new InvalidArgumentException(__('A peer\'s vocabulary is taken only into one of the institution\'s own.'));
            $url = rtrim((string) $peer->base_url, '/').'/federation/vocabularies/'.rawurlencode($sync->remote_vocabulary).'.ttl';
            ($peer->base_url && Webhooks::publicUrl($url)) || throw new RuntimeException(__('Its address is not a public one.'));
            $response = Http::timeout(60)->withHeaders(['Accept' => 'text/turtle'])->withoutRedirecting()->get($url);
            $response->successful() || throw new RuntimeException(__('The peer answered HTTP :status.', ['status' => $response->status()]));
            $turtle = $response->body();
            $graph = new Graph;
            $graph->parse($turtle, 'turtle');
            $concepts = $graph->allOfType('skos:Concept');
            $known = array_filter(array_map(fn ($c) => $this->skos->existing($vocabulary, $c->getUri()) ? $c->getUri() : null, $concepts));
            if ($rule === 'prefer-local' && $known !== []) {
                // Keep ours: only the concepts the institution does not have come in.
                $counts['conflicts'] = count($known);
                $counts['terms_skipped'] = count($known);
                $data = $graph->toRdfPhp();
                foreach ($known as $iri) {
                    unset($data[$iri]);
                }
                $kept = new Graph;
                $kept->parse($data, 'php');
                $turtle = $kept->allOfType('skos:Concept') === [] ? null : $kept->serialise('turtle');
            }
            if ($turtle !== null) {
                $done = $this->skos->import($vocabulary, $turtle, 'turtle', $actor);
                $counts['terms_added'] = $done['created'];
                $counts['terms_updated'] = $rule === 'prefer-local' ? 0 : $done['updated'];
                $counts['conflicts'] = $rule === 'prefer-local' ? $counts['conflicts'] : count($known);
            }
        } catch (Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 2000);
        }
        $status = $error === null ? 'success' : 'failed';
        DB::table('federation_vocabulary_sync_log')->where('id', $log)->update([...$counts, 'status_id' => $this->concept('harvest-status', $status), 'error_message' => $error, 'completed_at' => now()]);
        DB::table('federation_vocabulary_syncs')->where('id', $sync->id)->update(['last_sync_at' => now(), 'last_status_id' => $this->concept('harvest-status', $status),
            'last_terms_added' => $counts['terms_added'], 'last_terms_updated' => $counts['terms_updated'], 'last_conflicts' => $counts['conflicts'], 'updated_at' => now()]);

        return DB::table('federation_vocabulary_sync_log')->find($log);
    }

    /** Sync each vocabulary whose interval has passed. The number synced. */
    public function runDue(): int
    {
        $pull = Concept::where('iri', 'like', Vocab::BASE.'vocabulary-sync-direction/%')->whereIn('notation', ['pull', 'both'])->pluck('id');
        $due = DB::table('federation_vocabulary_syncs')->where('sync_enabled', true)->whereNotNull('sync_interval_hours')->whereIn('direction_id', $pull)->get()
            ->filter(fn ($s) => ! $s->last_sync_at || now()->parse($s->last_sync_at)->addHours($s->sync_interval_hours)->isPast());
        $due->each(fn ($s) => $this->run($s));

        return $due->count();
    }

    /** A change to a concept of a vocabulary the institution shares, for peers to see (Heratio's recordChange). */
    public static function recordChange(Concept $concept, string $type, ?string $old, ?string $new): void
    {
        $vocabulary = $concept->vocabulary;
        if ($vocabulary?->institution_id === null) {
            return;
        }
        $share = FederationShareSetting::where('institution_id', $vocabulary->institution_id)->first();
        if (! $share || ! in_array($vocabulary->id, (array) $share->shared_vocabularies, true)) {
            return;
        }
        DB::table('federation_vocabulary_changes')->insert(['id' => (string) Str::uuid7(), 'vocabulary_id' => $vocabulary->id, 'concept_id' => $concept->id, 'concept_iri' => $concept->iri,
            'change_type_id' => Concept::where('iri', Vocab::BASE.'vocabulary-change-type/'.$type)->value('id'), 'old_value' => $old, 'new_value' => $new,
            'created_by_id' => auth()->id(), 'created_at' => now()]);
    }

    /** @return list<array<string, mixed>> the changes to a shared vocabulary since a time */
    public function changes(Vocabulary $vocabulary, ?string $since): array
    {
        return DB::table('federation_vocabulary_changes')->join('concepts', 'concepts.id', '=', 'federation_vocabulary_changes.change_type_id')
            ->where('federation_vocabulary_changes.vocabulary_id', $vocabulary->id)
            ->when($since && strtotime($since), fn ($q) => $q->where('federation_vocabulary_changes.created_at', '>', date('c', strtotime($since))))
            ->orderBy('federation_vocabulary_changes.created_at')->limit(5000)
            ->get(['federation_vocabulary_changes.concept_iri', 'concepts.notation as change', 'federation_vocabulary_changes.old_value', 'federation_vocabulary_changes.new_value', 'federation_vocabulary_changes.created_at'])
            ->map(fn ($r) => (array) $r)->all();
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE."$vocabulary/$notation")->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
