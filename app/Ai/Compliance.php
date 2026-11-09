<?php

// OpenRiC Workspace - AI compliance (roadmap phase 17f), after Heratio's ahg-ai-compliance (EU AI Act articles 9 and 14,
// Annex IV): the register of an institution's AI systems, one for each AI service the Workspace may use, kept with
// Heratio's fields and filled at first with what the Workspace does (every output but the assistant's a proposal a person
// reviews); halting a system, which the AI gateway client then refuses to call; the models seen in the log of inferences;
// the oversight a system is under, shown from the proposals its outputs became and what reviewers did with them; the
// attestations of reviewers; a nightly monitor that raises an incident when reviewers reject much of what a system
// proposes, or readers find the assistant's answers wrong; and a system's technical documentation after Annex IV.
// For a South African institution the same register serves POPIA's accountability principle (section 8).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Ai;

use App\Audit\AuditLog;
use App\Models\AiAttestation;
use App\Models\AiIncident;
use App\Models\AiModel;
use App\Models\AiSystem;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class Compliance
{
    /** The attestation reviewers give, and how long it holds (Heratio's ai_operator_attestation, a year). */
    public const ATTESTATION_VERSION = '2026-11';

    public const ATTESTATION_DAYS = 365;

    /** Reviewers rejecting more than this share of a system's proposals, over at least MONITOR_MINIMUM decisions in 30 days, is an incident. */
    public const MONITOR_REJECTION = 0.5;

    public const MONITOR_MINIMUM = 20;

    /** Answers of the assistant reviewers find wrong, in 30 days, that make an incident. */
    public const MONITOR_WRONG_ANSWERS = 3;

    private const REVIEWED = 'Every output is a proposal in the review queue: a person accepts or rejects it before it changes a record (ADR 0007). Each call is kept in the chained, signed log of inferences.';

    /** What the Workspace does with each service, as the register starts: name, purpose, risk class, oversight. */
    private const DEFAULTS = [
        'ocr' => ['Reading printed pages', 'Proposes the text of a scanned page, read by a vision model and checked against Tesseract.', 'minimal'],
        'htr' => ['Reading handwriting', 'Proposes the text of a handwritten page.', 'minimal'],
        'ner' => ['Finding names', 'Proposes the people, organisations and places a description names, and candidates among the authority records.', 'minimal'],
        'summarize' => ['Summarising', 'Proposes a summary of a description and the text of its pages, as its scope and content.', 'minimal'],
        'translate' => ['Translating', 'Proposes a description\'s values in another language.', 'minimal'],
        'embed' => ['Search by meaning', 'Turns the search index into vectors, so a search finds records by meaning as well as by words. It ranks results; it decides nothing.', 'minimal'],
        'chat' => ['Language model', 'Writes the summaries, condition assessments, search widening and research leads the other services propose.', 'minimal'],
        'annif' => ['Suggesting subjects', 'Proposes subjects from a vocabulary for a record, with Annif.', 'minimal'],
        'condition' => ['Assessing condition', 'Proposes a condition grade for an object from its picture, with the damage it sees as evidence.', 'minimal'],
        'chatbot' => ['The catalogue\'s assistant', 'Answers a reader\'s questions about the collection from the records they may read, citing them.', 'limited'],
    ];

    /** The institution's register, a system made for every AI service it does not have one for yet. */
    public function systems(Institution $institution): Collection
    {
        $services = Concept::choices(Vocab::BASE.'ai-service/')->get();
        $have = AiSystem::where('institution_id', $institution->id)->pluck('service_id')->flip();
        foreach ($services as $service) {
            if (! isset($have[$service->id])) {
                [$name, $purpose, $class] = self::DEFAULTS[$service->notation] ?? [(string) $service->anyLabel(['en']), null, 'minimal'];
                AiSystem::create(['institution_id' => $institution->id, 'service_id' => $service->id, 'name' => $name, 'purpose' => $purpose,
                    'provider' => __('The institution, through the AHG AI gateway'), 'role_id' => $this->concept('ai-role', 'deployer'),
                    'risk_class_id' => $this->concept('ai-risk-class', $class), 'lifecycle_id' => $this->concept('ai-lifecycle', 'deployed'),
                    'requires_human_review' => $service->notation !== 'chatbot',
                    'human_oversight' => $service->notation === 'chatbot'
                        ? 'Answers go to the reader directly, marked as written by AI and citing the records they rest on, never anything the reader may not read. A reader may flag an answer as wrong; flagged answers are reviewed. Each call is kept in the chained, signed log of inferences.'
                        : self::REVIEWED]);
            }
        }

        return AiSystem::where('institution_id', $institution->id)->with('service.labels', 'riskClass.labels', 'lifecycle.labels', 'role.labels')->get()
            ->sortBy(fn (AiSystem $s) => $s->service->notation)->values();
    }

    /** Whether the institution halted the system that calls this service. */
    public function halted(string $institutionId, string $service): bool
    {
        return AiSystem::where('institution_id', $institutionId)->whereNotNull('halted_at')
            ->whereHas('service', fn ($q) => $q->where('iri', Vocab::BASE.'ai-service/'.$service))->exists();
    }

    public function halt(AiSystem $system, string $reason, User $actor): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $system->update(['halted_at' => now(), 'halted_reason' => mb_substr($reason, 0, 255), 'halted_by_id' => $actor->id])));
    }

    public function resume(AiSystem $system, User $actor): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $system->update(['halted_at' => null, 'halted_reason' => null, 'halted_by_id' => null])));
    }

    /** The models seen in the log of inferences, brought up to date (Heratio's model registry, filled from use). */
    public function refreshModels(Institution $institution): Collection
    {
        $seen = DB::table('ai_inferences')->where('institution_id', $institution->id)->where('succeeded', true)
            ->groupBy('service_id', 'model', 'model_version')
            ->selectRaw('service_id, model, model_version, min(occurred_at) AS first_seen, max(occurred_at) AS last_seen, count(*) AS calls')->get();
        foreach ($seen as $row) {
            $model = AiModel::firstOrNew(['institution_id' => $institution->id, 'service_id' => $row->service_id, 'model' => $row->model, 'model_version' => $row->model_version]);
            $model->fill(['first_seen_at' => $row->first_seen, 'last_seen_at' => $row->last_seen, 'calls' => $row->calls])->save();
        }

        return AiModel::where('institution_id', $institution->id)->with('service.labels')->orderByDesc('last_seen_at')->get();
    }

    /**
     * How reviewers dealt with each service's proposals, over the last $days days: service notation => proposed,
     * accepted, rejected; and the assistant's answers flagged and found wrong.
     *
     * @return array<string, array{proposed: int, accepted: int, rejected: int}>
     */
    public function oversight(Institution $institution, int $days = 30): array
    {
        $rows = DB::table('proposals as p')->join('ai_inferences as i', 'i.id', '=', 'p.inference_id')
            ->join('concepts as s', 's.id', '=', 'i.service_id')->join('concepts as st', 'st.id', '=', 'p.status_id')
            ->where('p.institution_id', $institution->id)->where('p.created_at', '>=', now()->subDays($days))
            ->groupBy('s.notation', 'st.notation')->selectRaw('s.notation AS service, st.notation AS status, count(*) AS n')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[$r->service] ??= ['proposed' => 0, 'accepted' => 0, 'rejected' => 0];
            if (isset($out[$r->service][$r->status])) {
                $out[$r->service][$r->status] = (int) $r->n;
            }
        }
        $chat = DB::table('chat_messages as m')->join('chat_conversations as c', 'c.id', '=', 'm.conversation_id')->where('c.institution_id', $institution->id)
            ->whereNotNull('m.flagged_at')->where('m.flagged_at', '>=', now()->subDays($days))
            ->leftJoin('concepts as r', 'r.id', '=', 'm.review_id')->groupBy('r.notation')->selectRaw('r.notation AS review, count(*) AS n')->pluck('n', 'review');
        $out['chatbot'] = ['proposed' => (int) ($chat['pending'] ?? 0), 'accepted' => (int) ($chat['right'] ?? 0), 'rejected' => (int) ($chat['wrong'] ?? 0)];

        return $out;
    }

    /** The reviewer's attestation that holds now, if any. */
    public function attestation(User $user): ?AiAttestation
    {
        return AiAttestation::where('user_id', $user->id)->where('version', self::ATTESTATION_VERSION)->where('expires_at', '>', now())->latest('attested_at')->first();
    }

    public function attest(User $user): AiAttestation
    {
        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => AiAttestation::create(['institution_id' => $user->institution_id, 'user_id' => $user->id,
            'version' => self::ATTESTATION_VERSION, 'attested_at' => now(), 'expires_at' => now()->addDays(self::ATTESTATION_DAYS)])));
    }

    /**
     * Heratio's risk monitor: an incident raised for a system whose proposals reviewers reject more than half the time
     * (over at least twenty decisions in thirty days), or whose answers readers found wrong three times, unless one the
     * monitor raised is still open. Returns the incidents raised.
     *
     * @return list<AiIncident>
     */
    public function monitor(Institution $institution): array
    {
        $raised = [];
        $oversight = $this->oversight($institution);
        foreach ($this->systems($institution) as $system) {
            $o = $oversight[$system->service->notation] ?? ['accepted' => 0, 'rejected' => 0];
            $decided = $o['accepted'] + $o['rejected'];
            $reason = match (true) {
                $system->service->notation === 'chatbot' && $o['rejected'] >= self::MONITOR_WRONG_ANSWERS => __(':n answers of the assistant were found wrong in the last 30 days.', ['n' => $o['rejected']]),
                $system->service->notation !== 'chatbot' && $decided >= self::MONITOR_MINIMUM && $o['rejected'] / $decided > self::MONITOR_REJECTION => __('Reviewers rejected :rejected of :decided of its proposals in the last 30 days.', ['rejected' => $o['rejected'], 'decided' => $decided]),
                default => null,
            };
            if ($reason === null || AiIncident::where('system_id', $system->id)->where('raised_by', 'monitor')->whereNull('resolved_at')->exists()) {
                continue;
            }
            $raised[] = DB::transaction(fn () => AuditLog::actingAs(null, fn () => AiIncident::create(['institution_id' => $institution->id, 'system_id' => $system->id,
                'description' => $reason, 'severity_id' => $this->concept('risk-level', 'medium'), 'raised_by' => 'monitor'])));
        }

        return $raised;
    }

    /**
     * A system's technical documentation, after the EU AI Act's Annex IV, as sections of title and text.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function annexIv(AiSystem $system): array
    {
        $system->loadMissing('service', 'riskClass.labels', 'lifecycle.labels', 'role.labels', 'risks.severity.labels', 'risks.status.labels', 'incidents');
        $models = AiModel::where('institution_id', $system->institution_id)->where('service_id', $system->service_id)->orderByDesc('last_seen_at')->get();
        $o = $this->oversight(Institution::findOrFail($system->institution_id))[$system->service->notation] ?? ['proposed' => 0, 'accepted' => 0, 'rejected' => 0];
        $lang = ['en'];

        return [
            [__('1. General description'), implode("\n", array_filter([$system->name, $system->description, __('Intended purpose: :p', ['p' => $system->purpose ?? '-']),
                __('Provider: :p', ['p' => $system->provider ?? '-']), __('Role of the institution: :r', ['r' => $system->role->label($lang)]),
                __('Risk class: :c', ['c' => $system->riskClass->label($lang)]), __('Lifecycle: :l', ['l' => $system->lifecycle->label($lang)]),
                __('Accountable: :o', ['o' => $system->owner ?? '-'])]))],
            [__('2. Elements and development'), $models->isEmpty() ? __('No model has answered yet.') : $models->map(fn (AiModel $m) => '- '.$m->model.' ('.$m->model_version.'): '
                .__(':n calls from :first to :last', ['n' => $m->calls, 'first' => $m->first_seen_at->toDateString(), 'last' => $m->last_seen_at->toDateString()])
                .($m->known_limits ? '. '.__('Known limits: :k', ['k' => $m->known_limits]) : '').($m->training_data_summary ? '. '.__('Training data: :t', ['t' => $m->training_data_summary]) : ''))->join("\n")
                ."\n".__('Every call goes through the AHG AI gateway, which holds the models; the Workspace sends the text or picture asked about and keeps what came back.')],
            [__('3. Monitoring, functioning and control'), implode("\n", array_filter([$system->human_oversight, $system->requires_human_review ? __('A person reviews every output before it changes a record.') : null,
                $system->dual_review_required ? __('Two people review each output.') : null,
                __('In the last 30 days: :p waiting, :a accepted, :r rejected.', ['p' => $o['proposed'], 'a' => $o['accepted'], 'r' => $o['rejected']]),
                $system->halted_at ? __('Halted since :d: :reason', ['d' => $system->halted_at->toDateString(), 'reason' => $system->halted_reason]) : null]))],
            [__('4. Risk management (Article 9)'), $system->risks->isEmpty() ? __('No risk recorded yet.') : $system->risks->map(fn ($r) => '- '.$r->description.' ('
                .__('severity :s, :status', ['s' => $r->severity->label($lang), 'status' => $r->status->label($lang)]).')'.($r->mitigation ? ': '.$r->mitigation : ''))->join("\n")],
            [__('5. Incidents'), $system->incidents->isEmpty() ? __('None.') : $system->incidents->map(fn ($i) => '- '.$i->created_at->toDateString().': '.$i->description
                .($i->resolved_at ? ' ('.__('resolved :d', ['d' => $i->resolved_at->toDateString()]).')' : ''))->join("\n")],
            [__('6. Record-keeping (Article 12)'), __('Every call is kept in the Workspace\'s log of inferences: the service, model and version, a hash of what was sent and of what came back, the time, the account and the record concerned, each entry chained to the one before and signed (php artisan workspace:ai:verify checks the chain).')],
        ];
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE."$vocabulary/$notation")->value('id') ?? throw new RuntimeException("$vocabulary/$notation is not loaded");
    }
}
