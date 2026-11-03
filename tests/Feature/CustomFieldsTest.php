<?php

// OpenRiC Workspace - the institution's own fields and form templates (roadmap phase 22b): definitions checked, values
// checked by type, shown to the public only where marked, searched where marked, kept in versions, exported and read
// back in the CSV, and a form template that applies inside a collection and describes through it.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\CustomFields\CustomFields;
use App\CustomFields\FormTemplates;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Exchange\DescriptionCsv;
use App\Exchange\ExportView;
use App\Exchange\Import\ImportService;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use App\Models\EntityVersion;
use App\Models\FormTemplate;
use App\Models\Import;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use App\Models\Vocabulary;
use App\Search\SearchService;
use App\Versions\Versions;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomFieldsTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private Institution $institution;

    private User $officer;

    private RecordResource $fonds;

    private RecordResource $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->officer = app(AccountService::class)->create($this->institution, 'Officer', 'officer@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->officer, 'administrator');
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $this->fonds = app(RecordService::class)->create($this->institution, self::LEVEL.'fonds', 'Council records', 'en', null, $holder, 'C');
        $this->file = app(RecordService::class)->create($this->institution, self::LEVEL.'file', 'Minute book', 'en', $this->fonds, null, '1');
        foreach ([$holder, $this->fonds, $this->file] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->officer);
        }
    }

    private function define(array $data): void
    {
        $this->actingAs($this->officer)->post(route('custom-fields.definitions.store'), $data + ['entity_table' => 'record_resources'])->assertSessionHasNoErrors();
    }

    public function test_own_fields_are_checked_shown_searched_versioned_and_exported(): void
    {
        $levels = Vocabulary::where('iri', 'https://workspace.openric.org/vocab/level-of-description/')->sole();
        $this->actingAs($this->officer)->post(route('custom-fields.definitions.store'), ['entity_table' => 'record_resources', 'label' => 'Box', 'field_key' => '1box', 'field_type' => 'text'])
            ->assertSessionHasErrors('fields');
        $this->actingAs($this->officer)->post(route('custom-fields.definitions.store'), ['entity_table' => 'record_resources', 'label' => 'Kind', 'field_type' => 'concept'])
            ->assertSessionHasErrors('fields');
        $this->define(['label' => 'Box number', 'field_type' => 'number', 'is_required' => 1, 'is_searchable' => 1]);
        $this->define(['label' => 'Digitised', 'field_type' => 'boolean']);
        $this->define(['label' => 'Kind of unit', 'field_type' => 'concept', 'vocabulary_id' => $levels->id]);
        $this->define(['label' => 'Shelving note', 'field_type' => 'textarea', 'is_searchable' => 1, 'is_visible_public' => 0, 'validation_rule' => '^Bay \d+']);
        $this->assertSame(['box_number', 'digitised', 'kind_of_unit', 'shelving_note'], CustomFieldDefinition::orderBy('field_key')->pluck('field_key')->all(), 'keys made from labels');

        $save = fn (array $custom) => $this->actingAs($this->officer)->post(route('custom-fields.entity.save', ['records', $this->file->id]), ['custom' => $custom]);
        $save(['box_number' => 'twelve'])->assertSessionHasErrors('fields');
        $save(['box_number' => ''])->assertSessionHasErrors('fields');
        $save(['shelving_note' => 'Top shelf'])->assertSessionHasErrors('fields');
        $save(['box_number' => '12', 'digitised' => '1', 'kind_of_unit' => ['file'], 'shelving_note' => 'Bay 4, strongroom'])->assertSessionHasNoErrors();
        $this->assertSame(4, CustomFieldValue::count());

        // On its page; the public sees what is marked public only, and searches only that.
        $this->actingAs($this->officer)->get(route('records.show', $this->file))->assertSee('Box number')->assertSee('Bay 4, strongroom');
        $this->get(route('discover.record', $this->file->id))->assertOk()->assertSee('Box number')->assertSee('12')->assertDontSee('Bay 4, strongroom');
        $search = app(SearchService::class);
        $this->assertSame(0, $search->search($this->institution, 'strongroom', true)['total']);
        $this->assertSame(1, $search->search($this->institution, 'strongroom', false)['total']);

        // In the record's versions.
        app(Versions::class)->flush();
        $this->assertContains('custom.box_number', EntityVersion::where('subject_id', $this->file->id)->orderByDesc('version_number')->first()->changed_fields);

        // Exported in the CSV as custom_ columns, and read back into a new unit; a key the institution lacks is reported.
        $csv = app(DescriptionCsv::class)->export([$this->file->fresh()], new ExportView($this->officer, true));
        $this->assertStringContainsString('custom_box_number,custom_digitised,custom_kind_of_unit,custom_shelving_note', strtok($csv, "\n"));
        $this->assertStringContainsString(',12,1,file,"Bay 4, strongroom"', $csv);
        $public = app(DescriptionCsv::class)->export([$this->file->fresh()], new ExportView(null, false, null, $this->institution));
        $this->assertStringNotContainsString('shelving', $public);
        $copy = "legacyId,title,levelOfDescription,custom_box_number,custom_digitised,custom_colour\nx1,Rates register,file,7,0,blue\n";
        $import = Import::create(['institution_id' => $this->institution->id, 'user_id' => $this->officer->id, 'format' => 'csv', 'source' => 'test', 'file_name' => 'copy.csv',
            'file_sha256' => hash('sha256', $copy), 'content' => $copy, 'language' => 'en', 'repository_id' => $this->fonds->repository_id, 'parent_id' => $this->fonds->id, 'options' => []]);
        app(ImportService::class)->run($import, true);
        $register = RecordResource::where('parent_id', $this->fonds->id)->get()->first(fn ($r) => $r->described('title', ['en']) === 'Rates register');
        $values = app(CustomFields::class)->values($register);
        $this->assertSame(['7', 'No'], [CustomFields::text($values['box_number']->first()), CustomFields::text($values['digitised']->first())]);
        $this->assertContains('the institution has no field with this key; not read', array_column($import->fresh()->report['issues'], 'problem'));
    }

    public function test_a_form_template_applies_inside_its_collection_and_describes_through_it(): void
    {
        $this->define(['label' => 'Box number', 'field_type' => 'number']);
        $this->actingAs($this->officer)->post(route('custom-fields.templates.store'), ['entity_table' => 'record_resources', 'name' => 'Minute books',
            'fields' => [['target' => 'title'], ['target' => 'scope-and-content', 'label' => 'What the minutes cover', 'is_required' => 1, 'section_name' => 'Content'],
                ['target' => 'custom:box_number', 'default_value' => '1'], ['target' => 'no-such-element']]])->assertSessionHasErrors('forms');
        $this->actingAs($this->officer)->post(route('custom-fields.templates.store'), ['entity_table' => 'record_resources', 'name' => 'Minute books',
            'fields' => [['target' => 'title'], ['target' => 'scope-and-content', 'label' => 'What the minutes cover', 'is_required' => 1, 'section_name' => 'Content'],
                ['target' => 'custom:box_number', 'default_value' => '1']],
            'assignments' => [['collection_iri' => $this->fonds->iri, 'priority' => 10, 'inherit_to_children' => 1]]])->assertRedirect();
        $template = FormTemplate::sole();

        // It applies to what is inside the fonds; nothing applies to the repository's other units.
        $this->assertSame($template->id, app(FormTemplates::class)->resolve($this->file)?->id);
        $other = app(RecordService::class)->create($this->institution, self::LEVEL.'fonds', 'Rates', 'en', null, $this->fonds->repository, 'R');
        $this->assertNull(app(FormTemplates::class)->resolve($other));

        $page = $this->actingAs($this->officer)->get(route('custom-fields.entity', ['records', $this->file->id]))->assertOk();
        $page->assertSee('What the minutes cover')->assertSee('Content')->assertSee('rico:scopeAndContent');
        $post = fn (array $form) => $this->actingAs($this->officer)->post(route('custom-fields.entity.save', ['records', $this->file->id]),
            ['template' => $template->id, 'language' => 'en', 'form' => $form]);
        $post(['title' => 'Minute book', 'custom__box_number' => '3'])->assertSessionHasErrors('fields');
        $post(['title' => 'Minute book, 1902', 'scope-and-content' => 'Council meetings.', 'custom__box_number' => '3'])->assertSessionHasNoErrors();
        $file = $this->file->fresh();
        $this->assertSame(['Minute book, 1902', 'Council meetings.', '3'], [$file->described('title', ['en']), $file->described('scope-and-content', ['en']),
            CustomFields::text(app(CustomFields::class)->values($file)['box_number']->first())]);
    }
}
