<?php

// OpenRiC Workspace - DOIs for units of description (roadmap phase 20c), against a DataCite that answers as its test
// service does: a published unit minted under the institution's prefix and pattern, its DataCite 4 metadata made from
// what the public reads (creators with their ORCID iDs, subjects, places with their coordinates, funding, the record
// set it is part of by its DOI), resolving to its IRI, the DOI kept as its identifier and in its citations, its maker
// told; a draft refused, as is a contributor; a reserved DOI made findable, verified and hidden; DOIs queued when units
// are published, tried again with a growing pause and failed in the end; views and downloads reported once a day; and
// funding taken in from a CSV.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\AgentService;
use App\Description\IdentifierService;
use App\Description\PlaceService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Dois\DataciteEvents;
use App\Dois\FundingImport;
use App\Dois\RecordDois;
use App\Mail\DoiMail;
use App\Models\DoiQueueItem;
use App\Models\DoiSetting;
use App\Models\Institution;
use App\Models\RecordDoi;
use App\Models\RecordFunding;
use App\Models\RecordResource;
use App\Models\ResearchServiceSettings;
use App\Models\User;
use App\Relations\RelationService;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RecordDoisTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private Institution $institution;

    private User $editor;

    private RecordResource $fonds;

    private RecordResource $letter;

    /** @var list<array{string, string, array}> what DataCite was sent: method, path and body */
    private array $sent = [];

    /** What DataCite answers next: true takes it, false refuses it. */
    private bool $accept = true;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://riverside.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->editor = app(AccountService::class)->create($this->institution, 'Editor', 'editor@riverside.example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->editor, 'editor');
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        app(IdentifierService::class)->setLocal($holder, 'RA');
        $this->fonds = app(RecordService::class)->create($this->institution, self::LEVEL.'fonds', 'Riverside Parish Council records', 'en', null, $holder, 'RPC');
        $this->letter = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Letter of 3 May 1902', 'en', $this->fonds, null, '1');
        app(RecordService::class)->describe($this->letter, ['scope-and-content' => 'Names the witness at the marriage.'], 'en');
        Http::fake(function (ClientRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $this->sent[] = [$request->method(), $path, $request->data()];
            if (! $this->accept) {
                return Http::response(['errors' => [['title' => 'This DOI has already been taken']]], 422);
            }
            if ($path === '/events') {
                return Http::response(['data' => ['id' => 'e1', 'type' => 'events']], 201);
            }
            $attributes = $request->data()['data']['attributes'] ?? [];
            $doi = $attributes['doi'] ?? rawurldecode(substr($path, strlen('/dois/')));
            $state = match (true) {
                $request->method() === 'GET' => 'findable',
                ($attributes['event'] ?? null) === 'publish' => 'findable',
                ($attributes['event'] ?? null) === 'hide' => 'registered',
                default => 'draft',
            };

            return Http::response(['data' => ['id' => $doi, 'type' => 'dois', 'attributes' => ['doi' => $doi, 'state' => $state]]], $request->method() === 'POST' ? 201 : 200);
        });
    }

    public function test_a_published_unit_is_minted_with_its_metadata_and_resolves_to_its_iri(): void
    {
        $dois = app(RecordDois::class);
        $this->assertThrows(fn () => $dois->mint($this->letter, $this->editor), \RuntimeException::class, 'DataCite is not set up');
        $this->account();
        $this->assertThrows(fn () => $dois->mint($this->letter, $this->editor), \RuntimeException::class, 'Publish the unit first');
        $this->assertSame([], $this->sent, 'nothing is sent for a draft');

        $clerk = app(AgentService::class)->create($this->institution, 'person', 'Botha, Maria', 'en');
        app(IdentifierService::class)->add($clerk, ['type' => 'orcid', 'value' => '0000-0002-1825-0097']);
        $town = app(PlaceService::class)->create($this->institution, 'Riverside', 'en');
        $town->update(['latitude' => -25.7479, 'longitude' => 28.2293]);
        app(RelationService::class)->create($this->letter, 'creation', $clerk, [], $this->editor);
        app(RelationService::class)->create($this->letter, 'subject', $town, [], $this->editor);
        RecordFunding::create(['record_resource_id' => $this->letter->id, 'funder_name' => 'National Research Foundation', 'award_number' => 'NRF-1902']);
        foreach ([$this->fonds->repository, $this->fonds, $this->letter, $clerk, $town] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->editor);
        }
        $parent = $dois->mint($this->fonds, $this->editor);
        $this->assertSame('10.80000/ra/'.now()->format('Y').'/'.$this->fonds->id, $parent->doi, 'the prefix and the pattern');
        $this->assertSame('Collection', $this->sent[0][2]['data']['attributes']['types']['resourceTypeGeneral'], 'a unit with units inside it');

        $doi = $dois->mint($this->letter, $this->editor);
        $attributes = end($this->sent)[2]['data']['attributes'];
        $this->assertSame(['POST', '/dois'], array_slice(end($this->sent), 0, 2));
        $this->assertSame([$this->letter->iri, 'publish', 'Riverside Archives', 'Text'], [$attributes['url'], $attributes['event'], $attributes['publisher'], $attributes['types']['resourceTypeGeneral']]);
        $this->assertSame('Letter of 3 May 1902', $attributes['titles'][0]['title']);
        $this->assertSame(['Botha, Maria', 'Personal', '0000-0002-1825-0097', 'ORCID'], [$attributes['creators'][0]['name'], $attributes['creators'][0]['nameType'],
            $attributes['creators'][0]['nameIdentifiers'][0]['nameIdentifier'], $attributes['creators'][0]['nameIdentifiers'][0]['nameIdentifierScheme']]);
        $this->assertSame('Names the witness at the marriage.', $attributes['descriptions'][0]['description']);
        $this->assertSame(-25.7479, $attributes['geoLocations'][0]['geoLocationPoint']['pointLatitude']);
        $this->assertSame(['National Research Foundation', 'NRF-1902'], [$attributes['fundingReferences'][0]['funderName'], $attributes['fundingReferences'][0]['awardNumber']]);
        $this->assertSame([$parent->doi, 'DOI', 'IsPartOf'], array_values($attributes['relatedIdentifiers'][0]), 'part of the fonds, by its DOI');

        $this->assertSame(['findable', true], [$doi->state->notation, $doi->test]);
        $this->assertTrue($this->letter->identifiers()->whereHas('identifierType', fn ($q) => $q->where('notation', 'doi'))->where('value', $doi->doi)->exists(), 'one of its identifiers');
        Mail::assertQueued(DoiMail::class, fn ($m) => $m->doi?->is($doi));
        $this->assertSame(2, DB::table('doi_log')->where('succeeded', true)->count());
        $this->assertSame($doi->id, $dois->mint($this->letter, $this->editor)->id, 'minted once');

        $this->get(route('discover.record', $this->letter->id))->assertSee($doi->doi);
        $this->assertStringContainsString('DO  - '.$doi->doi, $this->get(route('cite', [$this->letter->id, 'format' => 'ris']))->getContent());
    }

    public function test_a_reserved_doi_is_made_findable_verified_and_hidden_by_who_may_publish(): void
    {
        $this->account();
        $this->publish($this->fonds);
        $contributor = app(AccountService::class)->create($this->institution, 'Contributor', 'c@riverside.example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($contributor, 'contributor');
        $this->actingAs($contributor)->post(route('records.doi', [$this->fonds, 'mint']))->assertForbidden();

        $this->actingAs($this->editor)->post(route('records.doi', [$this->fonds, 'reserve']))->assertSessionHasNoErrors();
        $doi = RecordDoi::sole();
        $this->assertSame('draft', $doi->state->notation);
        $this->assertArrayNotHasKey('event', end($this->sent)[2]['data']['attributes'], 'a draft is not published');
        $this->post(route('records.doi', [$this->fonds, 'publish']))->assertSessionHasNoErrors();
        $this->assertSame(['PUT', '/dois/'.rawurlencode($doi->doi), 'findable'], [...array_slice(end($this->sent), 0, 2), $doi->fresh()->state->notation]);
        $this->post(route('records.doi', [$this->fonds, 'verify']))->assertSessionHasNoErrors();
        $this->assertSame('GET', end($this->sent)[0]);
        $this->post(route('records.doi', [$this->fonds, 'hide']), ['reason' => 'withdrawn'])->assertSessionHasNoErrors();
        $this->assertSame('registered', $doi->fresh()->state->notation, 'out of the search, still resolving');

        ResearchServiceSettings::where('institution_id', $this->institution->id)->update(['datacite_test' => false]);
        $this->post(route('records.doi', [$this->fonds, 'update']))->assertSessionHasErrors('doi');
        $this->get(route('dois.show', $doi))->assertOk()->assertSee($doi->doi);
        $this->get(route('dois.index'))->assertOk();
        $this->get(route('dois.report'))->assertOk();
    }

    public function test_dois_are_queued_on_publishing_and_tried_again_until_they_fail(): void
    {
        $this->account();
        DoiSetting::for($this->institution->id)->fill(['auto_mint' => true, 'auto_mint_level_ids' => [DB::table('concepts')->where('iri', self::LEVEL.'fonds')->value('id')]])->save();
        $this->publish($this->fonds);
        $this->publish($this->letter);
        $this->assertSame([$this->fonds->id], DoiQueueItem::pluck('record_resource_id')->all(), 'only the levels chosen');

        $dois = app(RecordDois::class);
        $this->accept = false;
        $this->assertSame(['done' => 0, 'failed' => 1], $dois->process());
        $item = DoiQueueItem::sole();
        $this->assertSame(['pending', 1], [$item->status->notation, $item->attempts]);
        $this->assertTrue($item->scheduled_at->isFuture(), 'tried again later');
        $item->update(['scheduled_at' => now()->subMinute()]);
        $dois->process();
        DoiQueueItem::sole()->update(['scheduled_at' => now()->subMinute()]);
        $dois->process();
        $this->assertSame(['failed', 3], [DoiQueueItem::sole()->status->notation, DoiQueueItem::sole()->attempts]);
        $this->assertStringContainsString('already been taken', DoiQueueItem::sole()->last_error);

        $this->accept = true;
        $this->actingAs($this->editor)->post(route('dois.queue.step', [DoiQueueItem::sole(), 'retry']))->assertRedirect();
        $this->post(route('dois.process'))->assertRedirect();
        $this->assertSame('completed', DoiQueueItem::sole()->status->notation);
        $this->assertSame('findable', RecordDoi::sole()->state->notation);

        // Publishing it again sends its metadata again.
        $this->publish($this->fonds);
        $this->assertSame(1, DoiQueueItem::whereHas('action', fn ($q) => $q->where('notation', 'update'))->count());
    }

    public function test_views_and_downloads_are_reported_to_datacite_once_a_day(): void
    {
        $this->account();
        $this->publish($this->fonds);
        $doi = app(RecordDois::class)->mint($this->fonds, $this->editor);
        $this->get(route('discover.record', $this->fonds->id))->assertOk();
        $this->assertSame(0, DB::table('datacite_events')->count(), 'off until switched on');

        DoiSetting::for($this->institution->id)->fill(['events_enabled' => true, 'events_token' => 'fabrica-token'])->save();
        $this->get(route('discover.record', $this->fonds->id));
        $this->get(route('discover.record', $this->fonds->id));
        $this->assertSame(1, DB::table('datacite_events')->count(), 'once a DOI and day');
        $this->assertSame(['sent' => 1, 'failed' => 0], app(DataciteEvents::class)->flush());
        [$method, $path, $body] = end($this->sent);
        $this->assertSame(['POST', '/events', 'https://doi.org/'.$doi->doi, 'unique-dataset-investigations-regular'],
            [$method, $path, $body['data']['attributes']['subj-id'], $body['data']['attributes']['relation-type-id']]);
        $this->assertNotSame('fabrica-token', DB::table('doi_settings')->value('events_token'), 'the token is kept encrypted');
    }

    public function test_funding_is_taken_in_from_a_csv(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'funding');
        file_put_contents($path, "record,funder_name,funder_identifier,funder_identifier_type,award_number\n{$this->letter->iri},National Research Foundation,https://ror.org/05s0g1g46,ROR,NRF-1\n{$this->letter->referenceCode()},Lotto,,,\nNOPE,Someone,,,\n");
        $result = app(FundingImport::class)->run($path);
        $this->assertSame([2, 1], [$result['added'], $result['skipped']]);
        $this->assertSame('ror', RecordFunding::where('funder_name', 'National Research Foundation')->sole()->identifierType->notation);
        $this->assertSame(2, RecordFunding::where('record_resource_id', $this->letter->id)->count(), 'by IRI and by reference code');
        $this->assertSame(['added' => 0, 'skipped' => 3], array_slice(app(FundingImport::class)->run($path), 0, 2), 'not taken twice');
    }

    private function account(): void
    {
        ResearchServiceSettings::create(['institution_id' => $this->institution->id, 'datacite_repository_id' => 'RIVERSIDE.ARCHIVE', 'datacite_password' => 'secret-pass',
            'datacite_prefix' => '10.80000', 'datacite_test' => true]);
    }

    private function publish(RecordResource $unit): void
    {
        foreach (array_filter([$unit->repository, $unit]) as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->editor);
        }
    }
}
