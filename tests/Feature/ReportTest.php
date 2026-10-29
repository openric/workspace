<?php

// OpenRiC Workspace - reports and statistics (roadmap phase 8): the overview, data quality, and the lists with their filters and CSV.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\AgentService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportTest extends TestCase
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

    public function test_the_overview_quality_and_lists_count_what_the_catalogue_holds_and_export_as_csv(): void
    {
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $fonds = app(RecordService::class)->create($this->institution, Vocab::BASE.'level-of-description/fonds', 'Council records', 'en', null, $holder, 'C1');
        app(RecordService::class)->create($this->institution, Vocab::BASE.'level-of-description/item', 'Minutes of 1952', 'en', $fonds, $holder);
        app(AgentService::class)->create($this->institution, 'person', 'Botha, Maria', 'en');
        app(PublicationService::class)->apply($fonds, 'publish', null, false, $this->editor);

        $this->actingAs($this->editor)->get('/reports')->assertOk()
            ->assertSeeInOrder(['Archival descriptions', '<td>2</td>', '1 (50%)'], false)
            ->assertSee('Riverside Archives')->assertSee(now()->format('Y-m'));

        // The reference code is kept apart from the values, and only one unit has one.
        $this->actingAs($this->editor)->get('/reports/quality')->assertOk()
            ->assertSeeInOrder(['3.1.1 Reference code', '1 (50%)', '3.1.2'], false);

        // Published or not, as the public catalogue decides; with the level filter.
        $this->actingAs($this->editor)->get('/reports/descriptions?published=yes')->assertOk()->assertSee('Council records')->assertDontSee('Minutes of 1952');
        $this->actingAs($this->editor)->get('/reports/descriptions?published=no')->assertOk()->assertSee('Minutes of 1952')->assertDontSee('Council records');
        $item = Concept::where('iri', Vocab::BASE.'level-of-description/item')->value('id');
        $this->actingAs($this->editor)->get("/reports/descriptions?level=$item")->assertOk()->assertSee('Minutes of 1952')->assertDontSee('Council records');
        $this->actingAs($this->editor)->get('/reports/descriptions?from=2000-01-01&to=2000-12-31')->assertOk()->assertSee('None.');
        $this->actingAs($this->editor)->get('/reports/descriptions?from=yesterday')->assertSessionHasErrors('from');

        $csv = $this->actingAs($this->editor)->get('/reports/descriptions?format=csv')->assertOk()->streamedContent();
        $this->assertStringStartsWith('Title,"Reference code","Level of description",Repository,Published,Created,Updated,IRI', $csv);
        $this->assertStringContainsString('"Council records","RA C1",Fonds,"Riverside Archives",Yes', $csv);

        $this->actingAs($this->editor)->get('/reports/authorities')->assertOk()->assertSee('Botha, Maria');
        $this->actingAs($this->editor)->get('/reports/repositories')->assertOk()->assertSeeInOrder(['Riverside Archives', 'RA', '2'], false);
        $this->actingAs($this->editor)->get('/reports/updates')->assertOk()->assertSee('Minutes of 1952')->assertSee('Botha, Maria');
        $this->actingAs($this->editor)->get('/reports/taxonomies')->assertOk()->assertSee('OpenRiC Workspace Accession Event Types');

        // User activity reads the audit log, so it needs the audit log's permission; the menu shows the lists the account may read.
        $this->actingAs($this->editor)->get('/reports/activity')->assertForbidden();
        $this->actingAs($this->editor)->get('/reports')->assertSee('>Recent updates<', false)->assertDontSee('>User activity<', false);
        $admin = app(AccountService::class)->create($this->institution, 'Admin', 'admin@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($admin, 'administrator');
        $this->actingAs($admin)->get('/reports/activity')->assertOk()->assertSee('Editor');
        $this->assertStringContainsString('User,Action,Count,Latest', $this->actingAs($admin)->get('/reports/activity?format=csv')->streamedContent());
    }
}
