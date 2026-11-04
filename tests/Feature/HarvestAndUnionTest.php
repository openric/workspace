<?php

// OpenRiC Workspace - OAI-PMH harvest, the union catalogue and the DSpace and Alma connectors (roadmap phase 16b): a
// peer's Dublin Core records harvested page by page into draft units of description in a repository of its own, mapped
// as Heratio maps them, brought up to date and marked deleted as the peer says, each run kept; the union filled with
// an institution's published records and a member's fetched from its union endpoint, served, and searched; and a
// DSpace repository and an Alma catalogue searched by their own protocols beside the other peers.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Federation\FederatedSearch;
use App\Federation\Harvest;
use App\Federation\UnionCatalogue;
use App\Models\Concept;
use App\Models\FederationPeer;
use App\Models\FederationShareSetting;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use App\Search\SearchIndex;
use App\Support\Vocab;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HarvestAndUnionTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private Institution $institution;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        [$this->institution, $this->admin] = $this->institution('Riverside Archives', 'riverside.example.org', 'admin@riverside.example.org');
        config(['workspace.webhooks_check_hosts' => false]);
    }

    /** @return array{0: Institution, 1: User} */
    private function institution(string $name, string $host, string $email): array
    {
        $institution = Institution::create(['name' => $name, 'base_iri' => "https://$host"]);
        app(RoleService::class)->seedDefaults($institution);
        $admin = app(AccountService::class)->create($institution, 'Admin of '.$name, $email, 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($admin, 'administrator');

        return [$institution, $admin];
    }

    private function peer(string $type, array $fields = []): FederationPeer
    {
        return FederationPeer::create(['institution_id' => $this->institution->id, 'name' => ucfirst($type).' peer', 'type_id' => Concept::where('iri', Vocab::BASE.'federation-peer-type/'.$type)->value('id'),
            'is_active' => true, 'search_enabled' => true, ...$fields]);
    }

    private function oai(array $records, ?string $token = null): string
    {
        $items = implode('', array_map(fn ($r) => $r['deleted'] ?? false
            ? '<record><header status="deleted"><identifier>'.$r['id'].'</identifier><datestamp>2026-11-02</datestamp></header></record>'
            : '<record><header><identifier>'.$r['id'].'</identifier><datestamp>2026-11-01</datestamp></header><metadata><oai_dc:dc xmlns:oai_dc="http://www.openarchives.org/OAI/2.0/oai_dc/" xmlns:dc="http://purl.org/dc/elements/1.1/">'
                .'<dc:title>'.$r['title'].'</dc:title><dc:description>'.($r['description'] ?? '').'</dc:description><dc:identifier>https://far.example.net/r/'.$r['n'].'</dc:identifier>'
                .'<dc:identifier>FAR-'.$r['n'].'</dc:identifier><dc:rights>Open to all</dc:rights><dc:format>2 volumes</dc:format><dc:date>1902</dc:date></oai_dc:dc></metadata></record>', $records));

        return '<?xml version="1.0"?><OAI-PMH xmlns="http://www.openarchives.org/OAI/2.0/"><responseDate>2026-11-04T00:00:00Z</responseDate><request>x</request><ListRecords>'.$items
            .($token !== null ? '<resumptionToken>'.$token.'</resumptionToken>' : '<resumptionToken/>').'</ListRecords></OAI-PMH>';
    }

    public function test_an_oai_pmh_peer_is_harvested_into_draft_units_brought_up_to_date_and_deleted_as_it_says(): void
    {
        $peer = $this->peer('oai-pmh', ['base_url' => 'https://far.example.net/oai', 'metadata_prefix' => 'oai_dc', 'harvest_interval_hours' => 24]);
        $pages = [
            'first' => $this->oai([['id' => 'oai:far:1', 'n' => 1, 'title' => 'Letter book', 'description' => 'Letters of the town clerk.'], ['id' => 'oai:far:2', 'n' => 2, 'title' => 'Rate roll']], 'page2'),
            'second' => $this->oai([['id' => 'oai:far:3', 'n' => 3, 'title' => 'Minute book']]),
        ];
        // One fake for the whole test, answering what $answer says: a fake added later would be asked after this one.
        $answer = fn ($request) => str_contains($request->url(), 'resumptionToken=page2') ? $pages['second'] : $pages['first'];
        Http::fake(function ($request) use (&$answer) {
            return Http::response($answer($request), 200, ['Content-Type' => 'text/xml']);
        });

        $session = app(Harvest::class)->run($peer, $this->admin);
        $this->assertSame([3, 3, 0], [$session->records_total, $session->records_created, $session->records_errors], (string) $session->error_message);
        $peer->refresh();
        $this->assertNotNull($peer->harvest_repository_id);
        $unit = RecordResource::find(DB::table('federation_harvested_records')->where(['peer_id' => $peer->id, 'source_identifier' => 'oai:far:1'])->value('record_resource_id'));
        $this->assertSame(['Letter book', 'FAR-1', $peer->harvest_repository_id], [$unit->described('title', ['en']), $unit->identifier, $unit->repository_id]);
        $this->assertSame(['Letters of the town clerk.', 'Open to all', '2 volumes'], [$unit->described('scope-and-content', ['en']), $unit->described('conditions-governing-access', ['en']), $unit->described('extent', ['en'])]);
        $this->assertSame(0, DB::table('search_entries')->where(['subject_id' => $unit->id, 'public' => true])->count(), 'a harvested unit is a draft');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'verb=ListRecords') && str_contains($r->url(), 'metadataPrefix=oai_dc'));

        // The next harvest asks from the last, and brings a record up to date or marks it deleted as the peer says.
        $answer = fn () => $this->oai([['id' => 'oai:far:1', 'n' => 1, 'title' => 'Letter book of the town clerk'], ['id' => 'oai:far:2', 'deleted' => true]]);
        $session = app(Harvest::class)->run($peer->fresh(), $this->admin);
        $this->assertSame([1, 1, 0], [$session->records_updated, $session->records_deleted, $session->records_created]);
        $this->assertSame('Letter book of the town clerk', $unit->fresh()->described('title', ['en']));
        $this->assertNotNull(RecordResource::find(DB::table('federation_harvested_records')->where('source_identifier', 'oai:far:2')->value('record_resource_id'))->deleted_at);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'from='));
        $this->assertSame(['created', 'deleted', 'updated'], DB::table('federation_harvest_log')->join('concepts', 'concepts.id', '=', 'federation_harvest_log.action_id')->distinct()->orderBy('notation')->pluck('notation')->all());

        // An error answer fails the run, kept with its reason.
        $answer = fn () => '<?xml version="1.0"?><OAI-PMH xmlns="http://www.openarchives.org/OAI/2.0/"><error code="badArgument">bad</error></OAI-PMH>';
        $failed = app(Harvest::class)->run($peer->fresh(), $this->admin, true);
        $this->assertStringContainsString('badArgument', (string) $failed->error_message);
        $this->assertSame(3, DB::table('federation_harvest_sessions')->where('peer_id', $peer->id)->count());

        $this->actingAs($this->admin)->get(route('federation.admin'))->assertOk()->assertSee('Harvest it now')->assertSee('Harvests');
    }

    public function test_the_union_catalogue_holds_an_institutions_published_records_and_a_members_and_is_searched(): void
    {
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $published = app(RecordService::class)->create($this->institution, self::LEVEL.'fonds', 'Bridge committee records', 'en', null, $holder, 'BC');
        app(RecordService::class)->create($this->institution, self::LEVEL.'fonds', 'Bridge committee drafts', 'en', null, $holder, 'BD');
        foreach ([$holder, $published] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->admin);
        }
        app(SearchIndex::class)->flush();
        $union = app(UnionCatalogue::class);
        $self = $union->self($this->institution);

        // Nothing in the union until the institution shares and opts in.
        $this->assertSame(0, $union->publish($this->institution));
        FederationShareSetting::for($this->institution->id)->fill(['share_enabled' => true])->save();
        DB::table('federation_members')->where('id', $self->id)->update(['is_enabled' => true]);
        $this->assertSame(1, $union->publish($this->institution));
        $this->assertSame(['Bridge committee records'], DB::table('federation_union_records')->where('member_id', $self->id)->pluck('title')->all());

        // A member's own records, fetched from its union endpoint.
        $member = $union->saveMember($this->institution, ['name' => 'Hillside Archives', 'base_url' => 'https://hillside.example.org', 'is_enabled' => true]);
        Http::fake(['https://hillside.example.org/union/records*' => Http::response(['records' => [['record_ref' => 'https://hillside.example.org/record/9', 'title' => 'Bridge tolls ledger',
            'level' => 'Series', 'url' => 'https://hillside.example.org/record/9']], 'page' => 1, 'last_page' => 1])]);
        $this->assertSame(1, $union->fetch(DB::table('federation_members')->find($member)));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'self=1'));

        // Served, and searched.
        $served = $this->getJson('https://riverside.example.org/union/records?per_page=10')->assertOk()->json();
        $this->assertEqualsCanonicalizing(['Bridge committee records', 'Bridge tolls ledger'], array_column($served['records'], 'title'));
        $this->assertSame(['Bridge committee records'], array_column($this->getJson('https://riverside.example.org/union/records?self=1')->json('records'), 'title'));
        $this->get('https://riverside.example.org/discover/union?q=bridge')->assertOk()->assertSee('Bridge tolls ledger')->assertSee('Hillside Archives')->assertDontSee('drafts');
        $this->actingAs($this->admin)->get(route('federation.admin', ['u' => 'tolls']))->assertOk()->assertSee('Union catalogue')->assertSee('Bridge tolls ledger');

        // Sharing stopped: the institution's own records leave the union.
        FederationShareSetting::for($this->institution->id)->fill(['share_enabled' => false])->save();
        $this->assertSame(0, $union->publish($this->institution));
        $this->assertSame(0, DB::table('federation_union_records')->where('member_id', $self->id)->count());
    }

    public function test_dspace_and_alma_are_searched_by_their_own_protocols(): void
    {
        $this->peer('dspace', ['base_url' => 'https://dspace.example.edu']);
        $this->peer('alma', ['base_url' => 'https://alma.example.edu/view/sru/01UNI', 'config' => ['record_url_template' => 'https://alma.example.edu/record/{mms_id}']]);
        Http::fake([
            'https://dspace.example.edu/server/api/discover/search/objects*' => Http::response(['_embedded' => ['searchResult' => ['_embedded' => ['objects' => [['_embedded' => ['indexableObject' => [
                'uuid' => 'a1', 'handle' => '123/45', 'metadata' => ['dc.title' => [['value' => 'Thesis on bridges']], 'dc.date.issued' => [['value' => '1999']]]]]]]]]]]),
            'https://alma.example.edu/*' => Http::response('<searchRetrieveResponse><records><record><recordData><record xmlns="http://www.loc.gov/MARC21/slim"><controlfield tag="001">99123</controlfield>'
                .'<controlfield tag="008">850101s1902    sa            000 0 eng d</controlfield><datafield tag="245" ind1="1" ind2="0"><subfield code="a">Bridges of the Cape /</subfield></datafield>'
                .'</record></recordData></record></records></searchRetrieveResponse>', 200, ['Content-Type' => 'application/xml']),
        ]);
        $found = app(FederatedSearch::class)->search($this->institution, 'bridges', $this->admin);
        $titles = array_column($found['results'], 'title', 'peer');
        $this->assertSame(['Dspace peer' => 'Thesis on bridges', 'Alma peer' => 'Bridges of the Cape'], $titles);
        $alma = collect($found['results'])->firstWhere('peer', 'Alma peer');
        $this->assertSame(['https://alma.example.edu/record/99123', 'unsigned'], [$alma['url'], $alma['verification']]);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'operation=searchRetrieve') && str_contains(urldecode($r->url()), 'alma.all_for_ui="bridges"'));
    }
}
