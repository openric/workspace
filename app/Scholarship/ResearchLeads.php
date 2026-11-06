<?php

/*
 * OpenRiC Workspace - copied from Heratio, packages/ahg-semantic-search/src/Services/ResearchLeadService.php at commit
 * bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md, roadmap phase 17d4): research leads promoted
 * from the strongest discoveries, idempotent a record, a lead never claiming more than the discovery it rests on;
 * buildHeadline, factualWhyItMatters and, on request, aiWhyItMatters (Heratio's prompt), labelled; publicFeed and
 * publicLead (published leads on published records only), the curation list, its counts, publish, dismiss and back to
 * pending; confidenceBand and the disclaimer. Changed: the statuses are vocabulary rows; a lead belongs to the
 * institution and its description, and keeps the receipt of a model's "why it matters"; published is what the public
 * catalogue shows; the namespace and code style.
 * Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Scholarship;

use App\Ai\AiUnavailable;
use App\Ai\Assist;
use App\Ai\Gateway;
use App\Api\PublicCatalogue;
use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\ResearchLead;
use App\Models\ScholarshipDiscovery;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ResearchLeads
{
    public const STATUSES = Vocab::BASE.'research-lead-status/';

    public const DISCLAIMER = 'A research lead is a starting point for enquiry, not a finding. Each lead is drawn by an AI from the catalogue\'s own verified links and is offered as a hypothesis to test against the underlying records. The AI is instructed to use only the supplied catalogue connections and never to invent people, places, dates or records, but it can still misread or overstate a link. Verify every lead against the records it cites before relying on it.';

    public function __construct(private Gateway $gateway) {}

    /**
     * Promote the institution's strongest discoveries into pending leads, refreshing those already made; with $enrich a
     * model writes each "why it matters", else it stands on the facts of the links.
     *
     * @return array{promoted: int, refreshed: int, skipped: int, enriched: int, ai_reached: bool}
     */
    public function generate(Institution $institution, int $limit = 25, bool $enrich = false, ?User $user = null): array
    {
        $out = ['promoted' => 0, 'refreshed' => 0, 'skipped' => 0, 'enriched' => 0, 'ai_reached' => false];
        $discoveries = ScholarshipDiscovery::where('institution_id', $institution->id)->orderByDesc('confidence')->orderByDesc('generated_at')->limit(max(1, min($limit, 200)))->get();
        foreach ($discoveries as $discovery) {
            $evidence = $discovery->evidence;
            $why = $this->factualWhyItMatters($discovery->connection_count, $evidence);
            $inference = null;
            if ($enrich) {
                try {
                    $call = $this->aiWhyItMatters($institution, $discovery, $user);
                    $out['ai_reached'] = true;
                    if (($ai = trim((string) preg_replace('/\s+/', ' ', $call['read']['output']))) !== '') {
                        [$why, $inference] = [mb_substr($ai, 0, 1200), $call['inference']->id];
                        $out['enriched']++;
                    }
                } catch (AiUnavailable) {
                    // The lead stands on the facts of its links.
                }
            }
            $values = ['institution_id' => $institution->id, 'discovery_id' => $discovery->id, 'headline' => trim((string) $discovery->title) ?: __('an unnamed record'),
                'lead_text' => trim((string) $discovery->summary) ?: null, 'why_it_matters' => $why, 'connection_count' => $discovery->connection_count,
                'confidence' => $discovery->confidence, 'evidence' => $evidence, 'ai_labelled' => true, 'inference_id' => $inference, 'generated_at' => now()];
            $lead = ResearchLead::where('record_resource_id', $discovery->record_resource_id)->first();
            DB::transaction(fn () => AuditLog::actingAs($user, fn () => $lead
                ? $lead->update($values)
                : ResearchLead::create([...$values, 'record_resource_id' => $discovery->record_resource_id, 'status_id' => self::status('pending'), 'curated_by_id' => $user?->id])));
            $lead ? $out['refreshed']++ : $out['promoted']++;
        }

        return $out;
    }

    /** The published leads whose records the public catalogue shows, newest first. */
    public function publicFeed(PublicCatalogue $catalogue, int $limit = 60): Collection
    {
        return ResearchLead::where(['institution_id' => $catalogue->institution->id, 'status_id' => self::status('published')])->orderByDesc('published_at')->limit($limit * 2)->get()
            ->filter(fn (ResearchLead $l) => $catalogue->find('records', $l->record_resource_id) !== null)->take($limit)->values();
    }

    public function publicLead(PublicCatalogue $catalogue, string $id): ?ResearchLead
    {
        $lead = preg_match('/^[0-9a-f-]{36}$/', $id) ? ResearchLead::where(['institution_id' => $catalogue->institution->id, 'status_id' => self::status('published')])->find($id) : null;

        return $lead && $catalogue->find('records', $lead->record_resource_id) !== null ? $lead : null;
    }

    public function list(Institution $institution, ?string $status = null): Collection
    {
        return ResearchLead::where('institution_id', $institution->id)->with('status.labels', 'record')
            ->when($status, fn ($q) => $q->where('status_id', self::status($status)))->orderByDesc('confidence')->orderByDesc('generated_at')->limit(500)->get();
    }

    /** @return array<string,int> */
    public function counts(Institution $institution): array
    {
        return ResearchLead::where('research_leads.institution_id', $institution->id)->join('concepts', 'concepts.id', '=', 'research_leads.status_id')
            ->groupBy('concepts.iri')->selectRaw('concepts.iri, count(*) AS c')->pluck('c', 'iri')
            ->mapWithKeys(fn ($c, $iri) => [substr($iri, strlen(self::STATUSES)) => (int) $c])->all();
    }

    /** Publish, dismiss, or send back to pending. */
    public function curate(ResearchLead $lead, string $status, User $curator): void
    {
        in_array($status, ['published', 'dismissed', 'pending'], true) || throw new InvalidArgumentException(__('Publish, dismiss or return it to pending.'));
        DB::transaction(fn () => AuditLog::actingAs($curator, fn () => $lead->update(['status_id' => self::status($status), 'curated_by_id' => $curator->id,
            'published_at' => $status === 'published' ? now() : null])));
    }

    public static function status(string $notation): string
    {
        return Concept::where('iri', self::STATUSES.$notation)->valueOrFail('id');
    }

    /** @return array{label: string, level: string} */
    public static function band(int $score): array
    {
        return Scholarship::band($score);
    }

    /** The verified links, named and linked, of a lead's evidence. @return list<array{name: string, url: ?string, domain: string}> */
    public static function links(array $evidence): array
    {
        $links = [];
        foreach ($evidence['connections'] ?? [] as $group) {
            foreach ($group['items'] ?? [] as $item) {
                $links[] = ['name' => (string) $item['name'], 'url' => $item['url'] ?? null, 'domain' => (string) ($group['domain'] ?? 'Other')];
            }
        }

        return $links;
    }

    private function factualWhyItMatters(int $total, array $evidence): string
    {
        $domains = array_values(array_unique(array_filter(array_map(fn ($g) => trim((string) ($g['domain'] ?? '')), $evidence['connections'] ?? []))));
        $secondHop = (int) ($evidence['second_hop_count'] ?? 0);
        $parts = [match (true) {
            $total === 1 => 'This record sits at the centre of a verified catalogue connection.',
            $total > 1 => 'This record sits at the centre of '.$total.' verified catalogue connections.',
            default => 'This record carries a connection worth following up.',
        }];
        if ($domains !== []) {
            $parts[] = 'The links cross '.$this->joinHuman($domains).'.';
        }
        if ($secondHop > 0) {
            $parts[] = $secondHop === 1 ? 'One further entity is reachable indirectly through a shared link, which may point to a connection no one has written down.'
                : $secondHop.' further entities are reachable indirectly through shared links, which may point to connections no one has written down.';
        }
        $parts[] = 'Following these links could reveal shared people, places or activities worth comparing across the collection.';

        return implode(' ', $parts);
    }

    /** Heratio's aiWhyItMatters prompt, through the gateway's chat route. */
    private function aiWhyItMatters(Institution $institution, ScholarshipDiscovery $discovery, ?User $user): array
    {
        $evidence = $discovery->evidence;
        $label = trim((string) $discovery->title) !== '' ? '"'.trim((string) $discovery->title).'"' : 'this record';
        $domains = array_values(array_unique(array_filter(array_map(fn ($g) => trim((string) ($g['domain'] ?? '')), $evidence['connections'] ?? []))));
        $names = array_slice(array_column(self::links($evidence), 'name'), 0, 20);
        $lines = ['You are a research analyst inside an archival catalogue. Write a SHORT, plain-language prompt (2-3 sentences) explaining why the connection below might matter to a researcher, phrased as an invitation to investigate.', '',
            'HARD CONSTRAINTS:', '1. Use ONLY the catalogue links and notes supplied below. Do NOT introduce any person, place, organisation, date, event or record not listed.',
            '2. NEVER invent facts, names or dates.', '3. Be concrete and inviting, e.g. "X and Y share Z - worth comparing their...".', '4. Plain language for a general scholarly audience. No preamble, no headings, no bullet points.', '',
            'THE CONNECTION centres on '.$label.'.'];
        if (trim((string) $discovery->summary) !== '') {
            $lines[] = 'AI lead already drawn from the links: '.trim((string) $discovery->summary);
        }
        if ($domains !== []) {
            $lines[] = 'The links cross these domains: '.implode(', ', $domains).'.';
        }
        if ($names !== []) {
            $lines[] = 'Verified linked entities: '.implode('; ', $names).'.';
        }
        $lines[] = '';
        $lines[] = 'Return ONLY the 2-3 sentence prompt.';
        $model = $this->gateway->settings($institution->id)->chat_model ?: (string) config('workspace.ai.chat_model');

        return $this->gateway->call($institution, 'chat', 'ollama/api/chat',
            ['model' => $model, 'stream' => false, 'think' => false, 'options' => ['temperature' => 0.3], 'messages' => [
                ['role' => 'system', 'content' => 'You are a careful archival research analyst. You ground every statement in the supplied catalogue links and never invent entities or facts. You write short, inviting, plain-language research prompts.'],
                ['role' => 'user', 'content' => implode("\n", $lines)]]],
            ['subject' => $discovery->record, 'field' => 'research-lead', 'user' => $user, 'input' => implode("\n", $lines)],
            fn (array $answer) => ['output' => Assist::withoutThinking((string) ($answer['message']['content'] ?? '')), 'model' => (string) ($answer['model'] ?? $model)]);
    }

    private function joinHuman(array $items): string
    {
        return count($items) <= 1 ? (string) ($items[0] ?? '') : implode(', ', array_slice($items, 0, -1)).' and '.end($items);
    }
}
