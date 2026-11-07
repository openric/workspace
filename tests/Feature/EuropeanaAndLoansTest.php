<?php

// OpenRiC Workspace - the Europeana EDM export and loans between union members (roadmap phase 16d): a published unit with
// its creator, place and image exported as EDM (its ProvidedCHO, Aggregation, WebResources, Agent and Place, its EDM
// type from its files and its rights from the right in force, else the default), a draft never, in a bundle with a
// sitemap kept and downloaded; and a loan moved only along Heratio's transitions, with its figures.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\AgentService;
use App\Description\InstantiationService;
use App\Description\PlaceService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Federation\Loans;
use App\Federation\UnionCatalogue;
use App\Files\FileStore;
use App\Models\FederationLoanRequest;
use App\Models\FederationShareSetting;
use App\Models\Institution;
use App\Models\User;
use App\Relations\RelationService;
use App\Rights\RightsService;
use App\Search\SearchIndex;
use App\Vocabulary\SkosImporter;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Imagick;
use ImagickPixel;
use InvalidArgumentException;
use Tests\TestCase;
use ZipArchive;

class EuropeanaAndLoansTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private Institution $institution;

    private User $admin;

    private string $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-16d-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://riverside.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->admin = app(AccountService::class)->create($this->institution, 'Admin', 'admin@riverside.example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->admin, 'administrator');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->store));
        parent::tearDown();
    }

    public function test_published_units_are_exported_to_europeana_as_edm(): void
    {
        $this->actingAs($this->admin);
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $letter = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Letter of 3 May 1902', 'en', null, $holder, 'L1');
        $draft = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'A draft letter', 'en', null, $holder, 'L2');
        $clerk = app(AgentService::class)->create($this->institution, 'person', 'Botha, Maria', 'en');
        $town = app(PlaceService::class)->create($this->institution, 'Riverside', 'en');
        $town->update(['latitude' => -25.7479, 'longitude' => 28.2293]);
        app(RelationService::class)->create($letter, 'creation', $clerk, [], $this->admin);
        app(RelationService::class)->create($letter, 'subject', $town, [], $this->admin);
        $image = new Imagick;
        $image->newImage(80, 60, new ImagickPixel('#d8c9a3'));
        $image->setImageFormat('png');
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, $image->getImageBlob());
        $scan = app(InstantiationService::class)->upload($letter, $path, 'letter.png', 'en', 'Scan', null, 'online', $this->admin);
        app(RightsService::class)->add($letter, ['basis' => 'copyright', 'statement' => 'NoC-NC', 'copyright_status' => 'public-domain'], $this->admin);
        foreach ([$holder, $letter, $clerk, $town, $scan] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->admin);
        }
        app(SearchIndex::class)->flush();
        FederationShareSetting::for($this->institution->id)->fill(['europeana_country' => 'South Africa', 'europeana_default_rights' => 'http://rightsstatements.org/vocab/CNE/1.0/'])->save();

        $this->post(route('federation.europeana.run'))->assertRedirect();
        $run = \DB::table('europeana_exports')->sole();
        $this->assertSame(1, $run->record_count, (string) $run->error);
        $zip = new ZipArchive;
        $zip->open(app(FileStore::class)->path($run->storage_path));
        $this->assertNotFalse($zip->locateName('sitemap.xml'));
        $this->assertStringStartsWith('entity,identifier,kind,what,value', (string) $zip->getFromName('left-out.csv'), 'what EDM leaves out, beside the files');
        $this->assertFalse($zip->locateName('records/'.$draft->id.'.xml'), 'a draft never');
        $edm = new DOMDocument;
        $edm->loadXML($zip->getFromName('records/'.$letter->id.'.xml'));
        $xp = new DOMXPath($edm);
        foreach (['edm' => 'http://www.europeana.eu/schemas/edm/', 'ore' => 'http://www.openarchives.org/ore/terms/', 'dc' => 'http://purl.org/dc/elements/1.1/',
            'rdf' => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#', 'skos' => 'http://www.w3.org/2004/02/skos/core#', 'wgs84_pos' => 'http://www.w3.org/2003/01/geo/wgs84_pos#'] as $p => $ns) {
            $xp->registerNamespace($p, $ns);
        }
        $this->assertSame('Letter of 3 May 1902', $xp->evaluate('string(//edm:ProvidedCHO/dc:title)'));
        $this->assertSame('IMAGE', $xp->evaluate('string(//edm:ProvidedCHO/edm:type)'));
        $this->assertSame($clerk->iri, $xp->evaluate('string(//edm:ProvidedCHO/dc:creator/@rdf:resource)'));
        $this->assertSame('Botha, Maria', $xp->evaluate('string(//edm:Agent/skos:prefLabel)'));
        $this->assertSame('-25.7479', $xp->evaluate('string(//edm:Place/wgs84_pos:lat)'));
        $this->assertSame($letter->iri, $xp->evaluate('string(//ore:Aggregation/edm:isShownAt/@rdf:resource)'));
        $this->assertSame(route('discover.file', $scan->file('reference')->id), $xp->evaluate('string(//ore:Aggregation/edm:isShownBy/@rdf:resource)'));
        $this->assertStringContainsString('NoC-NC', $xp->evaluate('string(//ore:Aggregation/edm:rights/@rdf:resource)'), 'the right in force, not the default');
        $this->assertSame('Riverside Archives', $xp->evaluate('string(//ore:Aggregation/edm:dataProvider)'));
        $this->assertGreaterThan(0, $xp->query('//edm:WebResource')->length);

        $this->get(route('federation.europeana.download', $run->id))->assertOk()->assertHeader('X-Content-SHA256', $run->bundle_checksum);
        $this->get(route('federation.europeana'))->assertOk()->assertSee('Export now');
    }

    public function test_a_loan_moves_only_along_its_transitions_and_is_counted(): void
    {
        $union = app(UnionCatalogue::class);
        $self = $union->self($this->institution);
        $hillside = $union->saveMember($this->institution, ['name' => 'Hillside Archives', 'base_url' => 'https://hillside.example.org', 'is_enabled' => true]);
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $map = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Map of the bridge, 1902', 'en', null, $holder, 'M1');
        $loans = app(Loans::class);

        $this->actingAs($this->admin)->post(route('federation.loans.store'), ['holding_member_id' => $self->id, 'requesting_member_id' => $hillside, 'record_resource_id' => $map->id,
            'purpose' => 'Exhibition', 'needed_from' => '2027-02-01', 'needed_to' => '2027-03-31'])->assertSessionHasNoErrors();
        $loan = FederationLoanRequest::sole();
        $this->assertSame(['Map of the bridge, 1902', $map->iri, 'requested'], [$loan->item_title, $loan->item_ref, $loan->status->notation]);
        $this->assertThrows(fn () => $loans->transition($loan, 'returned', null, $this->admin), InvalidArgumentException::class);
        $this->assertThrows(fn () => $loans->create($this->institution, ['holding_member_id' => $self->id, 'requesting_member_id' => $self->id, 'item_ref' => 'x'], $this->admin), InvalidArgumentException::class);
        $this->assertThrows(fn () => $loans->create($this->institution, ['holding_member_id' => $self->id, 'requesting_member_id' => $hillside, 'item_ref' => 'x', 'needed_from' => '2027-03-01', 'needed_to' => '2027-02-01'], $this->admin), InvalidArgumentException::class);

        $this->post(route('federation.loans.move', [$loan, 'approved']), ['note' => 'Insured by the borrower.'])->assertRedirect();
        $this->post(route('federation.loans.move', [$loan, 'in-transit']))->assertRedirect();
        $this->post(route('federation.loans.move', [$loan, 'returned']))->assertRedirect();
        $loan->refresh();
        $this->assertSame(['returned', $this->admin->id], [$loan->status->notation, $loan->decided_by_id]);
        $this->assertStringContainsString('approved: Insured by the borrower.', $loan->notes);

        $declined = $loans->create($this->institution, ['holding_member_id' => $hillside, 'requesting_member_id' => $self->id, 'item_ref' => 'HA/9', 'item_title' => 'Tolls ledger'], $this->admin);
        $loans->transition($declined, 'declined', 'Too fragile.', $this->admin);
        $report = $loans->report($this->institution);
        $this->assertSame([2, 1, 1, 1, 1, 50.0], [$report['total'], $report['by_status']['returned'], $report['by_status']['declined'], $report['outgoing'], $report['incoming'], $report['approval_rate']]);
        $this->get(route('federation.loans'))->assertOk()->assertSee('Map of the bridge, 1902')->assertSee('Tolls ledger');
    }
}
