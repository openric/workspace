<?php

// OpenRiC Workspace - the Heratio fields the Workspace lacked (governance/HERATIO-PARITY.md, "Field by field", 28 October 2026).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\ActivityService;
use App\Description\AgentService;
use App\Description\Elements;
use App\Description\InstantiationService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Files\Watermark;
use App\Models\BackgroundJob;
use App\Models\Concept;
use App\Models\Import;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\RecordWatermark;
use App\Models\Right;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FieldParityTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->editor = app(AccountService::class)->create($this->institution, 'Editor', 'editor@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->editor, 'editor');
    }

    public function test_a_description_carries_heratios_fields_with_languages_and_scripts_from_iso_lists(): void
    {
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $letter = app(RecordService::class)->create($this->institution, Vocab::BASE.'level-of-description/item', 'Letter of 3 May 1902', 'en', null, $holder, 'L1');
        $item = Vocab::BASE.'level-of-description/item';

        // A code that is not ISO 639 is refused; the codes are kept once, in no language, whatever language the form is in.
        $this->actingAs($this->editor)->post("/records/{$letter->id}", ['language' => 'en', 'level' => $item, 'identifier' => 'L1',
            'values' => ['title' => 'Letter of 3 May 1902', 'languages-of-material-codes' => ['', 'xx']]])->assertSessionHasErrors('values');
        $this->actingAs($this->editor)->post("/records/{$letter->id}", ['language' => 'en', 'level' => $item, 'identifier' => 'L1', 'values' => [
            'title' => 'Letter of 3 May 1902', 'alternate-title' => "Brief van 3 Mei 1902\nThe Engelbrecht letter", 'edition' => 'Second edition',
            'languages-of-material-codes' => ['', 'af', 'en'], 'scripts-of-material-codes' => ['', 'Latn'], 'institution-identifier' => 'ZA RA',
            'languages-of-description' => ['', 'en'], 'sources-of-description' => 'The accession register.',
        ]])->assertRedirect()->assertSessionHasNoErrors();
        $letter = $letter->fresh();
        $codes = $letter->descriptionValues()->whereHas('element', fn ($q) => $q->where('notation', 'languages-of-material-codes'))->get();
        $this->assertSame([['zxx', 'af en']], $codes->map(fn ($v) => [$v->language, $v->value])->all());
        $this->assertSame(['Brief van 3 Mei 1902', 'The Engelbrecht letter'], $letter->namesOfType('other')->pluck('value')->all());

        // The edit form offers the lists; the page names the codes in the interface language.
        $this->actingAs($this->editor)->get("/records/{$letter->id}/edit?language=af")->assertOk()
            ->assertSee('name="values[languages-of-material-codes][]"', false)->assertSee('<option value="af" selected>Afrikaans (af)</option>', false);
        $this->actingAs($this->editor)->get("/records/{$letter->id}")->assertOk()->assertSeeInOrder(['3.1 ', 'Edition', 'Second edition'], false)
            ->assertSee('Afrikaans, English')->assertSee('Latin')->assertSee('Institution identifier')->assertSee('The accession register.');
        $this->assertSame(['3.1.2', '3.1', '3.7'], array_map(fn ($n) => Elements::number(Elements::of(Elements::ISAD_G)->firstWhere('notation', $n)), ['alternate-title', 'edition', 'institution-identifier']));

        // Published, the public catalogue shows the alternate titles and names the languages.
        foreach ([$holder, $letter] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->editor);
        }
        auth()->logout();
        $this->get("/discover/records/{$letter->id}")->assertOk()->assertSee('Brief van 3 Mei 1902')->assertSee('Afrikaans, English');

        // AtoM's CSV: language and script codes separated by |, the institution identifier as written, the sources.
        $csv = $this->actingAs($this->editor)->get("/records/{$letter->id}/export/csv")->assertOk()->getContent();
        $rows = array_map('str_getcsv', explode("\n", trim($csv)));
        $row = array_combine($rows[0], $rows[1]);
        $this->assertSame(['af|en', 'Latn', 'ZA RA', 'The accession register.'], [$row['language'], $row['script'], $row['institutionIdentifier'], $row['sources']]);
    }

    public function test_an_atom_csv_brings_its_language_codes_and_reports_one_it_does_not_know(): void
    {
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $out = fopen('php://memory', 'r+');
        foreach ([['legacyId', 'title', 'levelOfDescription', 'language', 'script', 'institutionIdentifier', 'sources', 'culture'],
            ['1', 'Council records', 'Fonds', 'en|af|klingon', 'Latn', 'ZA RA', 'The register.', 'en']] as $line) {
            fputcsv($out, $line, escape: '');
        }
        rewind($out);
        $location = $this->actingAs($this->editor)->post('/imports', ['file' => UploadedFile::fake()->createWithContent('f.csv', stream_get_contents($out)),
            'format' => 'csv', 'repository' => $holder->id, 'language' => 'en'])->assertRedirect()->headers->get('Location');
        $import = Import::findOrFail(basename($location));
        $this->assertTrue(collect($import->report['issues'])->contains(fn ($i) => $i['field'] === 'language' && $i['value'] === 'klingon'));
        $this->actingAs($this->editor)->post("/imports/{$import->id}/commit")->assertRedirect();
        $fonds = RecordResource::firstOrFail();
        $this->assertSame('en af', $fonds->described('languages-of-material-codes', ['zxx']));
        $this->assertSame(['ZA RA', 'The register.'], [$fonds->described('institution-identifier', ['en']), $fonds->described('sources-of-description', ['en'])]);
    }

    public function test_new_child_levels_are_added_from_the_edit_form(): void
    {
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $fonds = app(RecordService::class)->create($this->institution, Vocab::BASE.'level-of-description/fonds', 'Council records', 'en', null, $holder, 'CR');
        $form = ['language' => 'en', 'level' => Vocab::BASE.'level-of-description/fonds', 'identifier' => 'CR', 'values' => ['title' => 'Council records']];
        $this->actingAs($this->editor)->get("/records/{$fonds->id}/edit")->assertOk()->assertSee('Add new child levels')->assertSee('name="child_levels[2][title]"', false);

        // A row with a title needs a level; empty rows are passed over.
        $this->actingAs($this->editor)->post("/records/{$fonds->id}", $form + ['child_levels' => [['title' => 'Minutes']]])->assertSessionHasErrors('child_levels.0.level');
        $this->assertSame(0, $fonds->children()->count());
        $this->actingAs($this->editor)->post("/records/{$fonds->id}", $form + ['child_levels' => [
            ['identifier' => 'S1', 'level' => Vocab::BASE.'level-of-description/series', 'title' => 'Minutes'],
            ['identifier' => '', 'level' => '', 'title' => ''],
            ['identifier' => 'S2', 'level' => Vocab::BASE.'level-of-description/series', 'title' => 'Correspondence'],
        ]])->assertRedirect()->assertSessionHasNoErrors();
        $children = $fonds->children()->with('level')->get();
        $this->assertSame([['S1', 'series', 'Minutes'], ['S2', 'series', 'Correspondence']], $children->map(fn ($c) => [$c->identifier, $c->level->notation, $c->described('title', ['en'])])->all());
    }

    public function test_a_repositorys_upload_limit_is_set_by_the_institution_and_refuses_a_file_past_it(): void
    {
        $store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $store]);
        $admin = app(AccountService::class)->create($this->institution, 'Admin', 'admin@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($admin, 'administrator');
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $letter = app(RecordService::class)->create($this->institution, Vocab::BASE.'level-of-description/item', 'Letter', 'en', null, $holder, 'L1');
        $form = ['language' => 'en', 'identifier' => 'RA', 'values' => ['authorised-name' => 'Riverside Archives']];
        $upload = fn () => $this->actingAs($this->editor)->post("/records/{$letter->id}/instantiations", ['title' => 'Notes', 'language' => 'en', 'availability' => 'online',
            'file' => UploadedFile::fake()->createWithContent('notes.txt', str_repeat('x', 600000))]);

        // An editor neither sees nor sets it; the institution's administrator does.
        $this->actingAs($this->editor)->get("/repositories/{$holder->id}/edit")->assertOk()->assertDontSee('Upload limit (GB)');
        $this->actingAs($this->editor)->post("/repositories/{$holder->id}", $form + ['upload_limit_gb' => '0'])->assertRedirect();
        $this->assertNull($holder->fresh()->upload_limit_gb);
        $this->actingAs($admin)->post("/repositories/{$holder->id}", $form + ['upload_limit_gb' => '0'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('0.000', $holder->fresh()->upload_limit_gb);

        // 0 switches uploads off; a limit refuses the file that would pass it, and counts what is kept.
        $upload()->assertSessionHasErrors(['file' => 'Uploads to Riverside Archives are switched off.']);
        $this->actingAs($admin)->post("/repositories/{$holder->id}", $form + ['upload_limit_gb' => '0.001']);
        $upload()->assertSessionHasNoErrors();
        $upload()->assertSessionHasErrors(['file' => 'The file would take Riverside Archives past its upload limit of 0.001 GB (0.00 GB used).']);
        $this->assertSame(600000, app(RepositoryService::class)->usedBytes($holder));
        $this->actingAs($admin)->get("/repositories/{$holder->id}")->assertSee('Upload limit');
        exec('rm -rf '.escapeshellarg($store));
    }

    public function test_an_own_reference_copy_or_thumbnail_replaces_the_one_made_from_the_master(): void
    {
        $store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $store]);
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $letter = app(RecordService::class)->create($this->institution, Vocab::BASE.'level-of-description/item', 'Letter', 'en', null, $holder, 'L1');
        $png = function (string $colour, int $width) {
            $image = new \Imagick;
            $image->newImage($width, 400, new \ImagickPixel($colour));
            $image->setImageFormat('png');

            return $image->getImageBlob();
        };
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, $png('#d8c9a3', 900));
        $scan = app(InstantiationService::class)->upload($letter, $path, 'letter.png', 'en', 'Scan', null, 'online', $this->editor);
        unlink($path);
        $made = $scan->fresh()->file('thumbnail')->sha256;

        $this->actingAs($this->editor)->get("/instantiations/{$scan->id}")->assertSee('Give your own copy');
        $this->actingAs($this->editor)->post("/instantiations/{$scan->id}/own-copy", ['use' => 'thumbnail',
            'file' => UploadedFile::fake()->createWithContent('notes.txt', 'not an image')])->assertSessionHasErrors('file');
        $this->actingAs($this->editor)->post("/instantiations/{$scan->id}/own-copy", ['use' => 'thumbnail',
            'file' => UploadedFile::fake()->createWithContent('cover.png', $png('#10373e', 600))])->assertRedirect()->assertSessionHasNoErrors();
        $scan = $scan->fresh();
        $this->assertNotSame($made, $scan->file('thumbnail')->sha256);
        $this->assertSame(1, $scan->files()->whereHas('use', fn ($q) => $q->where('notation', 'thumbnail'))->count());
        $this->assertSame('letter-thumbnail.jpg', $scan->file('thumbnail')->original_name);
        exec('rm -rf '.escapeshellarg($store));
    }

    public function test_an_authority_record_carries_its_icip_sensitivity_on_screen_and_in_the_public_catalogue(): void
    {
        $agent = app(AgentService::class)->create($this->institution, 'person', 'Engelbrecht, Johanna', 'en');
        $form = ['language' => 'en', 'type' => 'person', 'values' => ['agent-authorised-name' => 'Engelbrecht, Johanna']];
        $this->actingAs($this->editor)->get("/agents/{$agent->id}/edit")->assertOk()->assertSee('ICIP cultural-sensitivity classification')->assertSee('Sacred / secret');
        $this->actingAs($this->editor)->post("/agents/{$agent->id}", $form + ['icip_sensitivity' => 'https://example.org/taboo'])->assertSessionHasErrors('icip_sensitivity');
        $this->actingAs($this->editor)->post("/agents/{$agent->id}", $form + ['icip_sensitivity' => Vocab::BASE.'icip-sensitivity/deceased-persons-content'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('deceased-persons-content', $agent->fresh()->icipSensitivity->notation);
        $this->actingAs($this->editor)->get("/agents/{$agent->id}")->assertSee('ICIP sensitivity: Deceased persons content.');
        $this->actingAs($this->editor)->get("/agents/{$agent->id}?language=af")->assertOk();
        $this->assertSame('Inhoud oor oorlede persone', $agent->fresh()->icipSensitivity->label(['af']));

        // Kept when a save does not carry it, as an import's does not; cleared when chosen so.
        app(AgentService::class)->updateControl($agent->fresh(), ['type' => 'person']);
        $this->assertNotNull($agent->fresh()->icip_sensitivity_id);
        app(PublicationService::class)->apply($agent->fresh(), 'publish', null, false, $this->editor);
        auth()->logout();
        $this->get("/discover/agents/{$agent->id}")->assertOk()->assertSee('ICIP sensitivity')->assertSee('require culturally sensitive handling');
        $this->actingAs($this->editor)->post("/agents/{$agent->id}", $form + ['icip_sensitivity' => ''])->assertRedirect();
        $this->assertNull($agent->fresh()->icip_sensitivity_id);
    }

    public function test_the_source_standard_is_kept_in_the_description_control_area(): void
    {
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $letter = app(RecordService::class)->create($this->institution, Vocab::BASE.'level-of-description/item', 'Letter', 'en', null, $holder, 'L1');
        $this->actingAs($this->editor)->post("/records/{$letter->id}", ['language' => 'en', 'level' => Vocab::BASE.'level-of-description/item', 'identifier' => 'L1',
            'values' => ['title' => 'Letter'], 'description_identifier' => '', 'source_standard' => 'DACS'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('DACS', $letter->fresh()->source_standard);
        $this->actingAs($this->editor)->get("/records/{$letter->id}")->assertSee('Source standard: DACS.');

        $agent = app(AgentService::class)->create($this->institution, 'person', 'Engelbrecht, Johanna', 'en');
        $this->actingAs($this->editor)->post("/agents/{$agent->id}", ['language' => 'en', 'type' => 'person', 'values' => ['agent-authorised-name' => 'Engelbrecht, Johanna'],
            'source_standard' => 'ISAAR(CPF)'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('ISAAR(CPF)', $agent->fresh()->source_standard);
        $this->actingAs($this->editor)->get("/agents/{$agent->id}")->assertSee('Source standard: ISAAR(CPF).');
    }

    public function test_a_description_has_a_watermark_of_its_own_over_the_institutions(): void
    {
        $store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $store]);
        $this->institution->update(['watermark_type_id' => Concept::where('iri', Vocab::BASE.'watermark-type/copyright')->value('id'),
            'watermark_position_id' => Concept::where('iri', Vocab::BASE.'watermark-position/center')->value('id'), 'watermark_opacity' => 40]);
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $letter = app(RecordService::class)->create($this->institution, Vocab::BASE.'level-of-description/item', 'Letter', 'en', null, $holder, 'L1');
        $image = new \Imagick;
        $image->newImage(900, 600, new \ImagickPixel('#d8c9a3'));
        $image->setImageFormat('png');
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, $image->getImageBlob());
        app(InstantiationService::class)->upload($letter, $path, 'letter.png', 'en', 'Scan', null, 'online', $this->editor);
        unlink($path);
        $config = fn () => app(Watermark::class)->configFor($this->institution->fresh(), $letter->fresh());
        $form = ['language' => 'en', 'level' => Vocab::BASE.'level-of-description/item', 'identifier' => 'L1', 'values' => ['title' => 'Letter']];
        $this->assertSame(['© Riverside Archives', 'Center', 40], [$config()['text'], $config()['gravity'], $config()['opacity']], 'the institution\'s');

        $this->actingAs($this->editor)->get("/records/{$letter->id}/edit")->assertOk()->assertSee('Watermark for this description');
        $this->actingAs($this->editor)->post("/records/{$letter->id}", $form + ['watermark' => 'none'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($config());
        // Its own type, position and opacity; and the images' copies made again.
        $jobs = BackgroundJob::count();
        $this->actingAs($this->editor)->post("/records/{$letter->id}", $form + ['watermark' => 'draft', 'watermark_position' => 'repeat', 'watermark_opacity' => 60, 'remake_copies' => 1])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['Draft', 'Repeat', 60], [$config()['text'], $config()['gravity'], $config()['opacity']]);
        $this->assertSame($jobs + 1, BackgroundJob::count());
        $this->actingAs($this->editor)->post("/records/{$letter->id}", $form + ['watermark' => 'nothing'])->assertSessionHasErrors('watermark');
        // Following the institution's again removes its own.
        $this->actingAs($this->editor)->post("/records/{$letter->id}", $form + ['watermark' => ''])->assertRedirect();
        $this->assertSame(0, RecordWatermark::count());
        $this->assertSame('© Riverside Archives', $config()['text']);
        exec('rm -rf '.escapeshellarg($store));
    }

    public function test_a_repositorys_theme_shows_on_its_public_page_without_html(): void
    {
        Storage::fake('local');
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $png = function (string $colour) {
            $image = new \Imagick;
            $image->newImage(600, 120, new \ImagickPixel($colour));
            $image->setImageFormat('png');

            return $image->getImageBlob();
        };
        $this->actingAs($this->editor)->get("/repositories/{$holder->id}")->assertSee('Edit theme');
        $this->actingAs($this->editor)->get("/repositories/{$holder->id}/theme")->assertOk()->assertSee('Background colour');
        $this->actingAs($this->editor)->post("/repositories/{$holder->id}/theme", ['background_colour' => 'red'])->assertSessionHasErrors('background_colour');
        $this->actingAs($this->editor)->post("/repositories/{$holder->id}/theme", ['background_colour' => '#10373E',
            'content' => '**Open** Monday to Friday.<script>alert(1)</script>',
            'banner' => UploadedFile::fake()->createWithContent('banner.png', $png('#e1b34f')), 'logo' => UploadedFile::fake()->createWithContent('logo.png', $png('#10373e'))])
            ->assertRedirect()->assertSessionHasNoErrors();
        $holder = $holder->fresh();
        $this->assertSame('#10373e', $holder->theme_background_colour);
        Storage::disk('local')->assertExists($holder->theme_banner_path);
        $this->actingAs($this->editor)->get("/repositories/{$holder->id}/theme/logo")->assertOk();

        // Not published: the public sees neither the page nor its images.
        auth()->logout();
        $this->get("/discover/repositories/{$holder->id}/theme/banner")->assertNotFound();
        app(PublicationService::class)->apply($holder, 'publish', null, false, $this->editor);
        auth()->logout();
        $this->get("/discover/repositories/{$holder->id}")->assertOk()->assertSee('<strong>Open</strong> Monday to Friday.', false)->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('background: #10373e', false)->assertSee(route('discover.repository.image', [$holder->id, 'logo']));
        $this->get("/discover/repositories/{$holder->id}/theme/banner")->assertOk();

        // Removing the banner removes its file.
        $banner = $holder->theme_banner_path;
        $this->actingAs($this->editor)->post("/repositories/{$holder->id}/theme", ['background_colour' => '#10373e', 'remove_banner' => 1])->assertRedirect();
        $this->assertNull($holder->fresh()->theme_banner_path);
        Storage::disk('local')->assertMissing($banner);
        $this->assertNotNull($holder->fresh()->theme_logo_path);
    }

    public function test_repository_type_contact_coordinates_activity_control_area_and_rights_terms(): void
    {
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $this->actingAs($this->editor)->post("/repositories/{$holder->id}", ['language' => 'en', 'identifier' => 'RA', 'repository_type' => Vocab::BASE.'repository-type/municipal',
            'values' => ['authorised-name' => 'Riverside Archives', 'repository-languages' => ['', 'en'], 'geographic-subregion' => 'Western Cape']])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->post("/repositories/{$holder->id}", ['language' => 'en', 'repository_type' => 'https://example.org/castle',
            'values' => ['authorised-name' => 'Riverside Archives']])->assertSessionHasErrors('repository_type');
        $this->actingAs($this->editor)->post("/repositories/{$holder->id}/contacts", ['label' => 'Reading room', 'latitude' => '-33.9249', 'longitude' => '18.4241', 'note' => 'Enter from Long Street.'])->assertRedirect();
        $this->actingAs($this->editor)->post("/repositories/{$holder->id}/contacts", ['label' => 'Nowhere', 'latitude' => '91'])->assertSessionHasErrors('latitude');
        $this->actingAs($this->editor)->get("/repositories/{$holder->id}")->assertOk()->assertSee('Municipal')->assertSee('Western Cape')->assertSee('Access points')
            ->assertSee('value="-33.924900"', false)->assertSee('Enter from Long Street.');

        $activity = app(ActivityService::class)->create($this->institution, 'Issuing building permits', 'en');
        $this->actingAs($this->editor)->post("/activities/{$activity->id}", ['language' => 'en', 'description_identifier' => 'F-12',
            'description_status' => Vocab::DESCRIPTION_STATUSES.'final', 'values' => ['activity-name' => 'Issuing building permits', 'activity-classification' => '3.2 Planning',
                'activity-sources' => 'The council minutes.']])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->get("/activities/{$activity->id}")->assertOk()->assertSee('F-12')->assertSee('3.2 Planning')->assertSee('The council minutes.');

        $letter = app(RecordService::class)->create($this->institution, Vocab::BASE.'level-of-description/item', 'Letter', 'en', null, $holder, 'L1');
        $this->actingAs($this->editor)->post("/records/{$letter->id}/rights", ['basis' => 'licence', 'licence_terms' => 'May be shown in exhibitions of the council.',
            'policy_identifier' => 'POL-7', 'citation' => 'Deed 4'])->assertRedirect();
        $this->assertSame(['May be shown in exhibitions of the council.', 'POL-7', 'Deed 4'], array_values(Right::firstOrFail()->only(['licence_terms', 'policy_identifier', 'citation'])));
        $this->actingAs($this->editor)->get("/records/{$letter->id}")->assertOk()->assertSee('Licence terms: May be shown in exhibitions of the council.')->assertSee('Policy identifier: POL-7');
    }
}
