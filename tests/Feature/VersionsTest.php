<?php

// OpenRiC Workspace - version history (roadmap phase 22b): a version for each change, none when nothing changed, two
// compared field by field, an earlier one restored with its values and names under their own ids, the restore a new
// version, and the history kept from who may not change the record.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\NameService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\EntityVersion;
use App\Models\Institution;
use App\Models\Name;
use App\Models\User;
use App\Versions\Versions;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class VersionsTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private Institution $institution;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->editor = app(AccountService::class)->create($this->institution, 'Editor', 'editor@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->editor, 'editor');
    }

    public function test_versions_are_written_compared_and_restored(): void
    {
        $versions = app(Versions::class);
        $records = app(RecordService::class);
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $fonds = $records->create($this->institution, self::LEVEL.'fonds', 'Council records', 'en', null, $holder, 'C', $this->editor);
        $versions->flush();
        $history = fn () => EntityVersion::where('subject_id', $fonds->id)->orderBy('version_number')->get();
        $this->assertCount(1, $history());
        $this->assertNull($history()->first()->changed_fields, 'the first version');

        $records->describe($fonds, ['scope-and-content' => 'Minutes and letters.'], 'en', $this->editor);
        app(NameService::class)->add($fonds, ['type' => 'parallel', 'value' => 'Raadsrekords', 'language' => 'af'], $this->editor);
        $versions->flush();
        $this->assertCount(2, $history(), 'one version for the request, not one for each write');
        $this->assertEqualsCanonicalizing(['name.parallel.af', 'value.scope-and-content.en'], $history()->last()->changed_fields);
        $versions->flush();
        $records->describe($fonds, ['scope-and-content' => 'Minutes and letters.'], 'en', $this->editor);
        $versions->flush();
        $this->assertCount(2, $history(), 'nothing changed, no version');

        // Compared, field by field.
        $this->actingAs($this->editor)->get(route('versions.index', ['records', $fonds->id, 'from' => 1, 'to' => 2]))->assertOk()
            ->assertSee('value.scope-and-content.en')->assertSee('Minutes and letters.')->assertSee('Raadsrekords');

        // Version 1 restored: the scope and the parallel name go; the restore is version 3, saying so.
        $this->travel(1)->minutes();
        $records->describe($fonds, ['title' => 'Town council records'], 'en', $this->editor);
        $versions->flush();
        $titleId = Name::where(['subject_id' => $fonds->id, 'language' => 'en'])->whereHas('nameType', fn ($q) => $q->where('notation', 'authorised'))->value('id');
        $this->actingAs($this->editor)->post(route('versions.restore', ['records', $fonds->id]), ['version' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $fonds = $fonds->fresh();
        $this->assertSame('Council records', $fonds->described('title', ['en']));
        $this->assertSame($titleId, Name::where(['subject_id' => $fonds->id, 'language' => 'en'])->whereHas('nameType', fn ($q) => $q->where('notation', 'authorised'))->value('id'), 'the same name, its id kept');
        $this->assertNull($fonds->described('scope-and-content', ['en']));
        $this->assertSame(0, Name::where(['subject_id' => $fonds->id, 'language' => 'af'])->count());
        $last = $history()->last();
        $this->assertSame([4, true, 1], [$last->version_number, $last->is_restore, $last->restored_from_version]);
        $this->assertSame([], $versions->diff($history()->first()->snapshot, $last->snapshot), 'it says what version 1 said');
        $this->actingAs($this->editor)->post(route('versions.restore', ['records', $fonds->id]), ['version' => 9])->assertSessionHasErrors('versions');

        // Kept from another institution and from an account that may not change it.
        $reader = app(AccountService::class)->create($this->institution, 'Reader', 'reader@example.org', 'correct horse battery staple');
        $this->actingAs($reader)->get(route('versions.index', ['records', $fonds->id]))->assertNotFound();
        $other = Institution::create(['name' => 'Elsewhere', 'base_iri' => 'https://elsewhere.example.org']);
        app(RoleService::class)->seedDefaults($other);
        $stranger = app(AccountService::class)->create($other, 'Stranger', 'stranger@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($stranger, 'administrator');
        $this->actingAs($stranger)->post(route('versions.restore', ['records', $fonds->id]), ['version' => 1])->assertNotFound();
    }

    public function test_a_first_version_is_captured_for_what_has_none(): void
    {
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        app(RecordService::class)->create($this->institution, self::LEVEL.'fonds', 'Council records', 'en', null, $holder, 'C');
        EntityVersion::query()->delete();
        Artisan::call('workspace:versions:capture');
        $this->assertSame(1, EntityVersion::count());
        Artisan::call('workspace:versions:capture');
        $this->assertSame(1, EntityVersion::count(), 'captured once');
    }
}
