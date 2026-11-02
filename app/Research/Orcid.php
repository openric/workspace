<?php

// OpenRiC Workspace - a researcher's ORCID iD, proven by signing in at ORCID, their works read from ORCID, and a
// project's output added to their ORCID record (roadmap phase 19h, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's OrcidService. The institution registers one ORCID client (its ID and secret, kept encrypted with
// the sandbox or the production registry); Heratio kept a client per researcher. A researcher signs in at ORCID with
// the /authenticate, /read-limited and /activities/update scopes; the state is checked against the session, the code
// exchanged for a token, and the iD written to the researcher's profile, so it is proven, not typed. Their works are
// read through the member API and kept with the link; an output of a project they are on is added as a work with its
// DOI or other identifier (JSON, ORCID API 3.0). Unlinking removes the token and leaves the iD on the profile.

namespace App\Research;

use App\Audit\AuditLog;
use App\Models\Researcher;
use App\Models\ResearcherOrcidLink;
use App\Models\ResearchProjectOutput;
use App\Models\ResearchServiceSettings;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class Orcid
{
    private const SCOPE = '/authenticate /read-limited /activities/update';

    /** ORCID's work types, from the Workspace's output types. */
    private const WORK_TYPES = ['journal-article' => 'journal-article', 'dataset' => 'data-set', 'software' => 'software', 'presentation' => 'lecture-speech',
        'thesis' => 'dissertation-thesis', 'report' => 'report', 'chapter' => 'book-chapter'];

    public function settings(User $user): ?ResearchServiceSettings
    {
        $s = ResearchServiceSettings::where('institution_id', $user->institution_id)->first();

        return $s && $s->orcid_client_id && $s->orcid_client_secret ? $s : null;
    }

    public function researcher(User $user): Researcher
    {
        return Researcher::where(['institution_id' => $user->institution_id, 'user_id' => $user->id])->first()
            ?? throw new RuntimeException(__('Register as a researcher first.'));
    }

    /** Where to send the researcher to sign in at ORCID; the state is kept in the session. */
    public function authorizeUrl(User $user): string
    {
        $s = $this->settings($user) ?? throw new RuntimeException(__('ORCID is not set up yet. Ask the institution to set it up under the research settings.'));
        $this->researcher($user);
        $state = Str::random(40);
        session(['orcid_state' => $state]);

        return self::site($s).'/oauth/authorize?'.http_build_query(['client_id' => $s->orcid_client_id, 'response_type' => 'code', 'scope' => self::SCOPE,
            'redirect_uri' => route('research.orcid.callback'), 'state' => $state]);
    }

    public function callback(User $user, ?string $code, ?string $state): ResearcherOrcidLink
    {
        $expected = session()->pull('orcid_state');
        if (! $code || ! $expected || ! is_string($state) || ! hash_equals($expected, $state)) {
            throw new RuntimeException(__('The ORCID sign-in could not be confirmed. Try again.'));
        }
        $s = $this->settings($user) ?? throw new RuntimeException(__('ORCID is not set up yet.'));
        $researcher = $this->researcher($user);
        $response = Http::asForm()->acceptJson()->timeout(15)->post(self::site($s).'/oauth/token', ['client_id' => $s->orcid_client_id, 'client_secret' => $s->orcid_client_secret,
            'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => route('research.orcid.callback')]);
        $token = (array) $response->json();
        $orcid = Projects::orcid((string) ($token['orcid'] ?? ''));
        if (! $response->successful() || ! $orcid || empty($token['access_token'])) {
            throw new RuntimeException(__('ORCID did not confirm the sign-in.'));
        }

        return DB::transaction(fn () => AuditLog::actingAs($user, function () use ($researcher, $token, $orcid) {
            $researcher->update(['orcid_id' => $orcid]);

            return ResearcherOrcidLink::updateOrCreate(['researcher_id' => $researcher->id], ['orcid' => $orcid, 'name' => $token['name'] ?? null, 'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'] ?? null, 'scope' => $token['scope'] ?? null, 'expires_at' => isset($token['expires_in']) ? now()->addSeconds((int) $token['expires_in']) : null,
                'linked_at' => now()]);
        }));
    }

    public function unlink(User $user): void
    {
        $researcher = $this->researcher($user);
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => ResearcherOrcidLink::where('researcher_id', $researcher->id)->delete()));
    }

    /** The researcher's works on ORCID, kept with the link: [title, year, type, doi]. */
    public function pullWorks(User $user): array
    {
        [$s, $link] = $this->linked($user);
        $response = Http::withToken($link->access_token)->acceptJson()->timeout(15)->get(self::api($s).'/v3.0/'.$link->orcid.'/works');
        if (! $response->successful()) {
            throw new RuntimeException(__('ORCID did not answer (:status).', ['status' => $response->status()]));
        }
        $works = [];
        foreach ((array) $response->json('group') as $group) {
            $w = $group['work-summary'][0] ?? null;
            if (! $w) {
                continue;
            }
            $doi = collect($w['external-ids']['external-id'] ?? [])->firstWhere('external-id-type', 'doi')['external-id-value'] ?? null;
            $works[] = ['title' => $w['title']['title']['value'] ?? '', 'year' => $w['publication-date']['year']['value'] ?? null, 'type' => $w['type'] ?? null, 'doi' => FieldAlerts::doi($doi)];
        }
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $link->update(['works' => $works, 'works_pulled_at' => now()])));

        return $works;
    }

    /** Add a project's output to the researcher's ORCID record as a work; the put-code ORCID gives it back. */
    public function pushOutput(User $user, ResearchProjectOutput $output, Projects $projects): string
    {
        $projects->must($user, $output->project, 'contributor');
        [$s, $link] = $this->linked($user);
        $output->loadMissing('outputType', 'identifierType');
        $doi = FieldAlerts::doi($output->identifierType?->notation === 'doi' ? $output->identifier : $output->identifier_url);
        $url = Projects::outputUrl($output);
        $ids = $doi ? [['external-id-type' => 'doi', 'external-id-value' => $doi, 'external-id-url' => ['value' => 'https://doi.org/'.$doi], 'external-id-relationship' => 'self']]
            : ($url ? [['external-id-type' => 'uri', 'external-id-value' => $url, 'external-id-relationship' => 'self']] : []);
        if (! $ids) {
            throw new RuntimeException(__('Give the output a DOI or web address before adding it to ORCID.'));
        }
        $work = array_filter(['title' => ['title' => ['value' => $output->title]], 'type' => self::WORK_TYPES[$output->outputType->notation] ?? 'other',
            'journal-title' => $output->venue ? ['value' => $output->venue] : null, 'publication-date' => $output->output_date ? ['year' => ['value' => $output->output_date->format('Y')]] : null,
            'external-ids' => ['external-id' => $ids], 'url' => $url ? ['value' => $url] : null]);
        $response = Http::withToken($link->access_token)->acceptJson()->asJson()->timeout(15)->post(self::api($s).'/v3.0/'.$link->orcid.'/work', $work);
        if (! $response->successful()) {
            throw new RuntimeException(__('ORCID did not take the work (:status).', ['status' => $response->status()]));
        }
        $projects->log($output->project, $user, 'output', $output, ['orcid' => $link->orcid]);

        return basename((string) $response->header('Location'));
    }

    private function linked(User $user): array
    {
        $s = $this->settings($user) ?? throw new RuntimeException(__('ORCID is not set up yet.'));
        $link = ResearcherOrcidLink::where('researcher_id', $this->researcher($user)->id)->first() ?? throw new RuntimeException(__('Link your ORCID iD first.'));
        if ($link->expires_at && $link->expires_at->isPast()) {
            throw new RuntimeException(__('Your ORCID permission has expired. Link your ORCID iD again.'));
        }

        return [$s, $link];
    }

    private static function site(ResearchServiceSettings $s): string
    {
        return $s->orcid_sandbox ? 'https://sandbox.orcid.org' : 'https://orcid.org';
    }

    private static function api(ResearchServiceSettings $s): string
    {
        return $s->orcid_sandbox ? 'https://api.sandbox.orcid.org' : 'https://api.orcid.org';
    }
}
