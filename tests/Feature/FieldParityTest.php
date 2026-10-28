<?php

// OpenRiC Workspace - the Heratio fields the Workspace lacked (governance/HERATIO-PARITY.md, "Field by field", 28 October 2026).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\ActivityService;
use App\Description\Elements;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Import;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\Right;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
