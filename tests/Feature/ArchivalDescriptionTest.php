<?php

// OpenRiC Workspace - archival description: publication, browse, hierarchy, finding aids, dates, control and history.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\AgentService;
use App\Description\DateService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Institution;
use App\Models\Name;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\User;
use App\Relations\RelationService;
use App\Support\Vocab;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ArchivalDescriptionTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private Institution $institution;

    private Repository $holder;

    private RecordResource $fonds;

    private RecordResource $series;

    private RecordResource $file;

    private User $editor;

    private User $contributor;

    private User $translator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        $accounts = app(AccountService::class);
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        foreach (['editor', 'contributor', 'translator'] as $role) {
            $this->{$role} = $accounts->create($this->institution, ucfirst($role), "$role@example.org", 'correct horse battery staple');
            $accounts->grantTemplateRole($this->{$role}, $role);
        }
        $this->holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'ZA-RA');
        $records = app(RecordService::class);
        $this->fonds = $records->create($this->institution, self::LEVEL.'fonds', 'Riverside Parish Council records', 'en', null, $this->holder, 'RPC');
        $this->series = $records->create($this->institution, self::LEVEL.'series', 'Minutes', 'en', $this->fonds, null, '1');
        $this->file = $records->create($this->institution, self::LEVEL.'file', 'Minutes, 1902', 'en', $this->series, null, 'RPC/1/3');
        $records->describe($this->fonds, ['scope-and-content' => 'Minutes and correspondence of the council.'], 'en');
    }

    public function test_a_unit_is_reviewed_published_and_withdrawn_with_the_units_inside_it(): void
    {
        $published = fn (RecordResource $unit) => Name::where(['subject_id' => $unit->id])->whereHas('state', fn ($q) => $q->where('iri', Vocab::valueState('published')))->count();
        $this->actingAs($this->contributor)->post("/records/{$this->fonds->id}/publication", ['action' => 'publish'])->assertForbidden();
        $this->actingAs($this->contributor)->post("/records/{$this->fonds->id}/publication", ['action' => 'review'])->assertRedirect(route('records.show', $this->fonds));
        $this->assertSame('reviewed', $this->fonds->names()->first()->state->notation);

        $this->actingAs($this->editor)->get("/records/{$this->fonds->id}/publication")->assertOk()->assertSee('And the 2 units of description inside it');
        $this->actingAs($this->editor)->post("/records/{$this->fonds->id}/publication", ['action' => 'publish', 'inside' => '1'])
            ->assertSessionHas('status', '4 values published.');
        $this->assertSame([1, 1, 1], [$published($this->fonds), $published($this->series), $published($this->file)]);
        // A reader without view-draft (signed in, reading as the public role does) sees what is published.
        $reader = app(AccountService::class)->create($this->institution, 'A Reader', 'reader@example.org', 'correct horse battery staple');
        $this->actingAs($reader)->get("/records/{$this->fonds->id}")->assertSee('Minutes and correspondence of the council.');

        // A changed value goes back to draft and is hidden until published again.
        app(RecordService::class)->describe($this->fonds, ['scope-and-content' => 'Minutes, correspondence and accounts.'], 'en');
        $this->actingAs($reader)->get("/records/{$this->fonds->id}")->assertDontSee('Minutes, correspondence and accounts.')->assertDontSee('Minutes and correspondence of the council.');
        $this->actingAs($this->editor)->post("/records/{$this->fonds->id}/publication", ['action' => 'withdraw'])->assertSessionHas('status', '1 value withdrawn.');
        $this->assertSame('withdrawn', $this->fonds->names()->first()->state->notation);
        $this->assertTrue(DB::table('audit_log')->where('subject_table', 'names')->whereNotNull('after->state_id')->exists());
    }

    public function test_repositories_and_authority_records_are_published_too(): void
    {
        $agent = app(AgentService::class)->create($this->institution, 'person', 'Engelbrecht, Johanna', 'en');
        $this->actingAs($this->contributor)->post("/agents/{$agent->id}/publication", ['action' => 'publish'])->assertForbidden();
        $this->actingAs($this->editor)->post("/agents/{$agent->id}/publication", ['action' => 'publish'])->assertRedirect(route('agents.show', $agent));
        $this->actingAs($this->editor)->post("/repositories/{$this->holder->id}/publication", ['action' => 'publish'])->assertRedirect(route('repositories.show', $this->holder));
        $this->assertSame('published', $agent->names()->first()->state->notation);
        $this->assertSame('published', $this->holder->names()->first()->state->notation);
    }

    public function test_the_database_keeps_an_authorised_name_on_every_described_entity(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $title = $this->file->names()->first();
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('names')->where('id', $title->id)->delete()), QueryException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('names')->where('id', $title->id)
            ->update(['name_type_id' => DB::table('concepts')->where('iri', Vocab::NAME_TYPES.'other')->value('id')])), QueryException::class);
        // A deleted unit no longer needs one.
        DB::transaction(function () use ($title) {
            DB::table('record_resources')->where('id', $this->file->id)->update(['deleted_at' => now()]);
            DB::table('names')->where('id', $title->id)->delete();
        });
        $this->assertSame(0, Name::where('subject_id', $this->file->id)->count());
    }

    public function test_browse_searches_every_level_and_filters(): void
    {
        $this->actingAs($this->contributor)->get('/records')->assertOk()->assertSee('Riverside Parish Council records')->assertDontSee('Minutes, 1902');
        $this->actingAs($this->contributor)->get('/records?q=minutes')->assertSee('Minutes, 1902')->assertSee('in Minutes');
        $this->actingAs($this->contributor)->get('/records?q=RPC/1/3')->assertSee('Minutes, 1902');
        $level = DB::table('concepts')->where('iri', self::LEVEL.'file')->value('id');
        $this->actingAs($this->contributor)->get("/records?top=0&level=$level")->assertSee('Minutes, 1902')->assertDontSee('Riverside Parish Council records</a>', false);
        $this->actingAs($this->contributor)->get('/records?top=0&publication=published')->assertSee('No archival descriptions yet.');
    }

    public function test_a_repository_giving_complete_codes_stops_them_being_built_from_the_units_above(): void
    {
        app(RepositoryService::class)->setCompleteReferenceCodes($this->fonds->repository, true);
        $this->assertSame('ZA-RA 1', $this->series->fresh()->referenceCode(), 'the unit\'s own identifier, after the repository code');
        $this->assertSame('ZA-RA RPC/1/3', $this->file->fresh()->referenceCode());
        app(RepositoryService::class)->setCompleteReferenceCodes($this->fonds->repository, false);
        $this->assertSame('ZA-RA RPC/1', $this->series->fresh()->referenceCode());
    }

    public function test_reference_codes_hierarchy_inventory_and_finding_aid(): void
    {
        $this->assertSame('ZA-RA RPC', $this->fonds->referenceCode());
        $this->assertSame('ZA-RA RPC/1', $this->series->fresh()->referenceCode());
        $this->assertSame('ZA-RA RPC/1/3', $this->file->fresh()->referenceCode(), 'an identifier already holding the code above is taken as written');

        $this->actingAs($this->contributor)->get("/records/{$this->file->id}/tree")->assertOk()
            ->assertSeeInOrder(['Riverside Parish Council records', 'Minutes', 'Minutes, 1902'])->assertSee('aria-current="page"', false);
        $this->actingAs($this->contributor)->get("/records/{$this->fonds->id}/inventory")->assertOk()->assertSeeInOrder(['ZA-RA RPC/1', 'Minutes', 'ZA-RA RPC/1/3', 'Minutes, 1902']);
        $this->actingAs($this->contributor)->get("/records/{$this->fonds->id}/finding-aid")->assertOk()->assertSee('[title not published]')
            ->assertDontSee('Minutes and correspondence of the council.');
        $this->actingAs($this->contributor)->get("/records/{$this->fonds->id}/finding-aid?drafts=1")->assertSee('Minutes and correspondence of the council.')->assertSee('Minutes, 1902');
        $this->actingAs($this->contributor)->get("/records/{$this->fonds->id}/print?drafts=1")->assertOk()->assertSee('Riverside Parish Council records')->assertDontSee('Minutes, 1902');
    }

    public function test_dates_are_calculated_from_the_units_inside(): void
    {
        $dates = app(DateService::class);
        $dates->add($this->file, ['property' => 'creation', 'normalized' => '1902']);
        $other = app(RecordService::class)->create($this->institution, self::LEVEL.'file', 'Minutes, 1950', 'en', $this->series, null, '4');
        $dates->add($other, ['property' => 'creation', 'normalized' => '1950-06']);
        $this->actingAs($this->contributor)->post("/records/{$this->fonds->id}/calculate-dates")->assertSessionHas('status', 'Creation date set to 1902/1950-06-30, the span of the units inside it.');
        $this->assertSame('1902/1950-06-30', $this->fonds->dates()->sole()->normalized_value);
        $dates->add($this->file, ['property' => 'creation', 'normalized' => '1899']);
        $this->actingAs($this->contributor)->post("/records/{$this->fonds->id}/calculate-dates");
        $this->assertSame('1899/1950-06-30', $this->fonds->dates()->sole()->normalized_value, 'updated in place');
    }

    public function test_description_control_creators_provenance_citation_and_history(): void
    {
        $this->actingAs($this->contributor)->post("/records/{$this->fonds->id}", ['language' => 'en', 'level' => self::LEVEL.'fonds', 'identifier' => 'RPC',
            'values' => ['languages-of-material' => 'English and Dutch.'], 'description_identifier' => 'D-1', 'description_status' => Vocab::DESCRIPTION_STATUSES.'final'])->assertRedirect();
        $this->assertSame(['D-1', 'final'], [$this->fonds->fresh()->description_identifier, $this->fonds->fresh()->descriptionStatus->notation]);
        $this->actingAs($this->translator)->post("/records/{$this->fonds->id}", ['language' => 'af', 'level' => self::LEVEL.'fonds', 'identifier' => 'RPC',
            'values' => [], 'description_identifier' => 'D-2'])->assertForbidden();

        $agents = app(AgentService::class);
        $council = $agents->create($this->institution, 'corporate-body', 'Riverside Parish Council', 'en');
        $agents->describe($council, ['agent-history' => 'Established in 1902 to govern the parish.'], 'en');
        $relations = app(RelationService::class);
        $relations->create($this->fonds, 'creation', $council, ['from' => '1902', 'to' => '1950'], $this->editor);
        $town = app(RepositoryService::class)->create($this->institution, 'Riverside Town Hall', 'en');
        $relations->create($town, 'holding', $this->fonds, ['from' => '1950', 'to' => '1975', 'state' => 'past'], $this->editor);

        $this->actingAs($this->contributor)->get("/records/{$this->fonds->id}")->assertOk()
            ->assertSee('3.4.3 Language/scripts of material')->assertSee('English and Dutch.')
            ->assertSee('Creator(s) (3.2.1)')->assertSee('Established in 1902 to govern the parish.')
            ->assertSeeInOrder(['Custody and provenance', '1902 to 1950: Was created by Riverside Parish Council', '1950 to 1975: Is or was held by Riverside Town Hall', 'Now held by Riverside Archives'])
            ->assertSee('Riverside Parish Council records, ZA-RA RPC, Riverside Archives. '.$this->fonds->iri);

        $this->actingAs($this->contributor)->get("/records/{$this->fonds->id}/history")->assertOk()
            ->assertSee('English and Dutch.')->assertSee('relation')->assertSee('Contributor');
        $this->actingAs($this->translator)->get("/records/{$this->fonds->id}/history")->assertForbidden();
    }
}
