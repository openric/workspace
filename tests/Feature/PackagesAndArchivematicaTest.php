<?php

// OpenRiC Workspace - OAIS packages, SWORD and Archivematica (roadmap phase 15d, ADR 0022): a unit put together as a SIP
// in a BagIt bag that validates, carried on into an AIP and a DIP, and a changed package found; a DIP deposited by SWORD,
// as Archivematica uploads one to AtoM, its files kept with the unit, refused without a key with the write scope, in
// another packaging or with an MD5 that does not match; and a unit sent to a faked Archivematica as a transfer, followed
// to its AIP, and its DIP fetched back from the Storage Service and found its unit by the AIP's UUID.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Accounts\ApiKeyService;
use App\Description\InstantiationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Files\FileStore;
use App\Models\ArchivematicaJob;
use App\Models\ArchivematicaLink;
use App\Models\ArchivematicaSettings;
use App\Models\Institution;
use App\Models\Package;
use App\Models\PreservationEvent;
use App\Models\RecordResource;
use App\Models\User;
use App\Preservation\Archivematica\Transfers;
use App\Preservation\Packages;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Imagick;
use ImagickPixel;
use InvalidArgumentException;
use Tests\TestCase;
use ZipArchive;

class PackagesAndArchivematicaTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private const AIP = '7c1e9b02-6f3a-4c2d-9e51-0a8b3d4f5e61';

    private string $dir;

    private Institution $institution;

    private User $admin;

    private RecordResource $fonds;

    private RecordResource $letter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/workspace-15d-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/staging', 0700, true);
        config(['workspace.object_store' => $this->dir.'/objects']);
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->admin = app(AccountService::class)->create($this->institution, 'Admin', 'admin@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->admin, 'administrator');
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $this->fonds = app(RecordService::class)->create($this->institution, self::LEVEL.'fonds', 'Town council', 'en', null, $holder, 'TC');
        $this->letter = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Letter of 3 May 1902', 'en', $this->fonds, null, 'L1');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function png(string $colour): string
    {
        $image = new Imagick;
        $image->newImage(80, 60, new ImagickPixel($colour));
        $image->setImageFormat('png');
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, $image->getImageBlob());

        return $path;
    }

    /** An Archivematica DIP as a ZIP: its METS (OBJID the AIP's UUID, Dublin Core, a PREMIS object) and objects/<file-uuid>-<name>. */
    private function dip(string $identifier = '', string $name = 'deposit.png'): string
    {
        $png = file_get_contents($this->png('#406080'));
        $sha = hash('sha256', $png);
        $dc = $identifier !== '' ? "<dc:identifier>$identifier</dc:identifier>" : '';
        $mets = <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <mets xmlns="http://www.loc.gov/METS/" xmlns:xlink="http://www.w3.org/1999/xlink" OBJID="{$this->aip()}">
              <dmdSec ID="dmdSec_1"><mdWrap MDTYPE="DC"><xmlData><dublincore xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>Deposited scan</dc:title>$dc</dublincore></xmlData></mdWrap></dmdSec>
              <amdSec ID="amdSec_1"><techMD ID="techMD_1"><mdWrap MDTYPE="PREMIS:OBJECT"><xmlData>
                <premis:object xmlns:premis="http://www.loc.gov/premis/v3"><premis:objectIdentifier><premis:objectIdentifierType>UUID</premis:objectIdentifierType>
                  <premis:objectIdentifierValue>2b0c5d1e-1111-4a2b-8c3d-4e5f6a7b8c9d</premis:objectIdentifierValue></premis:objectIdentifier>
                  <premis:objectCharacteristics><premis:fixity><premis:messageDigestAlgorithm>sha256</premis:messageDigestAlgorithm><premis:messageDigest>$sha</premis:messageDigest></premis:fixity>
                  <premis:size>{$this->length($png)}</premis:size><premis:format><premis:formatDesignation><premis:formatName>Portable Network Graphics</premis:formatName></premis:formatDesignation>
                  <premis:formatRegistry><premis:formatRegistryName>PRONOM</premis:formatRegistryName><premis:formatRegistryKey>fmt/11</premis:formatRegistryKey></premis:formatRegistry></premis:format></premis:objectCharacteristics>
                  <premis:originalName>%transferDirectory%objects/$name</premis:originalName></premis:object></xmlData></mdWrap></techMD></amdSec>
              <fileSec><fileGrp USE="original"><file ID="file-1" ADMID="techMD_1" MIMETYPE="image/png"><FLocat LOCTYPE="OTHER" OTHERLOCTYPE="SYSTEM" xlink:href="objects/$name"/></file></fileGrp></fileSec>
            </mets>
            XML;
        $zip = new ZipArchive;
        $path = $this->dir.'/dip-'.bin2hex(random_bytes(4)).'.zip';
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('dip/METS.'.$this->aip().'.xml', ltrim($mets));
        $zip->addFromString('dip/objects/2b0c5d1e-1111-4a2b-8c3d-4e5f6a7b8c9d-'.$name, $png);
        $zip->close();

        return $path;
    }

    private function aip(): string
    {
        return self::AIP;
    }

    private function length(string $bytes): int
    {
        return strlen($bytes);
    }

    public function test_a_unit_is_packaged_as_a_bagit_sip_carried_on_into_an_aip_and_a_dip(): void
    {
        $this->actingAs($this->admin);
        $scan = app(InstantiationService::class)->upload($this->letter, $this->png('#d8c9a3'), 'letter.png', 'en', 'Scan', null, 'online', $this->admin);
        $packages = app(Packages::class);

        $this->post(route('packages.store', $this->fonds), ['type' => 'sip', 'format' => 'zip', 'algorithm' => 'sha256', 'include_descendants' => 1, 'originator' => 'Town clerk'])->assertRedirect();
        $sip = Package::sole();
        $this->assertSame(['sip', 'complete', 'Town clerk'], [$sip->type->notation, $sip->status->notation, $sip->originator]);
        $paths = $sip->files->pluck('relative_path')->all();
        $this->assertContains('data/objects/ra-tc-l1/'.$scan->id.'/master/letter.png', $paths, 'the master of the unit inside');
        $this->assertContains('data/metadata/mets.xml', $paths);
        $this->assertContains('data/metadata/premis.xml', $paths);
        $this->assertContains('tagmanifest-sha256.txt', $paths);

        // The package as kept is a BagIt bag any tool reads: unpacked here, its manifests and Payload-Oxum hold.
        $zip = new ZipArchive;
        $zip->open(app(FileStore::class)->path($sip->storage_path));
        $zip->extractTo($this->dir.'/sip');
        $this->assertStringContainsString('BagIt-Version: 1.0', file_get_contents($this->dir.'/sip/'.$sip->name.'/bagit.txt'));
        $this->assertStringContainsString('External-Identifier: '.$this->fonds->iri, file_get_contents($this->dir.'/sip/'.$sip->name.'/bag-info.txt'));
        $this->assertSame([], Packages::checkBag($this->dir.'/sip/'.$sip->name, 'sha256'));
        // And by the Library of Congress's bagit-python, where BAGIT names it.
        if ($bagit = getenv('BAGIT')) {
            exec(escapeshellarg($bagit).' --validate '.escapeshellarg($this->dir.'/sip/'.$sip->name).' 2>&1', $out, $code);
            $this->assertSame(0, $code, implode("\n", $out));
        }
        $this->assertSame([], $packages->validate($sip, $this->admin));
        $this->assertSame('validated', $sip->fresh()->status->notation);

        // Carried on: an AIP from the SIP, with RiC-O, and a DIP from the AIP, with the copies a reader may use.
        $this->post(route('packages.act', [$sip, 'derive']))->assertRedirect();
        $aip = Package::whereHas('type', fn ($q) => $q->where('notation', 'aip'))->sole();
        $this->assertSame($sip->id, $aip->parent_package_id);
        $this->assertContains('data/metadata/ric-o.jsonld', $aip->files->pluck('relative_path')->all());
        $dip = $packages->derive($aip, $this->admin);
        $this->assertSame(['dip', $aip->id], [$dip->type->notation, $dip->parent_package_id]);
        $this->assertTrue($dip->files->contains(fn ($f) => str_contains($f->relative_path, '/reference/')), 'the reference copy');
        $this->assertFalse($dip->files->contains(fn ($f) => str_contains($f->relative_path, '/master/')), 'not the master');
        $this->assertThrows(fn () => $packages->derive($dip, $this->admin), InvalidArgumentException::class);
        $this->assertSame(['creation'], $dip->events->map(fn ($e) => $e->type->notation)->all());

        // A changed package is found.
        file_put_contents(app(FileStore::class)->path($aip->storage_path), 'not a bag');
        $this->assertNotEmpty($packages->validate($aip->fresh(), $this->admin));
        $this->assertSame('error', $aip->fresh()->status->notation);

        $this->get(route('packages.index', $this->fonds))->assertOk()->assertSee($sip->name)->assertSee('Build a package');
        $this->get(route('packages.show', $sip))->assertOk()->assertSee('data/metadata/premis.xml')->assertSee('Carry it on into an AIP');
        $this->get(route('packages.download', $sip))->assertOk()->assertHeader('X-Content-SHA256', $sip->package_checksum);
        $this->assertSame('exported', $sip->fresh()->status->notation);

        // Removed: its OCFL object purged.
        $this->post(route('packages.act', [$dip, 'remove']))->assertRedirect();
        $this->assertFalse(app(FileStore::class)->ocfl()->exists($this->institution->base_iri.'/package/'.$dip->id));
        $this->assertSame(2, Package::count());
    }

    public function test_a_dip_deposited_by_sword_is_kept_with_its_unit(): void
    {
        [, $secret] = app(ApiKeyService::class)->issue($this->admin, 'Archivematica', ['read', 'write']);
        [, $readOnly] = app(ApiKeyService::class)->issue($this->admin, 'Reader', ['read']);
        $basic = fn (string $key) => ['Authorization' => 'Basic '.base64_encode('admin@example.org:'.$key)];

        $this->get(route('sword.service-root'))->assertStatus(401)->assertHeader('WWW-Authenticate');
        $this->get(route('sword.service-root'), $basic('correct horse battery staple'))->assertStatus(401, 'never the password');
        $this->get(route('sword.service-root'), $basic($readOnly))->assertStatus(401, 'the write scope');
        $doc = $this->get(route('sword.service-root'), $basic($secret))->assertOk()->assertHeader('Content-Type', 'application/atom+xml; charset="utf-8"')->getContent();
        $this->assertStringContainsString('<sword:version>1.3</sword:version>', $doc);
        $this->assertStringContainsString(route('sword.deposit', $this->fonds), $doc);
        $this->assertStringContainsString('http://purl.org/net/sword-types/METSArchivematicaDIP', $doc);
        $this->assertStringContainsString(route('sword.service', $this->fonds), $doc, 'a unit with parts is a service of its own');

        $zip = file_get_contents($this->dip());
        $send = fn (array $headers, string $body) => $this->call('POST', route('sword.deposit', $this->letter), [], [], [], $this->transformHeadersToServerVars($headers), $body);
        $headers = [...$basic($secret), 'X-Packaging' => 'http://purl.org/net/sword-types/METSArchivematicaDIP', 'Content-Type' => 'application/zip',
            'Content-Disposition' => 'filename=dip.zip', 'User-Agent' => 'Archivematica'];
        $send([...$headers, 'X-Packaging' => 'http://purl.org/net/sword-types/METSDSpaceSIP'], $zip)->assertStatus(415);
        $send([...$headers, 'Content-MD5' => base64_encode(md5('something else', true))], $zip)->assertStatus(412);
        $send([...$headers, 'Content-Location' => 'file:///etc/passwd'], '')->assertStatus(400);
        $entry = $send([...$headers, 'Content-MD5' => base64_encode(md5($zip, true))], $zip)->assertStatus(202)->assertHeader('Location', route('records.show', $this->letter))->getContent();
        $this->assertStringContainsString('<sword:packaging>http://purl.org/net/sword-types/METSArchivematicaDIP</sword:packaging>', $entry);

        // Taken in, as every upload is, and what the AIP's PREMIS said kept.
        $kept = $this->letter->instantiations()->with('files.use')->sole();
        $master = $kept->file('master');
        $this->assertSame(['deposit.png', 'image/png'], [$master->original_name, $master->mime_type]);
        $extraction = PreservationEvent::where('digital_file_id', $master->id)->whereHas('type', fn ($q) => $q->where('notation', 'metadata-extraction'))->get()
            ->first(fn ($e) => str_contains((string) $e->detail, self::AIP));
        $this->assertNotNull($extraction);
        $this->assertStringContainsString('PUID: fmt/11', $extraction->outcome_detail);
        $this->assertSame(['linked', 1, self::AIP], [ArchivematicaLink::sole()->status->notation, ArchivematicaLink::sole()->files_taken, ArchivematicaLink::sole()->aip_uuid]);
        $this->assertSame('complete', ArchivematicaJob::sole()->status->notation);

        // A deposit named by Content-Location, in the folder the installation allows.
        mkdir($this->dir.'/deposits/second', 0700, true);
        (new ZipArchive)->open($this->dip('', 'second.png')) && ($z = new ZipArchive) && $z->open($this->dip('', 'second.png')) && $z->extractTo($this->dir.'/deposits/second') && $z->close();
        ArchivematicaSettings::current()->update(['deposit_path' => $this->dir.'/deposits']);
        ArchivematicaLink::query()->delete();
        $send([...$headers, 'Content-Location' => 'file://'.$this->dir.'/deposits/second/dip'], '')->assertStatus(202);
        $this->assertSame(2, $this->letter->instantiations()->count());
    }

    public function test_a_unit_is_sent_to_archivematica_followed_to_its_aip_and_its_dip_fetched_back(): void
    {
        $this->actingAs($this->admin);
        app(InstantiationService::class)->upload($this->letter, $this->png('#d8c9a3'), 'letter.png', 'en', 'Scan', null, 'online', $this->admin);
        ArchivematicaSettings::current()->update(['dashboard_url' => 'https://am.example.org', 'dashboard_username' => 'workspace', 'dashboard_api_key' => 'dashboard-key',
            'storage_url' => 'https://ss.example.org', 'storage_username' => 'workspace', 'storage_api_key' => 'storage-key', 'pipeline_uuid' => 'a1b2c3d4-0000-4000-8000-000000000001',
            'transfer_source_location_uuid' => 'b2c3d4e5-0000-4000-8000-000000000002', 'transfer_source_path' => '/home/transfers', 'staging_path' => $this->dir.'/staging', 'fetch_dips' => true]);
        $transfer = 'c3d4e5f6-0000-4000-8000-000000000003';
        $dip = '9f8e7d6c-0000-4000-8000-000000000004';
        $polls = 0;
        Http::fake(function (HttpRequest $request) use ($transfer, $dip, &$polls) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/api/transfer/start_transfer/' => Http::response(['message' => 'Copy successful.', 'path' => '/var/archivematica/sharedDirectory/watchedDirectories/activeTransfers/standardTransfer/'.ArchivematicaJob::latest()->value('name')]),
                $path === '/api/transfer/unapproved/' => Http::response(['message' => 'Fetched unapproved transfers successfully.', 'results' => [['type' => 'standard', 'directory' => ArchivematicaJob::first()?->name, 'uuid' => $transfer]]]),
                $path === '/api/transfer/approve/' => Http::response(['message' => 'Approval successful.', 'uuid' => $transfer]),
                $path === "/api/transfer/status/$transfer/" => Http::response(['status' => 'COMPLETE', 'name' => 'x', 'microservice' => 'Move to SIP creation directory', 'uuid' => $transfer, 'sip_uuid' => self::AIP]),
                $path === '/api/ingest/status/'.self::AIP.'/' => Http::response(['status' => ++$polls > 0 ? 'COMPLETE' : 'PROCESSING', 'microservice' => 'Store AIP', 'uuid' => self::AIP]),
                $path === '/api/v2/file/' => Http::response(['meta' => ['next' => null], 'objects' => [['uuid' => $dip, 'package_type' => 'DIP', 'status' => 'UPLOADED']]]),
                $path === "/api/v2/file/$dip/download/" => Http::response(file_get_contents($this->dip('', 'returned.png'))),
                default => Http::response(['error' => true, 'message' => "unknown $path"], 404),
            };
        });

        $job = app(Transfers::class)->send($this->fonds, $this->admin);
        $this->assertSame(['processing', $transfer], [$job->status->notation, $job->transfer_uuid], (string) $job->error);
        $this->assertFileExists($this->dir.'/staging/'.$job->name.'/objects/ra-tc-l1/letter.png', 'what the unit includes, staged');
        $this->assertStringContainsString($this->fonds->iri, file_get_contents($this->dir.'/staging/'.$job->name.'/metadata/metadata.csv'));
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/api/transfer/start_transfer/') && $r->hasHeader('Authorization', 'ApiKey workspace:dashboard-key')
            && str_contains(urldecode($r->body()), 'paths[]='.base64_encode('b2c3d4e5-0000-4000-8000-000000000002:/home/transfers/'.$job->name)));

        // Through transfer and ingest to its AIP.
        app(Transfers::class)->poll();
        $this->assertSame(self::AIP, $job->fresh()->sip_uuid);
        app(Transfers::class)->poll();
        $this->assertSame(['complete', self::AIP, 'Store AIP'], [$job->fresh()->status->notation, $job->fresh()->aip_uuid, $job->fresh()->microservice]);

        // Its DIP fetched from the Storage Service, found its unit by the AIP's UUID, and taken in once.
        $links = app(Transfers::class)->fetchDips();
        $this->assertSame(['linked', $this->fonds->id], [$links[0]->status->notation, $links[0]->record_resource_id], (string) $links[0]->note);
        $this->assertSame(['returned.png'], $this->fonds->instantiations()->with('files.use')->get()->map(fn ($i) => $i->file('master')->original_name)->all());
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'ss.example.org') && $r->hasHeader('Authorization', 'ApiKey workspace:storage-key'));
        $this->assertSame([], app(Transfers::class)->fetchDips(), 'not twice');

        $this->get(route('archivematica.index'))->assertOk()->assertSee($dip)->assertSee('Store AIP');
        $this->get(route('packages.index', $this->fonds))->assertOk()->assertSee('Send the unit to Archivematica');
    }
}
