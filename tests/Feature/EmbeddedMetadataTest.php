<?php

// OpenRiC Workspace - the embedded metadata of an uploaded file (roadmap phase 5): read with exiftool in a job, kept on the
// master without the server's own facts, shown to those who may receive the master, and what a description can use
// proposed for the record, once.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Files\EmbeddedMetadata;
use App\Jobs\JobsService;
use App\Jobs\ReadEmbeddedMetadata;
use App\Models\BackgroundJob;
use App\Models\Instantiation;
use App\Models\Institution;
use App\Models\Proposal;
use App\Models\RecordResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Imagick;
use ImagickPixel;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class EmbeddedMetadataTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private string $store;

    private Institution $institution;

    private User $editor;

    private User $reader;

    private RecordResource $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        $this->loadBundledVocabularies();
        $accounts = app(AccountService::class);
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        foreach (['editor' => 'editor', 'reader' => 'contributor'] as $who => $role) {
            $this->{$who} = $accounts->create($this->institution, ucfirst($who), "$who@example.org", 'correct horse battery staple');
            $accounts->grantTemplateRole($this->{$who}, $role);
        }
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $this->item = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Council photograph', 'en', null, $holder, 'PH1');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->store)) {
            exec('rm -rf '.escapeshellarg($this->store));
        }
        parent::tearDown();
    }

    /** A photograph whose camera and cataloguer wrote EXIF, IPTC and XMP into it. */
    private function photograph(): UploadedFile
    {
        $image = new Imagick;
        $image->newImage(800, 600, new ImagickPixel('#8a7f6b'));
        $image->setImageFormat('jpeg');
        $path = tempnam(sys_get_temp_dir(), 'jpg');
        file_put_contents($path, $image->getImageBlob());
        $process = new Process(['exiftool', '-overwrite_original', '-q',
            '-XMP-dc:Creator=Jane Ndlovu', '-ExifIFD:DateTimeOriginal=1952:03:03 10:15:00', '-IPTC:Caption-Abstract=The council on the town hall steps',
            '-IFD0:Copyright=Riverside Studio', '-XMP-dc:Subject=council', '-IFD0:Make=Leica', $path]);
        $process->mustRun();
        $file = UploadedFile::fake()->createWithContent('council-1952.jpg', file_get_contents($path));
        unlink($path);

        return $file;
    }

    public function test_an_uploaded_files_metadata_is_kept_on_its_master_and_proposed_for_its_record_once(): void
    {
        Queue::fake();
        $this->actingAs($this->editor)->post("/records/{$this->item->id}/instantiations", ['file' => $this->photograph(), 'language' => 'en', 'availability' => 'online'])
            ->assertSessionHasNoErrors();
        $instantiation = Instantiation::where('record_resource_id', $this->item->id)->firstOrFail();
        $job = BackgroundJob::whereHas('type', fn ($q) => $q->where('notation', 'embedded-metadata'))->sole();
        $this->assertSame('Embedded metadata of council-1952.jpg', $job->name);
        Queue::assertPushed(ReadEmbeddedMetadata::class, fn ($queued) => $queued->jobId === $job->id);

        app()->call([new ReadEmbeddedMetadata($job->id, $instantiation->id), 'handle']);
        $this->assertSame('completed', $job->fresh()->status->notation);
        $metadata = $instantiation->file('master')->fresh()->embedded_metadata;
        $this->assertSame('Jane Ndlovu', $metadata['XMP-dc:Creator']);
        $this->assertSame('Leica', $metadata['IFD0:Make']);
        $this->assertEmpty(array_filter(array_keys($metadata), fn ($tag) => str_starts_with($tag, 'System:') || str_starts_with($tag, 'ExifTool:') || $tag === 'SourceFile'),
            'nothing of the server: no path, owner or permissions');

        $proposals = Proposal::with('kind', 'element')->get()->mapWithKeys(fn ($p) => [$p->kind->notation.($p->element ? ':'.$p->element->notation : '') => $p]);
        $this->assertEqualsCanonicalizing(['agent', 'date', 'value:scope-and-content', 'value:conditions-governing-reproduction'], $proposals->keys()->all());
        $this->assertSame('Jane Ndlovu', $proposals['agent']->value);
        $this->assertSame('1952-03-03', $proposals['date']->value);
        $this->assertSame('The council on the town hall steps', $proposals['value:scope-and-content']->value);
        $this->assertSame('Riverside Studio', $proposals['value:conditions-governing-reproduction']->value);
        $this->assertSame('Embedded in council-1952.jpg as XMP-dc:Creator.', $proposals['agent']->evidence);
        $this->assertSame([ReadEmbeddedMetadata::LABEL, $this->editor->id], [$proposals['agent']->origin_label, $proposals['agent']->proposed_by_id]);
        $this->assertSame(['tags' => count($metadata), 'proposed' => 4], $job->fresh()->output);

        // Read again: nothing is proposed twice.
        $again = app(JobsService::class)->create($this->institution->id, 'embedded-metadata', 'Again', $this->editor, $instantiation);
        app()->call([new ReadEmbeddedMetadata($again->id, $instantiation->id), 'handle']);
        $this->assertSame(0, $again->fresh()->output['proposed']);
        $this->assertSame(4, Proposal::count());

        $this->actingAs($this->editor)->get("/instantiations/{$instantiation->id}")->assertOk()
            ->assertSee('Embedded metadata: '.count($metadata).' tags')->assertSee('XMP-dc:Creator')->assertSee('Leica');
    }

    public function test_metadata_is_shown_only_to_those_who_may_receive_the_master(): void
    {
        $this->actingAs($this->editor)->post("/records/{$this->item->id}/instantiations", ['file' => $this->photograph(), 'language' => 'en', 'availability' => 'online'])
            ->assertSessionHasNoErrors();
        $instantiation = Instantiation::where('record_resource_id', $this->item->id)->firstOrFail();
        $this->assertNotEmpty($instantiation->file('master')->embedded_metadata, 'read before the page answers, without a separate worker');
        // A contributor sees the instantiation and its reference copy, not the master.
        $this->actingAs($this->reader)->get("/instantiations/{$instantiation->id}")->assertOk()->assertDontSee('XMP-dc:Creator')->assertDontSee('Embedded metadata');
    }

    public function test_dates_are_read_as_written_or_not_at_all(): void
    {
        $reader = app(EmbeddedMetadata::class);
        $this->assertSame('1952-03-03', $reader->normaliseIsoDate('1952:03:03 10:15:00'));
        $this->assertSame('1952-03-03', $reader->normaliseIsoDate('1952-03-03T10:15:00+02:00'));
        $this->assertSame('1952', $reader->normaliseIsoDate('1952'));
        $this->assertNull($reader->normaliseIsoDate('0000:00:00 00:00:00'), 'a camera whose clock was never set');
        $this->assertNull($reader->normaliseIsoDate('1952:02:30 10:00:00'));
        $this->assertNull($reader->normaliseIsoDate('spring 1952'));
        $this->assertSame(['date' => ['value' => '1952-03-03', 'tag' => 'IPTC:DateCreated']],
            $reader->normalise(['ExifIFD:DateTimeOriginal' => '0000:00:00 00:00:00', 'IPTC:DateCreated' => '1952:03:03']));
    }
}
