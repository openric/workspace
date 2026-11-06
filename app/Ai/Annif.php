<?php

// OpenRiC Workspace - subject suggestions by Annif (NatLibFi/Annif, Apache 2.0; roadmap phase 17e, Dr Johan Pieterse's
// choice of 3 November 2026), through the AI gateway's annif route only (gateway issue ArchiveHeritageGroup/ahg-ai#4):
// the text of a record (its title, scope and content and the transcripts of its pages) is sent to the institution's
// Annif project; each suggestion that is a concept of the institution's chosen vocabulary, and not already a subject
// of the record, waits in the review queue as a proposed subject access point with its score and the receipt of the
// call (ADR 0007); the subjects reviewers accept are taught back to the project; and a trial against the subjects
// published records already have measures how far to trust it.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Ai;

use App\Api\PublicCatalogue;
use App\Audit\AuditLog;
use App\Models\AiSetting;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\Proposal;
use App\Models\RecordResource;
use App\Models\Relation;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class Annif
{
    private const MAX_TEXT = 20000;

    private const TRIAL_RECORDS = 50;

    public function __construct(private Gateway $gateway) {}

    /** Whether the institution has AI on and an Annif project and vocabulary chosen. */
    public function configured(string $institutionId): bool
    {
        $s = $this->gateway->settings($institutionId);

        return $this->gateway->available($institutionId) && $s->annif_project && $s->annif_vocabulary_id;
    }

    /** What Annif suggests for a text, the concepts of the chosen vocabulary only, best first. @return list<array{concept: Concept, score: float}> */
    public function suggest(Institution $institution, string $text, ?RecordResource $subject = null, ?User $user = null, ?int $limit = null): array
    {
        $s = $this->settings($institution);
        $call = $this->gateway->call($institution, 'annif', 'annif/v1/projects/'.rawurlencode($s->annif_project).'/suggest',
            ['text' => mb_substr($text, 0, self::MAX_TEXT), 'limit' => $limit ?? $s->annif_limit, 'threshold' => $s->annif_threshold],
            ['subject' => $subject, 'field' => 'subject', 'user' => $user, 'input' => $text, 'input_excerpt' => Str::limit($text, 400), 'model' => 'annif:'.$s->annif_project],
            fn (array $answer) => ['output' => json_encode($answer['results'] ?? []), 'model' => 'annif:'.$s->annif_project]);
        $results = collect($call['answer']['results'] ?? [])->filter(fn ($r) => is_array($r) && is_string($r['uri'] ?? null));
        $concepts = Concept::where('vocabulary_id', $s->annif_vocabulary_id)->whereNull('deprecated_at')->whereIn('iri', $results->pluck('uri'))->with('labels')->get()->keyBy('iri');

        return [...$results->filter(fn ($r) => $concepts->has($r['uri']))->sortByDesc('score')
            ->map(fn ($r) => ['concept' => $concepts[$r['uri']], 'score' => round((float) ($r['score'] ?? 0), 4), 'inference_id' => $call['inference']->id])->values()->all()];
    }

    /** Ask for a record's subjects; each new one waits for review. @return int the proposals made */
    public function propose(RecordResource $record, User $user): int
    {
        $institution = Institution::findOrFail($record->institution_id);
        ($text = $this->text($record)) !== '' || throw new InvalidArgumentException(__('There is nothing to suggest subjects from: describe its scope and content, or read the text of its pages.'));
        $have = $this->subjectsOf($record);
        $kind = Concept::where('iri', Vocab::PROPOSAL_KINDS.'subject')->valueOrFail('id');
        $proposed = Concept::where('iri', Vocab::ASSERTION_STATUSES.'proposed')->valueOrFail('id');
        $made = 0;
        $project = $this->settings($institution)->annif_project;
        foreach ($this->suggest($institution, $text, $record, $user) as $s) {
            $concept = $s['concept'];
            $waiting = Proposal::where(['record_resource_id' => $record->id, 'kind_id' => $kind, 'linked_id' => $concept->id])
                ->whereHas('status', fn ($q) => $q->whereIn('iri', [Vocab::ASSERTION_STATUSES.'proposed', Vocab::ASSERTION_STATUSES.'rejected']))->exists();
            if (in_array($concept->id, $have, true) || $waiting) {
                continue;
            }
            DB::transaction(fn () => AuditLog::actingAs($user, fn () => Proposal::create(['institution_id' => $record->institution_id, 'record_resource_id' => $record->id,
                'kind_id' => $kind, 'status_id' => $proposed, 'value' => $concept->anyLabel([$record->originalLanguage('title'), 'en', 'und']), 'linked_table' => 'concepts', 'linked_id' => $concept->id,
                'confidence' => $s['score'], 'evidence' => __('Suggested by Annif (project :project) with a score of :score, from the record\'s description and the transcripts of its pages.',
                    ['project' => $project, 'score' => number_format($s['score'], 2)]),
                'origin_label' => 'ai-annif', 'proposed_by_id' => $user->id, 'inference_id' => $s['inference_id']])));
            $made++;
        }

        return $made;
    }

    /**
     * Teach the project the subjects reviewers accepted since it was last taught, with the text they were accepted
     * for (Annif's learn, where its backend learns). @return int the documents sent
     */
    public function learn(Institution $institution): int
    {
        $s = $this->settings($institution);
        $accepted = Proposal::where('institution_id', $institution->id)->where('origin_label', 'ai-annif')
            ->whereHas('status', fn ($q) => $q->where('iri', Vocab::ASSERTION_STATUSES.'accepted'))
            ->when($s->annif_learned_at, fn ($q, $at) => $q->where('reviewed_at', '>', $at))->with('record')->get()->groupBy('record_resource_id');
        if ($accepted->isEmpty()) {
            return 0;
        }
        $since = now();
        $documents = $accepted->map(fn (Collection $ps) => ['text' => mb_substr($this->text($ps->first()->record), 0, self::MAX_TEXT),
            'subjects' => $ps->map(fn (Proposal $p) => ['uri' => Concept::find($p->linked_id)?->iri, 'label' => $p->value])->filter(fn ($x) => $x['uri'])->values()->all()])
            ->filter(fn ($d) => $d['text'] !== '' && $d['subjects'] !== [])->values()->all();
        $this->gateway->call($institution, 'annif', 'annif/v1/projects/'.rawurlencode($s->annif_project).'/learn', ['documents' => $documents],
            ['field' => 'subject-learn', 'input' => json_encode($documents), 'model' => 'annif:'.$s->annif_project],
            fn (array $answer) => ['output' => json_encode($answer), 'model' => 'annif:'.$s->annif_project]);
        $s->update(['annif_learned_at' => $since]);

        return count($documents);
    }

    /**
     * Try the project on published records that already have subjects of the vocabulary: of the first $k it suggests,
     * how many are subjects the record has (precision), and of those it has, how many it suggested (recall). Kept.
     */
    public function trial(Institution $institution, ?User $user = null, int $k = 5): array
    {
        $s = $this->settings($institution);
        $catalogue = new PublicCatalogue($institution);
        $subjectType = Concept::where('iri', Vocab::BASE.'relation-type/subject')->value('id');
        $records = Relation::where(['relation_type_id' => $subjectType, 'source_table' => 'record_resources', 'target_table' => 'concepts'])
            ->whereIn('target_id', Concept::where('vocabulary_id', $s->annif_vocabulary_id)->select('id'))->distinct()->limit(self::TRIAL_RECORDS * 3)->pluck('source_id');
        $rows = [];
        foreach (RecordResource::whereIn('id', $records)->get() as $record) {
            if (count($rows) >= self::TRIAL_RECORDS || $catalogue->find('records', $record->id) === null || ($text = $this->text($record)) === '') {
                continue;
            }
            $gold = array_values(array_filter($this->subjectsOf($record), fn ($id) => Concept::where(['id' => $id, 'vocabulary_id' => $s->annif_vocabulary_id])->exists()));
            $got = array_map(fn ($x) => $x['concept']->id, array_slice($this->suggest($institution, $text, $record, $user, $k), 0, $k));
            $hits = count(array_intersect($got, $gold));
            $rows[] = ['record' => $record->id, 'gold' => count($gold), 'suggested' => count($got), 'hits' => $hits];
        }
        $suggested = array_sum(array_column($rows, 'suggested'));
        $gold = array_sum(array_column($rows, 'gold'));
        $hits = array_sum(array_column($rows, 'hits'));

        return DB::table('annif_trials')->insert(['id' => (string) Str::uuid7(), 'institution_id' => $institution->id, 'project' => $s->annif_project, 'records' => count($rows), 'k' => $k,
            'precision' => $suggested ? round($hits / $suggested, 4) : 0, 'recall' => $gold ? round($hits / $gold, 4) : 0, 'details' => json_encode($rows),
            'run_by_id' => $user?->id, 'run_at' => now()]) ? ['records' => count($rows), 'precision' => $suggested ? $hits / $suggested : 0.0, 'recall' => $gold ? $hits / $gold : 0.0] : [];
    }

    /** What a record is about, in words: its title, its scope and content, and the transcripts of its pages. */
    public function text(RecordResource $record): string
    {
        $language = $record->originalLanguage('title');
        $pages = $record->instantiations()->whereNull('deleted_at')->get()->map(fn ($i) => trim((string) $i->described('instantiation-transcript', [$language, 'en', 'af'])))->filter();

        return trim(implode("\n\n", array_filter([(string) $record->described('title', [$language, 'en']), (string) $record->described('scope-and-content', [$language, 'en']), ...$pages->all()])));
    }

    /** @return list<string> the concepts the record has as subjects, not rejected */
    private function subjectsOf(RecordResource $record): array
    {
        return Relation::whereHas('relationType', fn ($q) => $q->where('iri', Vocab::BASE.'relation-type/subject'))
            ->where(['source_table' => 'record_resources', 'source_id' => $record->id, 'target_table' => 'concepts'])->with('assertions.status')->get()
            ->filter(fn (Relation $r) => $r->standing() !== 'rejected')->pluck('target_id')->all();
    }

    private function settings(Institution $institution): AiSetting
    {
        $s = $this->gateway->settings($institution->id);
        $s->annif_project && $s->annif_vocabulary_id || throw new InvalidArgumentException(__('Choose the Annif project and the vocabulary it suggests from under Admin, AI.'));

        return $s;
    }
}
