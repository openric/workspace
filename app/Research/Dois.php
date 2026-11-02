<?php

// OpenRiC Workspace - DOIs registered at DataCite for a research project or its outputs (roadmap phase 19h,
// ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Heratio's mintDoi made up a DOI under 10.5281 (Zenodo's prefix) and called it minted. Here a DOI is registered
// through DataCite's REST API with the institution's own repository account and prefix, test or production; DataCite
// makes the suffix. A DOI starts as a draft (reserved, not resolving) or is published at once (findable); a draft can
// be published later, and a published DOI cannot be withdrawn, only its metadata brought up to date. Its metadata
// (DataCite 4): creators from the project's team, with ORCID iDs, or else whoever registers it; the title; the
// institution as publisher; the year; the resource type from the output's type. It resolves to the DOI's own landing
// page in the Workspace, which shows what was registered, so it keeps resolving when a share link is withdrawn. Only
// a public project's DOI can be registered, since registering publishes. The output's identifier becomes the DOI.

namespace App\Research;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\ResearchDoi;
use App\Models\ResearchProject;
use App\Models\ResearchProjectOutput;
use App\Models\ResearchServiceSettings;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class Dois
{
    /** DataCite's resourceTypeGeneral, from the Workspace's output types. */
    private const TYPES = ['journal-article' => 'JournalArticle', 'dataset' => 'Dataset', 'software' => 'Software', 'presentation' => 'Text', 'thesis' => 'Dissertation',
        'report' => 'Report', 'chapter' => 'BookChapter', 'other' => 'Other'];

    public function __construct(private Projects $projects) {}

    public function settings(int|string $institutionId): ?ResearchServiceSettings
    {
        $s = ResearchServiceSettings::where('institution_id', $institutionId)->first();

        return $s && $s->datacite_repository_id && $s->datacite_password && $s->datacite_prefix ? $s : null;
    }

    public function register(User $actor, ResearchProject $project, ?ResearchProjectOutput $output, bool $publish): ResearchDoi
    {
        $this->projects->must($actor, $project, 'editor');
        if ($output && $output->project_id !== $project->id) {
            throw new RuntimeException(__('That output is not of this project.'));
        }
        if ($project->visibility->notation !== 'public') {
            throw new RuntimeException(__('Make the project public first: registering a DOI publishes it.'));
        }
        if ($project->dois()->where('output_id', $output?->id)->exists()) {
            throw new RuntimeException(__('It has a DOI already.'));
        }
        $s = $this->settings($project->institution_id) ?? throw new RuntimeException(__('DataCite is not set up yet. Ask the institution to set it up under the research settings.'));
        $id = (string) Str::uuid7();
        $url = route('research.dois.landing', $id);
        $metadata = $this->metadata($actor, $project, $output);
        $response = $this->call($s, 'post', '/dois', ['data' => ['type' => 'dois', 'attributes' => array_filter(['prefix' => $s->datacite_prefix, 'event' => $publish ? 'publish' : null, 'url' => $url] + $metadata)]]);

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $project, $output, $s, $id, $url, $metadata, $response, $publish) {
            $doi = new ResearchDoi(['project_id' => $project->id, 'output_id' => $output?->id, 'doi' => mb_strtolower((string) $response['data']['id']),
                'state_id' => $this->state((string) ($response['data']['attributes']['state'] ?? ($publish ? 'findable' : 'draft'))), 'url' => $url, 'metadata' => $metadata,
                'test' => $s->datacite_test, 'minted_by_id' => $actor->id]);
            $doi->id = $id;
            $doi->save();
            if ($output) {
                $output->update(['identifier_type_id' => Concept::where('iri', Vocab::BASE.'output-identifier-type/doi')->value('id'), 'identifier' => $doi->doi, 'identifier_url' => null]);
            }
            $this->projects->log($project, $actor, 'doi', $output, ['doi' => $doi->doi]);

            return $doi;
        }));
    }

    /** A draft DOI made findable; or a findable one's metadata brought up to date. */
    public function publish(User $actor, ResearchDoi $doi): void
    {
        $this->projects->must($actor, $doi->project, 'editor');
        $s = $this->settings($doi->project->institution_id) ?? throw new RuntimeException(__('DataCite is not set up yet.'));
        if ((bool) $s->datacite_test !== $doi->test) {
            throw new RuntimeException(__('The DOI was registered with DataCite\'s other service, test or production.'));
        }
        $metadata = $this->metadata($actor, $doi->project, $doi->output);
        $response = $this->call($s, 'put', '/dois/'.rawurlencode($doi->doi), ['data' => ['type' => 'dois', 'attributes' => ['event' => 'publish', 'url' => $doi->url] + $metadata]]);
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $doi->update(['metadata' => $metadata, 'state_id' => $this->state((string) ($response['data']['attributes']['state'] ?? 'findable'))])));
    }

    /** DataCite 4 metadata, as the REST API takes it. */
    public function metadata(User $actor, ResearchProject $project, ?ResearchProjectOutput $output): array
    {
        $project->loadMissing('teamMembers');
        $creators = $project->teamMembers->sortByDesc('is_lead')->map(fn ($t) => array_filter(['name' => $t->person_name, 'nameType' => 'Personal',
            'nameIdentifiers' => $t->orcid ? [['nameIdentifier' => 'https://orcid.org/'.$t->orcid, 'nameIdentifierScheme' => 'ORCID', 'schemeUri' => 'https://orcid.org']] : null,
            'affiliation' => $t->affiliation ? [['name' => $t->affiliation]] : null]))->values()->all() ?: [['name' => $actor->name, 'nameType' => 'Personal']];
        $type = $output ? self::TYPES[$output->outputType->notation] ?? 'Other' : 'Other';
        $year = (int) ($output?->output_date ?? now())->format('Y');

        return array_filter(['creators' => $creators, 'titles' => [['title' => $output?->title ?? $project->title]], 'publisher' => ['name' => Institution::find($project->institution_id)->name],
            'publicationYear' => $year, 'types' => ['resourceTypeGeneral' => $type, 'resourceType' => $output ? $output->outputType->label(['en']) : 'Research project'],
            'descriptions' => trim((string) ($output?->notes ?? $project->description)) !== '' ? [['description' => trim((string) ($output?->notes ?? $project->description)), 'descriptionType' => 'Abstract']] : null,
            'relatedIdentifiers' => $output && $project->dois()->whereNull('output_id')->exists() ? [['relatedIdentifier' => $project->dois()->whereNull('output_id')->value('doi'), 'relatedIdentifierType' => 'DOI', 'relationType' => 'IsPartOf']] : null]);
    }

    private function call(ResearchServiceSettings $s, string $method, string $path, array $body): array
    {
        $base = $s->datacite_test ? 'https://api.test.datacite.org' : 'https://api.datacite.org';
        $response = Http::withBasicAuth($s->datacite_repository_id, $s->datacite_password)->withHeaders(['Content-Type' => 'application/vnd.api+json', 'Accept' => 'application/vnd.api+json'])
            ->timeout(20)->{$method}($base.$path, $body);
        if (! $response->successful() || ! isset($response['data']['id'])) {
            $error = $response->json('errors.0.title') ?? $response->status();
            throw new RuntimeException(__('DataCite did not take it: :error', ['error' => $error]));
        }

        return (array) $response->json();
    }

    private function state(string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.'doi-state/'.$notation)->value('id') ?? throw new RuntimeException(__(':value is not in the list', ['value' => $notation]));
    }
}
