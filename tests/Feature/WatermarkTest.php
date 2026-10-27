<?php

// OpenRiC Workspace - the watermark (roadmap phase 5): set by an administrator, written on an image's reference copy only,
// never on the master or the thumbnail, skipped below the minimum size, and the viewing copies made again after a change.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\InstantiationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Jobs\MakeViewingCopies;
use App\Models\BackgroundJob;
use App\Models\Instantiation;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Imagick;
use ImagickPixel;
use Tests\TestCase;

class WatermarkTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private string $store;

    private Institution $institution;

    private User $admin;

    private User $editor;

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
        foreach (['admin' => 'administrator', 'editor' => 'editor'] as $who => $role) {
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

    /** A plain grey image, so any watermark changes its pixels. */
    private function photo(int $width = 1200, int $height = 900): string
    {
        $image = new Imagick;
        $image->newImage($width, $height, new ImagickPixel('#808080'));
        $image->setImageFormat('png');
        $path = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($path, $image->getImageBlob());

        return $path;
    }

    private function upload(string $path): Instantiation
    {
        $instantiation = app(InstantiationService::class)->upload($this->item, $path, 'council.png', 'en', null, null, 'online', $this->editor);
        unlink($path);

        return $instantiation->fresh();
    }

    /** How far the image is from plain grey: 0 for none of it changed. */
    private function marked(Instantiation $instantiation, string $use): float
    {
        $image = new Imagick(app(InstantiationService::class)->path($instantiation->file($use)));
        $plain = new Imagick;
        $plain->newImage($image->getImageWidth(), $image->getImageHeight(), new ImagickPixel('#808080'));
        $plain->setImageFormat('png');

        return $image->compareImages($plain, Imagick::METRIC_MEANABSOLUTEERROR)[1];
    }

    private function save(array $settings): TestResponse
    {
        return $this->actingAs($this->admin)->post('/settings/watermark', $settings + ['position' => 'center', 'opacity' => 40, 'min_size' => 200]);
    }

    public function test_the_reference_copy_of_an_image_carries_the_watermark_and_the_master_and_thumbnail_do_not(): void
    {
        $this->actingAs($this->admin)->get('/settings/watermark')->assertOk()->assertSee('Copyright')->assertSee('Repeated across the image');
        $this->save(['type' => 'copyright', 'position' => 'repeat'])->assertRedirect('/settings/watermark')->assertSessionHasNoErrors();
        $this->assertSame(['copyright', 'repeat', 40], [$this->institution->fresh()->watermarkType->notation, $this->institution->fresh()->watermarkPosition->notation, $this->institution->fresh()->watermark_opacity]);

        $photo = $this->upload($this->photo());
        $this->assertGreaterThan(0.01, $this->marked($photo, 'reference'), 'marked');
        $this->assertSame(0.0, $this->marked($photo, 'master'), 'the master is as received');
        $this->assertLessThan(0.001, $this->marked($photo, 'thumbnail'));

        // Below the minimum size, no mark.
        $small = $this->upload($this->photo(150, 120));
        $this->assertLessThan(0.001, $this->marked($small, 'reference'));
    }

    public function test_after_a_change_the_viewing_copies_are_made_again(): void
    {
        $photo = $this->upload($this->photo());
        $this->assertLessThan(0.001, $this->marked($photo, 'reference'), 'no watermark yet');
        $before = $photo->file('reference')->id;

        $this->save(['type' => 'draft', 'position' => 'bottom-right']);
        Queue::fake();
        $this->actingAs($this->admin)->post('/settings/watermark/remake')->assertSessionHas('status', 'The viewing copies of 1 image are being made again.');
        $job = BackgroundJob::where('name', 'Viewing copies of council.png, again')->sole();
        Queue::assertPushed(MakeViewingCopies::class, fn ($queued) => $queued->jobId === $job->id && $queued->remake);
        app()->call([new MakeViewingCopies($job->id, $photo->id, true), 'handle']);
        $this->assertSame(['copies' => 2], $job->fresh()->output);

        $photo = $photo->fresh();
        $this->assertNotSame($before, $photo->file('reference')->id);
        $this->assertCount(3, $photo->files);
        $this->assertGreaterThan(0.001, $this->marked($photo, 'reference'));

        // One instantiation, by an editor; not by a stranger.
        $this->actingAs($this->editor)->post("/instantiations/{$photo->id}/copies")->assertRedirect("/instantiations/{$photo->id}")
            ->assertSessionHas('status', 'The viewing copies are being made again.');
        $this->actingAs($this->editor)->get('/settings/watermark')->assertForbidden();
    }

    public function test_a_custom_watermark_needs_its_image_and_settings_are_checked(): void
    {
        $this->save(['type' => 'custom'])->assertSessionHasErrors('image');
        $this->assertNull($this->institution->fresh()->watermark_type_id);
        $this->save(['type' => 'copyright', 'opacity' => 0])->assertSessionHasErrors('opacity');
        $this->save(['type' => 'copyright', 'position' => 'middle'])->assertSessionHasErrors('position');

        $logo = new Imagick;
        $logo->newImage(200, 80, new ImagickPixel('#ffffff'));
        $logo->setImageFormat('png');
        $this->save(['type' => 'custom', 'image' => UploadedFile::fake()->createWithContent('logo.png', $logo->getImageBlob())])->assertSessionHasNoErrors();
        $this->assertNotNull($this->institution->fresh()->watermark_image_path);
        $photo = $this->upload($this->photo());
        $this->assertGreaterThan(0.001, $this->marked($photo, 'reference'));

        $this->save(['type' => ''])->assertSessionHasNoErrors();
        $this->assertNull($this->institution->fresh()->watermark_type_id, 'none');
    }
}
