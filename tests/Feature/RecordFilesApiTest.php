<?php

// OpenRiC Workspace - a record's files and their text through the API (ADR 0007, step 4): only what a visitor may
// have, at the public catalogue's own addresses; masters never; text only once published, and only of online files.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\InstantiationService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Imagick;
use ImagickPixel;
use Tests\TestCase;

class RecordFilesApiTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://archives.example.org/api/ric/v1';

    private string $store;

    private User $editor;

    private RecordResource $record;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        $this->loadBundledVocabularies();
        $institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($institution);
        $this->editor = app(AccountService::class)->create($institution, 'Editor', 'editor@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->editor, 'editor');
        $holder = app(RepositoryService::class)->create($institution, 'Riverside Archives', 'en', 'RA');
        $this->record = app(RecordService::class)->create($institution, 'https://workspace.openric.org/vocab/level-of-description/item', 'Minute book, 1902', 'en', null, $holder, 'MB1');
        app(PublicationService::class)->apply($holder, 'publish', null, false, $this->editor);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->store)) {
            exec('rm -rf '.escapeshellarg($this->store));
        }
        parent::tearDown();
    }

    private function scan(): string
    {
        $image = new Imagick;
        $image->newImage(2400, 1800, new ImagickPixel('#d8c9a3'));
        $image->setImageFormat('png');
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, $image->getImageBlob());

        return $path;
    }

    public function test_a_visitor_gets_the_reference_copy_and_thumbnail_and_the_published_transcript(): void
    {
        $service = app(InstantiationService::class);
        $scan = $service->upload($this->record, $this->scan(), 'page-1.png', 'en', 'Scan of page 1', null, 'online', $this->editor);
        $service->describe($scan, ['instantiation-transcript' => 'Minutes of the meeting of 3 May 1902.'], 'en', $this->editor);
        $kept = $service->upload($this->record, $this->scan(), 'page-2.png', 'en', 'Scan of page 2', null, 'on-request', $this->editor);
        $service->describe($kept, ['instantiation-transcript' => 'Not for visitors.'], 'en', $this->editor);

        // Not published: the record does not exist for the API.
        $this->getJson(self::API."/records/{$this->record->id}/files")->assertNotFound();

        app(PublicationService::class)->apply($this->record, 'publish', null, false, $this->editor);
        $body = $this->getJson(self::API."/records/{$this->record->id}/files")->assertOk()->json();
        $this->assertSame([$this->record->id, 'Minute book, 1902'], [$body['record'], $body['title']]);
        // The on-request scan is held back, its text with it.
        $this->assertCount(1, $body['instantiations']);
        [$page] = $body['instantiations'];
        $this->assertSame(['Scan of page 1', [['language' => 'en', 'value' => 'Minutes of the meeting of 3 May 1902.']]], [$page['title'], $page['text']]);
        $this->assertEqualsCanonicalizing(['reference', 'thumbnail'], array_column($page['files'], 'use'));
        foreach ($page['files'] as $file) {
            $this->assertStringStartsWith('/discover/files/', $file['path']);
            $this->assertSame('https://archives.example.org'.$file['path'], $file['url']);
            $this->get($file['url'])->assertOk()->assertHeader('Content-Type', $file['mime_type']);
        }
        $this->assertStringNotContainsString('Not for visitors', json_encode($body));

        // A transcript changed since is a draft again, as any value is: no text until the record is published again.
        $service->describe($scan, ['instantiation-transcript' => 'Minutes of the meeting of 3 May 1902, corrected.'], 'en', $this->editor);
        $this->assertSame([], $this->getJson(self::API."/records/{$this->record->id}/files")->json('instantiations.0.text'));
        app(PublicationService::class)->apply($this->record, 'publish', null, false, $this->editor);
        $this->assertSame('Minutes of the meeting of 3 May 1902, corrected.', $this->getJson(self::API."/records/{$this->record->id}/files")->json('instantiations.0.text.0.value'));
    }
}
