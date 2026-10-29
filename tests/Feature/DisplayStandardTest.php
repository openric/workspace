<?php

// OpenRiC Workspace - display standards (AtoM's and Heratio's display_standard_id): a unit shown and edited in ISAD(G),
// DACS, RAD, Dublin Core or MODS, with each standard's numbers, labels and areas over the same values, the elements
// only DACS, RAD and MODS have, their lists of types, and the choice passed down to the units inside.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\Elements;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DisplayStandardTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://archives.example.org';

    private const FONDS = Vocab::BASE.'level-of-description/fonds';

    private User $editor;

    private RecordResource $fonds;

    private RecordResource $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        $institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => self::SITE]);
        app(RoleService::class)->seedDefaults($institution);
        $this->editor = app(AccountService::class)->create($institution, 'Editor', 'editor@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->editor, 'editor');
        $holder = app(RepositoryService::class)->create($institution, 'Riverside Archives', 'en', 'RA');
        $this->fonds = app(RecordService::class)->create($institution, self::FONDS, 'Riverside Survey maps', 'en', null, $holder, 'RS');
        $this->file = app(RecordService::class)->create($institution, Vocab::BASE.'level-of-description/file', 'Sheet 1', 'en', $this->fonds, $holder, 'RS/1');
    }

    private function save(array $fields): TestResponse
    {
        return $this->actingAs($this->editor)->post("/records/{$this->fonds->id}", ['language' => 'en', 'level' => self::FONDS, 'identifier' => 'RS'] + $fields);
    }

    public function test_dacs_shows_the_same_values_under_its_numbers_and_keeps_its_own(): void
    {
        $this->actingAs($this->editor)->get("/records/{$this->fonds->id}/edit")->assertOk()->assertSee('3.3.1 Scope and content')->assertDontSee('Technical access');
        $this->save(['values' => ['scope-and-content' => 'Survey sheets of the river.'], 'display_standard' => 'dacs'])->assertSessionHasNoErrors();
        $this->assertSame('dacs', $this->fonds->fresh()->standard());

        $this->actingAs($this->editor)->get("/records/{$this->fonds->id}/edit")->assertOk()
            ->assertSee('3 Content and structure elements')->assertSee('3.1 Scope and content')->assertSee('4.3 Technical access')->assertSee('Survey sheets of the river.');
        $this->save(['values' => ['technical-access' => 'A map table is needed.']])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->get("/records/{$this->fonds->id}")->assertOk()->assertSee('4 Conditions of access and use elements')->assertSee('A map table is needed.');

        // Back in ISAD(G) the DACS element is not shown, and is kept.
        $this->save(['display_standard' => ''])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->get("/records/{$this->fonds->id}")->assertOk()->assertSee('3.3.1 Scope and content')->assertDontSee('A map table is needed.');
        $this->assertSame('A map table is needed.', $this->fonds->fresh()->described('technical-access', ['en']));
    }

    public function test_rad_has_its_areas_elements_and_material_designations(): void
    {
        $this->save(['display_standard' => 'rad'])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->get("/records/{$this->fonds->id}/edit")->assertOk()
            ->assertSee('1.1 Title and statement of responsibility area')->assertSee('1.1B Title proper')->assertSee('1.3 Statement of scale (cartographic)')
            ->assertSee('Control area')->assertSee('General material designation')->assertSee('Cartographic material');
        $this->save(['types_shown' => '1', 'types' => ['cartographic-material'], 'values' => ['statement-of-scale-cartographic' => '1:50 000']])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->get("/records/{$this->fonds->id}")->assertOk()->assertSee('Cartographic material')->assertSee('1:50 000');
        $this->save(['types_shown' => '1', 'types' => ['no-such-type']])->assertSessionHasErrors();
        $this->assertSame(['cartographic-material'], $this->fonds->resourceTypes()->with('type')->get()->pluck('type.notation')->all());
    }

    public function test_dublin_core_and_mods_types_are_exported_and_the_choice_is_passed_down(): void
    {
        $this->save(['display_standard' => 'dc', 'display_standard_inside' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('dc', $this->file->fresh()->standard());
        $this->save(['types_shown' => '1', 'types' => ['still-image']])->assertSessionHasNoErrors();
        $this->assertStringContainsString('<dc:type>Still image</dc:type>', $this->actingAs($this->editor)->get("/records/{$this->fonds->id}/export/dc")->assertOk()->getContent());

        $this->save(['display_standard' => 'mods'])->assertSessionHasNoErrors();
        $this->assertSame('dc', $this->file->fresh()->standard());
        $this->save(['types_shown' => '1', 'types' => ['cartographic']])->assertSessionHasNoErrors();
        $mods = $this->actingAs($this->editor)->get("/records/{$this->fonds->id}/export/mods")->assertOk()->getContent();
        $this->assertStringContainsString('>cartographic</typeOfResource>', $mods);
        $this->assertStringNotContainsString('mixed material', $mods);

        // MODS shows its fifteen elements, and the public page shows the unit in it.
        $this->assertCount(15, Elements::of('mods'));
        foreach ([$this->fonds->repository, $this->fonds] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->editor);
        }
        auth()->logout();
        $this->get(self::SITE."/discover/records/{$this->fonds->id}")->assertOk()->assertSee('Riverside Survey maps');
    }
}
