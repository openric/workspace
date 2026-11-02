<?php

// OpenRiC Workspace - a research project's funding, grant applications, data management plans, ethics approvals,
// impact, compliance, replication pack, DOIs and ORCID (roadmap phase 19h, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\ResearchDmp;
use App\Models\ResearchDoi;
use App\Models\Researcher;
use App\Models\ResearcherOrcidLink;
use App\Models\ResearchGrantDraft;
use App\Models\ResearchProject;
use App\Models\ResearchServiceSettings;
use App\Models\User;
use App\Research\DataPlans;
use App\Research\Funding;
use App\Research\Impact;
use App\Support\Vocab;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ResearchFundingTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $admin;

    private User $reader;

    private User $other;

    private ResearchProject $project;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::preventStrayRequests();
        $this->travelTo('2026-11-05 09:00');
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->admin = app(AccountService::class)->create($this->institution, 'Admin', 'admin@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->admin, 'administrator');
        $this->reader = app(AccountService::class)->create($this->institution, 'Rita', 'rita@example.org', 'correct horse battery staple');
        $this->other = app(AccountService::class)->create($this->institution, 'Olu', 'olu@example.org', 'correct horse battery staple');
        $this->actingAs($this->reader)->post(route('research.projects.store'), ['title' => 'The parish bridge', 'project_type' => 'thesis', 'status' => 'active', 'visibility' => 'collaborators',
            'description' => 'How the parish council paid for its bridge, 1890 to 1910.', 'start_date' => '2026-01-01', 'expected_end_date' => '2027-12-31'])->assertSessionHasNoErrors();
        $this->project = ResearchProject::where('owner_id', $this->reader->id)->sole();
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE."$vocabulary/$notation")->value('id');
    }

    public function test_funding_is_totalled_per_currency_and_a_grant_application_starts_from_its_template(): void
    {
        $p = $this->project;
        $this->actingAs($this->reader)->post(route('research.funding.save', $p), ['title' => 'Doctoral bursary', 'funder_name' => 'National Research Foundation', 'funder_type' => 'research-council',
            'amount' => '120 000.00', 'currency' => 'ZAR', 'status' => 'active', 'start_date' => '2026-01-01', 'end_date' => '2027-12-31'])->assertSessionHasNoErrors();
        $this->actingAs($this->reader)->post(route('research.funding.save', $p), ['title' => 'Travel grant', 'funder_name' => 'Ferdinand Trust', 'funder_type' => 'foundation',
            'amount' => '1,500.50', 'currency' => 'USD', 'status' => 'awarded', 'start_date' => '2027-03-01'])->assertSessionHasNoErrors();
        $this->actingAs($this->reader)->post(route('research.funding.save', $p), ['title' => 'Archive fellowship', 'funder_name' => 'Ferdinand Trust', 'amount' => '9000', 'currency' => 'USD', 'status' => 'applied'])->assertSessionHasNoErrors();
        $this->actingAs($this->reader)->post(route('research.funding.save', $p), ['title' => 'Backwards', 'funder_name' => 'X', 'start_date' => '2026-05-01', 'end_date' => '2026-04-01'])
            ->assertSessionHasErrors(['research' => 'The end date comes before the start.']);
        $this->actingAs($this->reader)->post(route('research.funding.save', $p), ['title' => 'Bad', 'funder_name' => 'X', 'amount' => 'lots'])->assertSessionHasErrors('research');

        $summary = app(Funding::class)->summary($p);
        // Awarded money only, never added across currencies; the travel grant is awarded but starts later.
        $this->assertSame(['USD' => ['amount' => '1500.50', 'count' => 1], 'ZAR' => ['amount' => '120000.00', 'count' => 1]], $summary['by_currency']);
        $this->assertSame(1, $summary['active_now']);
        $this->actingAs($this->reader)->get(route('research.funding', $p))->assertOk()->assertSee('ZAR 120 000.00')->assertSee('Active now');
        // Someone not on the project sees nothing of it.
        $this->actingAs($this->other)->get(route('research.funding', $p))->assertNotFound();
        $this->actingAs($this->other)->post(route('research.funding.save', $p), ['title' => 'Mine', 'funder_name' => 'X'])->assertSessionHasErrors('research');

        $this->actingAs($this->reader)->post(route('research.projects.milestones', $p), ['title' => 'Archive visits', 'due_date' => '2026-12-01'])->assertSessionHasNoErrors();
        $this->actingAs($this->reader)->post(route('research.grants.store', $p), ['template' => 'nrf'])->assertRedirect();
        $draft = ResearchGrantDraft::sole();
        $this->assertSame('NRF-style (research foundation) application for The parish bridge', $draft->title);
        $this->assertSame(['summary', 'background', 'questions', 'aims', 'methodology', 'outputs', 'impact', 'capacity', 'workplan', 'budget', 'ethics'], $draft->sections->pluck('section_key')->all());
        $this->actingAs($this->reader)->get(route('research.grants.show', $draft))->assertOk()->assertSee('How the parish council paid for its bridge')->assertSee('Archive visits (2026-12-01)');
        $this->actingAs($this->reader)->post(route('research.grants.save', $draft), ['title' => $draft->title, 'status' => 'in-review', 'sections' => ['summary' => 'Who paid for the bridge.', 'aims' => 'Find the ledgers.']])
            ->assertSessionHasNoErrors();
        $this->assertSame(['filled' => 2, 'total' => 11, 'pct' => 18], Funding::completeness($draft->fresh()->sections));
        $this->assertSame('in-review', $draft->fresh()->status->notation);

        // Funding calls are the researcher's own.
        $this->actingAs($this->reader)->post(route('research.calls.save'), ['funder' => 'NRF', 'title' => 'Thuthuka 2027', 'deadline' => '2027-04-30', 'project_id' => $p->id])->assertSessionHasNoErrors();
        $this->actingAs($this->reader)->get(route('research.calls'))->assertOk()->assertSee('Thuthuka 2027');
        $this->actingAs($this->other)->get(route('research.calls'))->assertOk()->assertDontSee('Thuthuka 2027');
        $this->actingAs($this->other)->post(route('research.calls.save'), ['funder' => 'NRF', 'title' => 'Mine', 'project_id' => $p->id])->assertSessionHasErrors(['research' => 'Choose one of your projects.']);
    }

    public function test_a_data_management_plan_is_answered_and_exported_as_a_madmp_and_ethics_approvals_expire(): void
    {
        $p = $this->project;
        $this->actingAs($this->reader)->post(route('research.projects.plans.store', $p), ['title' => 'Bridge data plan', 'template' => 'nrf', 'funder' => 'NRF', 'language' => 'af',
            'contact_name' => 'Rita', 'contact_email' => 'rita@example.org'])->assertRedirect();
        $plan = ResearchDmp::sole();
        $this->actingAs($this->reader)->get(route('research.projects.plans', $p))->assertOk()->assertSee('Bridge data plan')->assertSee('0 of 12 sections answered');
        $this->assertCount(12, $plan->sections);
        $this->actingAs($this->reader)->post(route('research.projects.plans.save', [$p, $plan]), ['title' => 'Bridge data plan', 'status' => 'draft', 'language' => 'af', 'contact_name' => 'Rita',
            'contact_email' => 'rita@example.org', 'sections' => ['data-description' => 'Transcriptions of council minutes.', 'costs' => 'None beyond storage.']])->assertSessionHasNoErrors();
        $this->actingAs($this->reader)->post(route('research.ethics.save', $p), ['title' => 'Interviews with descendants', 'approval_type' => 'human-subjects', 'status' => 'approved',
            'committee_name' => 'Faculty ethics committee', 'reference_number' => 'HUM-2026-014', 'decision_date' => '2026-02-01', 'expiry_date' => '2026-12-15',
            'data_sensitivity' => 'personal', 'dmp_id' => $plan->id])->assertSessionHasNoErrors();

        $madmp = $this->actingAs($this->reader)->get(route('research.projects.plans.show', [$p, $plan, 'format' => 'madmp']))->assertOk()->json('dmp');
        $this->assertSame('afr', $madmp['language']);
        $this->assertSame('yes', $madmp['ethical_issues_exist']);
        $this->assertSame(['yes', 'no'], [$madmp['dataset'][0]['personal_data'], $madmp['dataset'][0]['sensitive_data']]);
        $this->assertStringContainsString('Data description and collection: Transcriptions of council minutes.', $madmp['dataset'][0]['description']);
        $this->assertSame('rita@example.org', $madmp['contact']['mbox']);
        $this->assertCount(12, $madmp['extension'][0]['openric']['sections']);
        $this->assertSame([['name' => 'NRF', 'funding_status' => 'planned']], $madmp['project'][0]['funding']);

        $approval = $p->ethicsApprovals()->with('status')->sole();
        $this->assertSame('soon', DataPlans::expiry($approval));
        $this->actingAs($this->reader)->get(route('research.ethics', $p))->assertOk()->assertSee('Expires soon');
        $checks = app(DataPlans::class)->compliance($p->fresh())['checks'];
        $this->assertSame(['attention', 'attention', 'ok', 'missing'], [$checks['ethics'][0], $checks['dmp'][0], $checks['funding'][0], $checks['replication'][0]]);
        $this->travelTo('2026-12-20 09:00');
        $this->assertSame('expired', DataPlans::expiry($approval->fresh()));
        $this->actingAs($this->reader)->get(route('research.compliance', $p))->assertOk()->assertSee('An ethics approval has expired. Renew it before working on.');
        $this->actingAs($this->reader)->post(route('research.ethics.save', $p), ['title' => 'Backwards', 'decision_date' => '2026-05-01', 'expiry_date' => '2026-04-01'])
            ->assertSessionHasErrors(['research' => 'It cannot expire before it was decided.']);
    }

    public function test_impact_is_found_once_and_the_replication_pack_is_an_ro_crate(): void
    {
        $p = $this->project;
        $this->actingAs($this->reader)->post(route('research.projects.outputs', $p), ['title' => 'Who paid for the bridge', 'output_type' => 'journal-article', 'status' => 'published',
            'identifier_type' => 'doi', 'identifier' => 'https://doi.org/10.5555/Bridge.1', 'output_date' => '2026-06-01'])->assertSessionHasNoErrors();
        Http::fake([
            'api.openalex.org/works/doi:*' => Http::response(['id' => 'https://openalex.org/W42', 'doi' => 'https://doi.org/10.5555/bridge.1', 'cited_by_count' => 3]),
            'api.openalex.org/works?*' => Http::response(['results' => [['id' => 'https://openalex.org/W7', 'display_name' => 'Bridges and parishes', 'doi' => 'https://doi.org/10.6666/parish', 'publication_year' => 2026]]]),
            'api.eventdata.crossref.org/*' => Http::response(['message' => ['events' => [['source_id' => 'wikipedia', 'subj_id' => 'https://en.wikipedia.org/wiki/Parish_bridge', 'relation_type_id' => 'references'],
                ['source_id' => 'datacite', 'subj_id' => 'https://doi.org/10.7777/data', 'relation_type_id' => 'is_supplement_to']]]]),
        ]);
        $this->actingAs($this->reader)->post(route('research.impact.step', [$p, 'scan']))->assertSessionHas('raised', 4);
        $this->actingAs($this->reader)->post(route('research.impact.step', [$p, 'scan']))->assertSessionHas('raised', 0);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'doi:10.5555%2Fbridge.1'));
        $this->actingAs($this->reader)->post(route('research.impact.step', [$p, 'record']), ['title' => 'Cited in the provincial heritage report', 'signal_type' => 'policy'])->assertSessionHasNoErrors();
        $this->assertEquals(['citations' => 3, 'by_type' => ['citation' => 1, 'mention' => 1, 'dataset-reuse' => 1, 'policy' => 1]],
            array_intersect_key(app(Impact::class)->counts($p), ['citations' => 1, 'by_type' => 1]));
        $this->actingAs($this->reader)->get(route('research.impact', $p))->assertOk()->assertSee('Bridges and parishes')->assertSee('Wikipedia mention')->assertSee('3 citations');

        $this->actingAs($this->reader)->post(route('research.projects.team', $p), ['person_name' => 'Rita Botha', 'orcid' => '0000-0002-1825-0097', 'is_lead' => 1])->assertSessionHasNoErrors();
        $this->actingAs($this->reader)->post(route('research.reasoning.decisions.save', $p), ['summary' => 'Leave out the 1905 ledger', 'reason' => 'Water damage', 'decision_type' => 'exclusion'])->assertSessionHasNoErrors();
        $zip = $this->actingAs($this->reader)->post(route('research.replication', $p))->assertOk()->assertHeader('Content-Type', 'application/zip');
        $path = tempnam(sys_get_temp_dir(), 'pack');
        file_put_contents($path, $zip->getContent());
        $archive = new \ZipArchive;
        $archive->open($path);
        $crate = json_decode($archive->getFromName('ro-crate-metadata.json'), true);
        $root = collect($crate['@graph'])->firstWhere('@id', './');
        $this->assertSame('The parish bridge', $root['name']);
        $this->assertSame([['@id' => 'https://orcid.org/0000-0002-1825-0097']], $root['author']);
        $this->assertContains(['@id' => 'decision-log.csv'], $root['hasPart']);
        $this->assertStringContainsString('Leave out the 1905 ledger', $archive->getFromName('decision-log.csv'));
        $this->assertNotFalse($archive->getFromName('project.json'));
        $archive->close();
        @unlink($path);
        $this->assertSame(hash('sha256', $zip->getContent()), $p->replicationBuilds()->sole()->sha256);
        $this->assertSame('ok', app(DataPlans::class)->compliance($p->fresh())['checks']['replication'][0]);
    }

    public function test_orcid_is_linked_by_signing_in_and_its_secrets_are_not_audited(): void
    {
        $this->actingAs($this->reader)->get(route('research.services.settings'))->assertForbidden();
        $this->actingAs($this->admin)->post(route('research.services.settings.update'), ['orcid_client_id' => 'APP-ABCDEFGHIJKLMNOP', 'orcid_client_secret' => 'top-secret-value', 'orcid_sandbox' => 1])
            ->assertSessionHasNoErrors();
        $settings = ResearchServiceSettings::sole();
        $this->assertNotSame('top-secret-value', DB::table('research_service_settings')->value('orcid_client_secret'));
        $this->assertSame('top-secret-value', $settings->orcid_client_secret);
        $this->assertFalse(DB::table('audit_log')->whereRaw('after::text like ?', ['%top-secret%'])->exists());
        // A secret left empty keeps the one stored.
        $this->actingAs($this->admin)->post(route('research.services.settings.update'), ['orcid_client_id' => 'APP-ABCDEFGHIJKLMNOP', 'orcid_client_secret' => '', 'orcid_sandbox' => 1]);
        $this->assertSame('top-secret-value', $settings->fresh()->orcid_client_secret);

        $this->actingAs($this->reader)->post(route('research.orcid.step', 'link'))->assertSessionHasErrors(['research' => 'Register as a researcher first.']);
        $researcher = Researcher::create(['institution_id' => $this->institution->id, 'user_id' => $this->reader->id, 'first_name' => 'Rita', 'last_name' => 'Botha',
            'affiliation_id' => $this->concept('researcher-affiliation', 'student'), 'status_id' => $this->concept('researcher-status', 'approved')]);
        $redirect = $this->actingAs($this->reader)->post(route('research.orcid.step', 'link'))->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://sandbox.orcid.org/oauth/authorize?', $redirect);
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
        $this->assertSame('/authenticate /read-limited /activities/update', $query['scope']);

        $this->actingAs($this->reader)->get(route('research.orcid.callback', ['code' => 'abc', 'state' => 'forged']))->assertSessionHasErrors('research');
        $this->assertSame(0, ResearcherOrcidLink::count());

        Http::fake([
            'sandbox.orcid.org/oauth/token' => Http::response(['access_token' => 'tok-123', 'refresh_token' => 'ref-456', 'orcid' => '0000-0002-1825-0097', 'name' => 'Rita Botha', 'scope' => $query['scope'], 'expires_in' => 631138518]),
            'api.sandbox.orcid.org/v3.0/0000-0002-1825-0097/works' => Http::response(['group' => [['work-summary' => [['title' => ['title' => ['value' => 'An earlier article']], 'type' => 'journal-article',
                'publication-date' => ['year' => ['value' => '2024']], 'external-ids' => ['external-id' => [['external-id-type' => 'doi', 'external-id-value' => '10.5555/Earlier']]]]]]]]),
            'api.sandbox.orcid.org/v3.0/0000-0002-1825-0097/work' => Http::response('', 201, ['Location' => 'https://api.sandbox.orcid.org/v3.0/0000-0002-1825-0097/work/98765']),
        ]);
        $this->actingAs($this->reader)->post(route('research.orcid.step', 'link'));
        $this->actingAs($this->reader)->get(route('research.orcid.callback', ['code' => 'abc', 'state' => session('orcid_state')]))->assertRedirect(route('research.profile'))->assertSessionHasNoErrors();
        $this->assertSame('0000-0002-1825-0097', $researcher->fresh()->orcid_id);
        $link = ResearcherOrcidLink::sole();
        $this->assertSame('tok-123', $link->access_token);
        $this->assertNotSame('tok-123', DB::table('researcher_orcid_links')->value('access_token'));
        $this->assertFalse(DB::table('audit_log')->whereRaw('after::text like ?', ['%tok-123%'])->exists());

        $this->actingAs($this->reader)->post(route('research.orcid.step', 'pull'))->assertSessionHasNoErrors();
        $this->assertEquals([['title' => 'An earlier article', 'year' => '2024', 'type' => 'journal-article', 'doi' => '10.5555/earlier']], $link->fresh()->works);
        $this->actingAs($this->reader)->get(route('research.profile'))->assertOk()->assertSee('An earlier article');

        $this->actingAs($this->reader)->post(route('research.projects.outputs', $this->project), ['title' => 'Who paid for the bridge', 'output_type' => 'journal-article', 'status' => 'published',
            'identifier_type' => 'doi', 'identifier' => '10.5555/bridge.1', 'venue' => 'South African Archives Journal', 'output_date' => '2026-06-01']);
        $this->actingAs($this->reader)->post(route('research.orcid.push', $this->project->outputs()->sole()))->assertSessionHasNoErrors();
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/work') && $r['type'] === 'journal-article' && $r['external-ids']['external-id'][0]['external-id-value'] === '10.5555/bridge.1'
            && $r->hasHeader('Authorization', 'Bearer tok-123'));
    }

    public function test_a_doi_is_registered_at_datacite_for_a_public_project_and_resolves_to_its_landing_page(): void
    {
        $p = $this->project;
        $this->actingAs($this->reader)->post(route('research.projects.outputs', $p), ['title' => 'Bridge ledger transcriptions', 'output_type' => 'dataset', 'status' => 'published', 'output_date' => '2026-07-01',
            'notes' => 'Every entry of the bridge ledgers, 1890 to 1910.'])->assertSessionHasNoErrors();
        $this->actingAs($this->reader)->post(route('research.projects.team', $p), ['person_name' => 'Rita Botha', 'orcid' => '0000-0002-1825-0097', 'affiliation' => 'University of the Free State'])->assertSessionHasNoErrors();
        $output = $p->outputs()->sole();
        $this->actingAs($this->reader)->post(route('research.dois.store', $p), ['output_id' => $output->id])->assertSessionHasErrors(['research' => 'Make the project public first: registering a DOI publishes it.']);
        $this->actingAs($this->reader)->post(route('research.projects.step', [$p, 'update']), ['title' => $p->title, 'project_type' => 'thesis', 'status' => 'active', 'visibility' => 'public'])->assertSessionHasNoErrors();
        $this->actingAs($this->reader)->post(route('research.dois.store', $p), ['output_id' => $output->id])->assertSessionHasErrors(['research' => 'DataCite is not set up yet. Ask the institution to set it up under the research settings.']);
        $this->actingAs($this->admin)->post(route('research.services.settings.update'), ['datacite_repository_id' => 'RIVER.ARCHIVES', 'datacite_password' => 'dc-secret', 'datacite_prefix' => '10.80000', 'datacite_test' => 1])
            ->assertSessionHasNoErrors();

        Http::fake([
            'api.test.datacite.org/dois' => Http::response(['data' => ['id' => '10.80000/ABCD-1234', 'type' => 'dois', 'attributes' => ['state' => 'draft']]], 201),
            'api.test.datacite.org/dois/*' => Http::response(['data' => ['id' => '10.80000/abcd-1234', 'type' => 'dois', 'attributes' => ['state' => 'findable']]]),
        ]);
        $this->actingAs($this->reader)->post(route('research.dois.store', $p), ['output_id' => $output->id])->assertSessionHasNoErrors();
        $doi = ResearchDoi::sole();
        $this->assertSame(['10.80000/abcd-1234', 'draft', true], [$doi->doi, $doi->state->notation, $doi->test]);
        $this->assertSame(['doi', '10.80000/abcd-1234'], [$output->fresh()->identifierType->notation, $output->fresh()->identifier]);
        Http::assertSent(function (HttpRequest $r) use ($doi) {
            $a = $r['data']['attributes'] ?? [];

            return $r->method() === 'POST' && $r->hasHeader('Authorization', 'Basic '.base64_encode('RIVER.ARCHIVES:dc-secret')) && $a['prefix'] === '10.80000' && ! isset($a['event'])
                && $a['url'] === route('research.dois.landing', $doi->id) && $a['types']['resourceTypeGeneral'] === 'Dataset' && $a['publicationYear'] === 2026
                && $a['creators'][0]['nameIdentifiers'][0]['nameIdentifier'] === 'https://orcid.org/0000-0002-1825-0097' && $a['publisher']['name'] === 'Riverside Archives';
        });
        $this->actingAs($this->reader)->post(route('research.dois.store', $p), ['output_id' => $output->id])->assertSessionHasErrors(['research' => 'It has a DOI already.']);

        $this->actingAs($this->reader)->post(route('research.dois.publish', $doi))->assertSessionHasNoErrors();
        $this->assertSame('findable', $doi->fresh()->state->notation);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/dois/10.80000%2Fabcd-1234') && $r['data']['attributes']['event'] === 'publish');

        auth()->logout();
        $this->get(route('research.dois.landing', $doi))->assertOk()->assertSee('Bridge ledger transcriptions')->assertSee('https://doi.org/10.80000/abcd-1234')->assertSee('Every entry of the bridge ledgers');
    }
}
