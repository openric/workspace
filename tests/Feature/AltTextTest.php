<?php

// OpenRiC Workspace - alternative text for images (roadmap phase 5): the worklist of images without it in a language, with
// its coverage; saving, publishing and clearing one image's text; the text as the image's alt in the catalogue and viewer.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\InstantiationService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Instantiation;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Imagick;
use ImagickPixel;
use Tests\TestCase;

class AltTextTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://archives.example.org';

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private string $store;

    private Institution $institution;

    private User $editor;

    private User $contributor;

    private User $member;

    private RecordResource $record;

    private Instantiation $photo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        $this->loadBundledVocabularies();
        $accounts = app(AccountService::class);
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => self::SITE]);
        app(RoleService::class)->seedDefaults($this->institution);
        foreach (['editor' => 'editor', 'contributor' => 'contributor', 'member' => null] as $who => $role) {
            $this->{$who} = $accounts->create($this->institution, ucfirst($who), "$who@example.org", 'correct horse battery staple');
            if ($role) {
                $accounts->grantTemplateRole($this->{$who}, $role);
            }
        }
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $this->record = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Council photograph', 'en', null, $holder, 'PH1');
        $this->photo = $this->upload('council.png', 'Scan of the photograph', 'png');
        // A PDF is not an image, though it has a picture of its first page.
        $this->upload('minutes.pdf', 'Scan of the minutes', 'pdf');
        foreach ([$holder, $this->record] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->editor);
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->store)) {
            exec('rm -rf '.escapeshellarg($this->store));
        }
        parent::tearDown();
    }

    private function upload(string $name, string $title, string $format): Instantiation
    {
        $image = new Imagick;
        $image->newImage(900, 600, new ImagickPixel('#8a7f6b'));
        $image->setImageFormat($format);
        $path = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($path, $image->getImageBlob());
        $instantiation = app(InstantiationService::class)->upload($this->record, $path, $name, 'en', $title, null, 'online', $this->editor);
        unlink($path);

        return $instantiation;
    }

    public function test_the_worklist_lists_images_without_alternative_text_and_saving_one_publishes_it_to_the_catalogue(): void
    {
        $this->actingAs($this->editor)->get('/alt-text')->assertOk()
            ->assertSee('0 of 1 images have alternative text in en (0%).')->assertSee('Scan of the photograph')->assertDontSee('Scan of the minutes');
        $this->actingAs($this->editor)->get('/records/'.$this->record->id)->assertSee('alt="Scan of the photograph"', false);

        $this->actingAs($this->editor)->post("/alt-text/{$this->photo->id}", ['language' => 'en', 'alt_text' => 'The council on the town hall steps', 'publish' => '1'])
            ->assertRedirect('/alt-text?language=en')->assertSessionHas('status', 'Alternative text saved.');
        $value = $this->photo->descriptionValues()->with('state')->sole();
        $this->assertSame(['The council on the town hall steps', 'en', 'published'], [$value->value, $value->language, $value->state->notation]);
        $this->actingAs($this->editor)->get('/alt-text')->assertSee('1 of 1 images have alternative text in en (100%).')->assertSee('Every image has alternative text in en.');
        $this->actingAs($this->editor)->get('/alt-text?show=all')->assertSee('The council on the town hall steps');

        app(PublicationService::class)->apply($this->photo, 'publish', null, false, $this->editor);
        $this->get(self::SITE."/discover/records/{$this->record->id}")->assertOk()->assertSee('alt="The council on the town hall steps"', false);
        $this->actingAs($this->editor)->get("/instantiations/{$this->photo->id}")->assertSee('alt="The council on the town hall steps"', false);

        // Another language is its own value; the Afrikaans worklist still lists the image.
        $this->actingAs($this->editor)->get('/alt-text?language=af')->assertSee('0 of 1 images have alternative text in af (0%).');
        // A blank text clears it.
        $this->actingAs($this->editor)->post("/alt-text/{$this->photo->id}", ['language' => 'en', 'alt_text' => ''])->assertSessionHas('status', 'Alternative text cleared.');
        $this->assertSame(0, $this->photo->descriptionValues()->count());
    }

    public function test_only_those_who_may_publish_publish_and_only_those_who_may_describe_see_the_worklist(): void
    {
        $this->actingAs($this->contributor)->get('/alt-text')->assertOk();
        $this->actingAs($this->contributor)->post("/alt-text/{$this->photo->id}", ['language' => 'af', 'alt_text' => 'Die raad op die trappe van die stadsaal', 'publish' => '1'])
            ->assertSessionHas('status', 'Alternative text saved.');
        $value = $this->photo->descriptionValues()->with('state')->sole();
        $this->assertSame(['af', 'draft'], [$value->language, $value->state->notation], 'a contributor may not publish');
        $this->get(self::SITE."/discover/records/{$this->record->id}")->assertDontSee('Die raad op die trappe');

        $this->actingAs($this->member)->get('/alt-text')->assertForbidden();
        $this->actingAs($this->member)->post("/alt-text/{$this->photo->id}", ['language' => 'en', 'alt_text' => 'x'])->assertForbidden();
        $this->actingAs($this->editor)->post("/alt-text/{$this->photo->id}", ['language' => 'en', 'alt_text' => str_repeat('x', 2001)])->assertSessionHasErrors('alt_text');

        $other = Institution::create(['name' => 'Hill Museum', 'base_iri' => 'https://hill.example.org']);
        app(RoleService::class)->seedDefaults($other);
        $stranger = app(AccountService::class)->create($other, 'Stranger', 'stranger@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($stranger, 'editor');
        $this->actingAs($stranger)->post("/alt-text/{$this->photo->id}", ['language' => 'en', 'alt_text' => 'x'])->assertNotFound();
        $this->actingAs($stranger)->get('/alt-text')->assertSee('No images.');
    }
}
