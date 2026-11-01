<?php

// OpenRiC Workspace - field alerts: news of the works a research project cites (roadmap phase 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's FieldAlertService: a project watches the works it cites by their DOIs, taken from the entries of its
// bibliographies or added by hand. A scan asks the public scholarly services, Crossref and OpenAlex, about each: a work
// retracted or withdrawn, one updated (a correction, erratum or new version), and new works that cite it. Each alert
// is raised once; it is read or not. The daily run scans every project's watches; a project's members may scan it
// now. These are public metadata services, not AI, and nothing of the project is sent to them but the DOI.

namespace App\Research;

use App\Models\Bibliography;
use App\Models\BibliographyEntry;
use App\Models\Concept;
use App\Models\ResearchFieldAlert;
use App\Models\ResearchFieldWatch;
use App\Models\ResearchProject;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class FieldAlerts
{
    private const CROSSREF = 'https://api.crossref.org';

    private const OPENALEX = 'https://api.openalex.org';

    private const TIMEOUT = 10;

    /** New citing works told of, at most, each scan. */
    private const RELATED = 3;

    public function __construct(private Projects $projects) {}

    /** A DOI as its bare form, from a link or a doi: prefix; null when it is not one. */
    public static function doi(?string $value): ?string
    {
        $doi = preg_replace('#^(https?://(dx\.)?doi\.org/|doi:\s*)#i', '', trim((string) $value)) ?? '';

        return preg_match('#^10\.\d{4,9}/\S+$#', $doi) ? mb_strtolower($doi) : null;
    }

    public function watch(User $user, ResearchProject $project, string $doi, ?string $title = null, ?string $source = null): ResearchFieldWatch
    {
        $this->projects->must($user, $project, 'contributor');
        $doi = self::doi($doi) ?? throw new InvalidArgumentException(__('That is not a DOI.'));

        return ResearchFieldWatch::firstOrCreate(['project_id' => $project->id, 'doi' => $doi],
            ['title' => trim((string) $title) ?: null, 'source_ref' => $source, 'added_by_id' => $user->id, 'created_at' => now()]);
    }

    /** Heratio's seedWatchesFromBibliography: watch every DOI of the project's bibliographies. @return int how many are new */
    public function fromBibliographies(User $user, ResearchProject $project): int
    {
        $this->projects->must($user, $project, 'contributor');
        $n = 0;
        $entries = BibliographyEntry::whereIn('bibliography_id', Bibliography::where('project_id', $project->id)->select('id'))->whereNotNull('doi')->get();
        foreach ($entries as $entry) {
            if (self::doi($entry->doi) !== null && $this->watch($user, $project, $entry->doi, $entry->title, 'bibliography')->wasRecentlyCreated) {
                $n++;
            }
        }

        return $n;
    }

    public function unwatch(User $user, ResearchFieldWatch $watch): void
    {
        $this->projects->must($user, $watch->project, $watch->added_by_id === $user->id ? 'contributor' : 'editor');
        $watch->delete();
    }

    public function read(User $user, ResearchProject $project, ?ResearchFieldAlert $alert = null): void
    {
        $this->projects->must($user, $project, 'viewer');
        ($alert ? ResearchFieldAlert::whereKey($alert->id)->where('project_id', $project->id) : ResearchFieldAlert::where('project_id', $project->id))->update(['is_read' => true]);
    }

    /** @return int alerts raised */
    public function scanProject(ResearchProject $project): int
    {
        return $project->fieldWatches()->get()->sum(fn ($w) => $this->scan($w));
    }

    /** The daily run. @return int alerts raised */
    public function scanAll(): int
    {
        return ResearchFieldWatch::with('project')->get()->sum(fn ($w) => $this->scan($w));
    }

    /** @return int alerts raised for one watched work */
    public function scan(ResearchFieldWatch $watch): int
    {
        $crossref = $this->get(self::CROSSREF.'/works/'.rawurlencode($watch->doi))['message'] ?? [];
        $openalex = $this->get(self::OPENALEX.'/works/doi:'.rawurlencode($watch->doi));
        $title = $watch->title ?? (string) ($crossref['title'][0] ?? $openalex['title'] ?? $watch->doi);
        $url = 'https://doi.org/'.$watch->doi;
        $raised = 0;

        $retracted = ! empty($openalex['is_retracted'])
            || collect($crossref['update-to'] ?? [])->contains(fn ($u) => preg_match('/retract|withdraw/i', (string) ($u['type'] ?? '')))
            || collect($crossref['updated-by'] ?? [])->contains(fn ($u) => preg_match('/retract|withdraw/i', (string) ($u['type'] ?? '')))
            || isset($crossref['relation']['is-retracted-by']) || isset($crossref['relation']['has-retraction']);
        if ($retracted) {
            $raised += $this->raise($watch, 'retraction', $title, __('This cited work is retracted. Treat its findings as withdrawn and review every claim that relies on it.'), $url);
        }
        foreach ($crossref['updated-by'] ?? [] as $u) {
            $type = (string) ($u['type'] ?? 'update');
            if (! preg_match('/retract|withdraw/i', $type)) {
                $doi = (string) ($u['DOI'] ?? '');
                $raised += $this->raise($watch, 'update', __(':type published for a cited work', ['type' => ucfirst(str_replace(['_', '-'], ' ', $type))]),
                    __('Crossref reports a ":type" for :title. Check whether it changes how you cite it.', ['type' => $type, 'title' => $title]), $doi !== '' ? 'https://doi.org/'.$doi : $url);
            }
        }
        if (($openalex['id'] ?? null) !== null) {
            $citing = $this->get(self::OPENALEX.'/works', ['filter' => 'cites:'.basename((string) $openalex['id']), 'sort' => 'publication_date:desc', 'per-page' => self::RELATED]);
            foreach (array_slice($citing['results'] ?? [], 0, self::RELATED) as $w) {
                $raised += $this->raise($watch, 'new-related', (string) ($w['title'] ?? $w['display_name'] ?? __('A citing work')),
                    __('A new work cites :title.', ['title' => $title]), (string) ($w['doi'] ?? $w['id'] ?? ''));
            }
        }
        $watch->update(['last_checked_at' => now(), 'title' => $watch->title ?? ($title !== $watch->doi ? mb_substr($title, 0, 500) : null)]);

        return $raised;
    }

    /** Once only: by type and address, or by type and title. */
    private function raise(ResearchFieldWatch $watch, string $type, string $title, string $detail, ?string $url): int
    {
        $typeId = Concept::where('iri', Vocab::BASE.'field-alert-type/'.$type)->value('id');
        $exists = ResearchFieldAlert::where(['project_id' => $watch->project_id, 'alert_type_id' => $typeId])
            ->where(fn ($q) => $url ? $q->where('url', $url) : $q->whereNull('url')->where('title', $title))->exists();
        if ($exists) {
            return 0;
        }
        ResearchFieldAlert::create(['project_id' => $watch->project_id, 'watch_id' => $watch->id, 'alert_type_id' => $typeId, 'title' => mb_substr($title, 0, 500),
            'detail' => $detail, 'url' => $url ?: null, 'detected_at' => now()]);

        return 1;
    }

    private function get(string $url, array $query = []): array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => 'OpenRiC-Workspace/'.trim((string) @file_get_contents(base_path('VERSION'))).' (mailto:'.config('mail.from.address').')'])
                ->acceptJson()->timeout(self::TIMEOUT)->get($url, $query);

            return $response->successful() ? (array) $response->json() : [];
        } catch (\Throwable $e) {
            Log::info('Field alert lookup failed', ['url' => $url, 'error' => $e->getMessage()]);

            return [];
        }
    }
}
