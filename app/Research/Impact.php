<?php

// OpenRiC Workspace - signs that a research project's published outputs are cited, mentioned or reused (roadmap
// phase 19h, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ImpactTrackingService. For each published output with a DOI (the project's outputs, and DOIs
// registered for it at DataCite), OpenAlex gives how often it is cited, kept as one summary per DOI and brought up to
// date on each scan, and up to 25 works citing it; Crossref Event Data gives up to 25 mentions, a DataCite or
// dataset or supplement link counting as reuse. A signal is raised once: the same type and address is not raised
// again. A member may also record one by hand. The scan runs from the project's page and nightly
// (workspace:research-impact). Unlike Heratio, a failed lookup is logged, not silently dropped, and no AI inference
// record is written, since none is made.

namespace App\Research;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\ResearchImpactSignal;
use App\Models\ResearchProject;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class Impact
{
    private const OPENALEX = 'https://api.openalex.org';

    private const CROSSREF_EVENTS = 'https://api.eventdata.crossref.org/v1';

    private const TIMEOUT = 8;

    private const MAX = 25;

    private const SOURCES = ['wikipedia' => 'Wikipedia', 'twitter' => 'Twitter/X', 'reddit' => 'Reddit', 'newsfeed' => 'News', 'hypothesis' => 'Hypothesis', 'wordpressdotcom' => 'WordPress',
        'stackexchange' => 'Stack Exchange', 'datacite' => 'DataCite', 'f1000' => 'F1000', 'plaudit' => 'Plaudit'];

    public function __construct(private Projects $projects) {}

    /** The project's published DOIs, each with the output it belongs to: [doi => output or null]. */
    public function dois(ResearchProject $project): array
    {
        $out = [];
        foreach ($project->outputs()->with('status', 'identifierType')->get() as $o) {
            $doi = $o->status->notation === 'published' ? FieldAlerts::doi($o->identifierType?->notation === 'doi' ? $o->identifier : $o->identifier_url) : null;
            if ($doi) {
                $out[$doi] = $o;
            }
        }
        foreach ($project->dois()->with('state')->get() as $d) {
            if ($d->state->notation === 'findable' && ! $d->test) {
                $out[$d->doi] ??= $d->output;
            }
        }

        return $out;
    }

    public function scan(ResearchProject $project, ?User $actor = null): int
    {
        $raised = 0;
        foreach ($this->dois($project) as $doi => $output) {
            $work = $this->get(self::OPENALEX.'/works/doi:'.rawurlencode($doi));
            if (isset($work['cited_by_count'])) {
                $count = (int) $work['cited_by_count'];
                $raised += $this->summary($project, $output?->id, $doi, $count);
                $id = preg_replace('#^https?://openalex\.org/#i', '', (string) ($work['id'] ?? ''));
                foreach ($id ? (array) ($this->get(self::OPENALEX.'/works', ['filter' => 'cites:'.$id, 'per_page' => self::MAX, 'select' => 'id,display_name,doi,publication_year'])['results'] ?? []) : [] as $r) {
                    $year = isset($r['publication_year']) ? ' ('.$r['publication_year'].')' : '';
                    $raised += $this->raise($project, $output?->id, $doi, 'citation', trim((string) ($r['display_name'] ?? '')) ?: __('A citing work'),
                        __('Cites this output:year. Source: OpenAlex.', ['year' => $year]), $r['doi'] ?? $r['id'] ?? null, 'openalex');
                }
            }
            foreach ((array) ($this->get(self::CROSSREF_EVENTS.'/events', ['obj-id' => $doi, 'rows' => self::MAX])['message']['events'] ?? []) as $ev) {
                $source = strtolower((string) ($ev['source_id'] ?? ''));
                $relation = strtolower((string) ($ev['relation_type_id'] ?? ''));
                $reuse = str_contains($source, 'datacite') || str_contains($relation, 'dataset') || str_contains($relation, 'supplement');
                $label = self::SOURCES[$source] ?? ($source !== '' ? ucfirst($source) : 'Web');
                $raised += $this->raise($project, $output?->id, $doi, $reuse ? 'dataset-reuse' : ($source !== '' ? 'mention' : 'other'),
                    $reuse ? __(':source reuse', ['source' => $label]) : __(':source mention', ['source' => $label]),
                    __('Crossref Event Data records it:relation.', ['relation' => $relation !== '' ? ' ('.$relation.')' : '']), is_string($ev['subj_id'] ?? null) ? $ev['subj_id'] : null, 'crossref-event');
            }
        }
        $this->projects->log($project, $actor, 'impact', null, ['raised' => $raised]);

        return $raised;
    }

    public function scanAll(): int
    {
        $raised = 0;
        ResearchProject::whereHas('outputs')->orWhereHas('dois')->each(function ($p) use (&$raised) {
            $raised += $this->scan($p);
        });

        return $raised;
    }

    public function record(User $actor, ResearchProject $project, array $data): ResearchImpactSignal
    {
        $this->projects->must($actor, $project, 'contributor');
        if (trim((string) ($data['title'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Say what the sign is.'));
        }
        $url = trim((string) ($data['url'] ?? ''));
        if ($url !== '' && ! preg_match('#^https?://#i', $url)) {
            throw new InvalidArgumentException(__('Give a web address.'));
        }
        $output = ($data['output_id'] ?? '') ? $project->outputs()->whereKey($data['output_id'])->first() ?? throw new InvalidArgumentException(__('That output is not of this project.')) : null;
        $type = $this->type((string) ($data['signal_type'] ?? 'citation'));
        if ($url !== '' && ResearchImpactSignal::where(['project_id' => $project->id, 'signal_type_id' => $type, 'url' => $url])->exists()) {
            throw new InvalidArgumentException(__('That is recorded already.'));
        }

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $project->impactSignals()->create(['output_id' => $output?->id,
            'doi' => $output ? FieldAlerts::doi($output->identifier ?? $output->identifier_url) : null, 'signal_type_id' => $type, 'title' => mb_substr(trim($data['title']), 0, 500),
            'detail' => trim((string) ($data['detail'] ?? '')) ?: null, 'url' => $url ?: null, 'source' => 'manual', 'detected_at' => ($data['detected_at'] ?? '') ?: now()])));
    }

    public function delete(User $actor, ResearchImpactSignal $signal): void
    {
        $this->projects->must($actor, $signal->project, 'editor');
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $signal->delete()));
    }

    /** Counts by type, and the number of citations: OpenAlex's own count where it has one, else the citations raised. */
    public function counts(ResearchProject $project): array
    {
        $signals = $project->impactSignals()->with('signalType')->get();
        $summaries = $signals->where('source', 'openalex-summary');
        $byDoi = $summaries->mapWithKeys(fn ($s) => [$s->doi => (int) $s->detail]);
        $citations = $byDoi->sum() + $signals->filter(fn ($s) => $s->signalType->notation === 'citation' && $s->source !== 'openalex-summary' && ! $byDoi->has($s->doi))->count();

        return ['by_type' => $signals->where('source', '!=', 'openalex-summary')->countBy(fn ($s) => $s->signalType->notation)->all(), 'citations' => $citations,
            'last_scan' => $signals->max('detected_at')];
    }

    public function signals(ResearchProject $project): Collection
    {
        return $project->impactSignals()->with('signalType', 'output')->get();
    }

    private function raise(ResearchProject $project, ?string $output, string $doi, string $type, string $title, string $detail, mixed $url, string $source): int
    {
        $url = is_string($url) && $url !== '' ? mb_substr($url, 0, 1000) : null;
        $typeId = $this->type($type);
        $exists = ResearchImpactSignal::where(['project_id' => $project->id, 'signal_type_id' => $typeId])
            ->when($url, fn ($q) => $q->where('url', $url), fn ($q) => $q->whereNull('url')->where('title', $title))->exists();
        if ($exists) {
            return 0;
        }
        $project->impactSignals()->create(['output_id' => $output, 'doi' => $doi, 'signal_type_id' => $typeId, 'title' => mb_substr($title, 0, 500), 'detail' => $detail,
            'url' => $url, 'source' => $source, 'detected_at' => now()]);

        return 1;
    }

    /**
     * The citation count of one DOI, kept as one row brought up to date (Heratio's upsertSummary): the count is its
     * detail; it has no address, so it never clashes with a citing work's.
     */
    private function summary(ResearchProject $project, ?string $output, string $doi, int $count): int
    {
        $row = ResearchImpactSignal::firstOrNew(['project_id' => $project->id, 'source' => 'openalex-summary', 'doi' => $doi]);
        $new = ! $row->exists;
        $row->fill(['output_id' => $output, 'signal_type_id' => $this->type('citation'), 'title' => trans_choice('Cited :count time (OpenAlex)|Cited :count times (OpenAlex)', $count, ['count' => number_format($count)]),
            'detail' => (string) $count, 'url' => null, 'detected_at' => now()])->save();

        return (int) $new;
    }

    private function get(string $url, array $query = []): array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => 'OpenRiC-Workspace/'.trim((string) @file_get_contents(base_path('VERSION'))).' (mailto:'.config('mail.from.address').')'])
                ->acceptJson()->timeout(self::TIMEOUT)->get($url, $query);

            return $response->successful() ? (array) $response->json() : [];
        } catch (\Throwable $e) {
            Log::info('Impact lookup failed', ['url' => $url, 'error' => $e->getMessage()]);

            return [];
        }
    }

    private function type(string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.'impact-signal-type/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
