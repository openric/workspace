<?php

// OpenRiC Workspace - PREMIS events and file formats (roadmap phase 15a): a file kept is an ingestion, its viewing copies
// creations from it, its digest and checks events with their outcome and agent; its format is identified against PRONOM
// by Siegfried; its deletion is an event that outlives it; and the unit's preservation metadata exports as PREMIS 3.0,
// valid against the PREMIS schema where PREMIS_XSD names a copy of it.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\InstantiationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Fixity\Fixity;
use App\Models\DigitalFile;
use App\Models\FileFormat;
use App\Models\Institution;
use App\Models\PreservationEvent;
use App\Vocabulary\SkosImporter;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Imagick;
use ImagickPixel;
use Tests\TestCase;

class PreservationEventsTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private string $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->store)) {
            exec('rm -rf '.escapeshellarg($this->store));
        }
        parent::tearDown();
    }

    public function test_what_is_done_to_a_file_is_kept_as_premis_events_and_exported_as_premis(): void
    {
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($institution);
        $admin = app(AccountService::class)->create($institution, 'Admin', 'admin@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($admin, 'administrator');
        $holder = app(RepositoryService::class)->create($institution, 'Riverside Archives', 'en', 'RA');
        $letter = app(RecordService::class)->create($institution, self::LEVEL.'item', 'Letter of 3 May 1902', 'en', null, $holder, 'L1');
        $image = new Imagick;
        $image->newImage(80, 60, new ImagickPixel('#d8c9a3'));
        $image->setImageFormat('png');
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, $image->getImageBlob());
        $this->actingAs($admin);
        $scan = app(InstantiationService::class)->upload($letter, $path, 'letter.png', 'en', 'Scan of the letter', null, 'online', $admin);
        $master = $scan->file('master');
        $copies = $scan->files()->whereHas('use', fn ($q) => $q->where('notation', '!=', 'master'))->get();
        $this->assertNotEmpty($copies, 'viewing copies made');

        $types = fn (DigitalFile $f) => PreservationEvent::where('digital_file_id', $f->id)->with('type')->orderBy('occurred_at')->get()->map(fn ($e) => $e->type->notation)->all();
        $this->assertContains('ingestion', $types($master));
        $this->assertContains('message-digest-calculation', $types($master));
        $ingest = PreservationEvent::where(['digital_file_id' => $master->id])->whereHas('type', fn ($q) => $q->where('notation', 'ingestion'))->sole();
        $this->assertSame(['person', 'Admin'], [$ingest->agentType->notation, $ingest->agent_name]);
        $creation = PreservationEvent::where(['digital_file_id' => $copies[0]->id])->whereHas('type', fn ($q) => $q->where('notation', 'creation'))->sole();
        $this->assertSame($master->id, $creation->source_file_id, 'a copy is made from the master');

        // Identified against PRONOM where Siegfried is installed.
        $siegfried = trim((string) shell_exec('command -v sf'));
        if ($siegfried !== '') {
            $format = $master->fresh()->format;
            $this->assertNotNull($format, 'the master was identified');
            $this->assertMatchesRegularExpression('#^fmt/\d+$#', $format->puid);
            $this->assertStringContainsString('Portable Network Graphics', $format->name);
            $this->assertContains('format-identification', $types($master));
        }

        // A fixity check is an event.
        app(Fixity::class)->check($master->fresh(), null, $admin);
        $this->assertContains('fixity-check', $types($master));

        // The unit's preservation metadata as PREMIS 3.0.
        $xml = $this->get(route('records.export.download', [$letter, 'premis']))->assertOk()->getContent();
        $dom = new DOMDocument;
        $dom->loadXML($xml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('p', 'http://www.loc.gov/premis/v3');
        $xpath->registerNamespace('xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $this->assertSame(1, $xpath->query('//p:object[@xsi:type="intellectualEntity"]')->length);
        $this->assertSame(1, $xpath->query('//p:object[@xsi:type="representation"]')->length);
        $this->assertSame(1 + $copies->count(), $xpath->query('//p:object[@xsi:type="file"]')->length);
        $this->assertSame($master->sha256, $xpath->query('//p:object[p:objectIdentifier/p:objectIdentifierValue="urn:uuid:'.$master->id.'"]//p:messageDigest')->item(0)->textContent);
        $this->assertSame(PreservationEvent::whereIn('digital_file_id', $scan->files()->pluck('id'))->count(), $xpath->query('//p:event')->length);
        $this->assertGreaterThan(0, $xpath->query('//p:event/p:eventType[@valueURI="http://id.loc.gov/vocabulary/preservation/eventType/ing"]')->length);
        $this->assertSame(1, $xpath->query('//p:object[p:objectIdentifier/p:objectIdentifierValue="urn:uuid:'.$copies[0]->id.'"]/p:relationship[p:relationshipSubType="has source"]')->length);
        if ($xsd = getenv('PREMIS_XSD')) {
            libxml_use_internal_errors(true);
            $this->assertTrue($dom->schemaValidate($xsd), 'valid PREMIS 3.0: '.implode('; ', array_map(fn ($e) => trim($e->message), libxml_get_errors())));
        }

        // Removed: the deletion is an event, and the events outlive the file.
        $id = $copies[0]->id;
        $copies[0]->delete();
        $this->assertContains('deletion', PreservationEvent::where('digital_file_id', $id)->with('type')->get()->map(fn ($e) => $e->type->notation)->all());

        // The formats page; a format's risk and action set by an operator.
        if ($siegfried !== '') {
            $this->get(route('formats.index'))->assertOk()->assertSee($master->fresh()->format->puid);
            $this->post(route('formats.update', $master->fresh()->format), ['risk' => 'low', 'action' => 'monitor', 'is_preservation_format' => 1])->assertRedirect();
            $png = FileFormat::find($master->fresh()->format_id);
            $this->assertSame(['low', 'monitor', true], [$png->risk->notation, $png->action->notation, $png->is_preservation_format]);
        }
        $this->get(route('instantiations.show', $scan))->assertOk()->assertSee('Preservation')->assertSee('Ingestion');
    }
}
