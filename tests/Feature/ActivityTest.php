<?php

// OpenRiC Workspace - activities (rico:Activity): the V0.1 demonstration steps 5 to 7 (WS-003, WS-006).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// tests/Fixtures/activity-type.ttl is the published OpenRiC activity-type
// vocabulary (https://openric.org/vocab/activity-type.ttl, CC BY 4.0).

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\ActivityService;
use App\Description\AgentService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Activity;
use App\Models\Agent;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use App\Relations\RelationService;
use App\Vocabulary\ConceptService;
use App\Vocabulary\SkosImporter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ActivityTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private Institution $institution;

    private User $editor;

    private RecordResource $minutes;

    private RecordResource $letter;

    private RecordResource $secondLetter;

    private Agent $clerk;

    private RelationService $relations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        foreach (['role-type', 'activity-type'] as $fixture) {
            app(SkosImporter::class)->import(file_get_contents(base_path("tests/Fixtures/$fixture.ttl")));
        }
        $this->relations = app(RelationService::class);
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->editor = app(AccountService::class)->create($this->institution, 'Editor', 'editor@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->editor, 'editor');
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $records = app(RecordService::class);
        $fonds = $records->create($this->institution, self::LEVEL.'fonds', 'Riverside Parish Council records', 'en', null, $holder, 'RPC');
        $this->minutes = $records->create($this->institution, self::LEVEL.'series', 'Minute books', 'en', $fonds, null, '1');
        $this->letter = $records->create($this->institution, self::LEVEL.'item', 'Letter of 3 May 1902', 'en', $fonds, null, '2');
        $this->secondLetter = $records->create($this->institution, self::LEVEL.'item', 'Letter of 9 June 1902', 'en', $fonds, null, '3');
        $this->clerk = app(AgentService::class)->create($this->institution, 'person', 'Botha, Jan (parish clerk)', 'en');
    }

    private function type(string $iri): string
    {
        return Concept::where('iri', $iri)->value('id');
    }

    public function test_step_5_the_clerk_performs_the_creation_activity_that_resulted_in_the_minute_books(): void
    {
        $this->actingAs($this->editor)->get('/activities/new')->assertOk()->assertSee('Production')->assertSee('rico:hasActivityType');
        $this->actingAs($this->editor)->post('/activities', ['name' => 'Keeping of the council minutes', 'language' => 'en',
            'type' => $this->type(Activity::TYPES.'production')])->assertRedirect();
        $creation = Activity::sole();
        $this->assertSame('production', $creation->activityType->notation);
        $this->assertStringStartsWith('https://archives.example.org/activity/', $creation->iri);

        $this->relations->create($creation, 'performance', $this->clerk, ['from' => '1902', 'to' => '1950'], $this->editor);
        $this->relations->create($creation, 'result', $this->minutes, [], $this->editor);
        $this->relations->create($this->letter, 'documentation', $creation, [], $this->editor);

        $this->actingAs($this->editor)->get("/activities/{$creation->id}")->assertOk()
            ->assertSeeInOrder(['Performed by', 'Botha, Jan (parish clerk)', 'Resulted in', 'Minute books', 'Documented by', 'Letter of 3 May 1902'])
            ->assertSee('rico:PerformanceRelation')->assertSee('rico:ActivityDocumentationRelation');
        // Followed both ways: from the minute books and from the clerk.
        $this->actingAs($this->editor)->get("/records/{$this->minutes->id}")->assertSee('Results or resulted from Keeping of the council minutes');
        $this->actingAs($this->editor)->get("/agents/{$this->clerk->id}")->assertSee('Performs or performed Keeping of the council minutes');
        $this->assertSame('accepted', $this->relations->of($creation)->first()->standing(), 'with who made it and when');
    }

    public function test_steps_6_and_7_the_marriage_with_roles_evidence_and_a_corrected_witness(): void
    {
        $concepts = app(ConceptService::class);
        $marriage = $concepts->create($concepts->schemeOfKind($this->institution, 'activity-type'), ['label' => 'Marriage', 'language' => 'en']);
        $activities = app(ActivityService::class);
        $this->assertTrue($activities->types($this->institution->id)->contains('id', $marriage->id), 'the institution\'s own activity types are offered');
        $wedding = $activities->create($this->institution, 'Marriage of Johanna Engelbrecht and Pieter de Wet', 'en', $marriage->id, $this->editor);

        $agents = app(AgentService::class);
        $bride = $agents->create($this->institution, 'person', 'Engelbrecht, Johanna', 'en');
        $groom = $agents->create($this->institution, 'person', 'de Wet, Pieter', 'en');
        $wrong = $agents->create($this->institution, 'person', 'Smit, Koos', 'en');
        foreach ([[$bride, 'spouse'], [$groom, 'spouse'], [$wrong, 'witness']] as [$person, $role]) {
            $relation = $this->relations->create($wedding, 'participation', $person, ['role' => $role, 'from' => '1902-05-01'], $this->editor);
            $this->relations->addEvidence($relation, $this->letter, $this->editor);
        }
        $this->actingAs($this->editor)->get("/activities/{$wedding->id}")->assertOk()
            ->assertSee('Has or had as a participant Engelbrecht, Johanna, as Spouse')->assertSee('Smit, Koos, as Witness')->assertSee('Letter of 3 May 1902');

        // Step 7: the second letter shows the witness was the parish clerk.
        $old = $this->relations->of($wedding)->first(fn ($r) => $r->other->is($wrong));
        $this->relations->assert($old, $this->editor, false, 'The letter of 9 June 1902 names the parish clerk as witness.', 'en', $old->assertions->sole());
        $corrected = $this->relations->create($wedding, 'participation', $this->clerk, ['role' => 'witness'], $this->editor,
            ['basis' => 'The letter of 9 June 1902.', 'basis_language' => 'en']);
        $this->relations->addEvidence($corrected, $this->secondLetter, $this->editor);

        $old = $old->fresh(['assertions.status']);
        $this->assertCount(2, $old->assertions, 'the first assertion is kept, not deleted');
        $this->assertSame([$old->assertions[0]->id], $old->assertions->pluck('supersedes_id')->filter()->values()->all(), 'and marked superseded');
        $this->assertSame('rejected', $old->standing());
        $this->actingAs($this->editor)->get("/activities/{$wedding->id}")
            ->assertSee('Botha, Jan (parish clerk), as Witness')->assertSee('The letter of 9 June 1902 names the parish clerk as witness.')->assertSee('Letter of 9 June 1902');
        $this->actingAs($this->editor)->get("/agents/{$this->clerk->id}")->assertSee('Takes or took part in Marriage of Johanna Engelbrecht and Pieter de Wet');
    }

    public function test_activity_types_names_and_the_database_rules(): void
    {
        $activities = app(ActivityService::class);
        $subject = app(ConceptService::class)->create(app(ConceptService::class)->schemeOfKind($this->institution, 'subject'), ['label' => 'Local government', 'language' => 'en']);
        $this->assertThrows(fn () => $activities->create($this->institution, 'Something', 'en', $subject->id), \InvalidArgumentException::class);
        $activity = $activities->create($this->institution, 'Transfer of the minute books', 'en', $this->type(Activity::TYPES.'transfer'));
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('activities')->where('id', $activity->id)->update(['activity_type_id' => $subject->id])), QueryException::class);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('names')->where('subject_id', $activity->id)->delete()), QueryException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('activities')->where('id', $activity->id)->delete()), QueryException::class);

        $this->actingAs($this->editor)->post("/activities/{$activity->id}", ['language' => 'af', 'values' => ['activity-name' => 'Oordrag van die notuleboeke',
            'activity-description' => 'Die raad het die boeke in 1975 oorgedra.'], 'type' => $this->type(Activity::TYPES.'custody')])->assertRedirect();
        $activity->refresh();
        $this->assertSame(['custody', 'Oordrag van die notuleboeke', 'Transfer of the minute books'], [$activity->activityType->notation, $activity->described('activity-name', ['af']), $activity->described('activity-name', ['en'])]);
        $this->actingAs($this->editor)->get("/activities/{$activity->id}/edit?language=af")->assertOk()->assertSee('5.2.2 Description')->assertSee('rico:generalDescription');
        $this->actingAs($this->editor)->get('/activities?q=minute')->assertOk()->assertSee('Transfer of the minute books');
        $this->actingAs($this->editor)->get("/activities/{$activity->id}/history")->assertOk();
        $this->actingAs($this->editor)->post("/activities/{$activity->id}/delete")->assertForbidden();
        $administrator = app(AccountService::class)->create($this->institution, 'Administrator', 'admin@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($administrator, 'administrator');
        $this->actingAs($administrator)->post("/activities/{$activity->id}/delete")->assertRedirect();
        $this->assertNotNull($activity->fresh()->deleted_at);
        $this->actingAs($this->editor)->get("/activities/{$activity->id}")->assertNotFound();
    }

    public function test_an_activity_is_made_from_a_unit_of_description_and_readers_need_the_authority_permission(): void
    {
        $this->actingAs($this->editor)->get("/records/{$this->letter->id}/relations/new?relate=documentation|source")->assertOk()->assertSee('a new activity');
        $this->actingAs($this->editor)->post("/records/{$this->letter->id}/relations", ['relate' => 'documentation|source', 'other' => 'new:activity',
            'new_name' => 'Election of 1902', 'new_language' => 'en'])->assertRedirect();
        $this->assertSame('Election of 1902', Activity::sole()->described('activity-name', ['en']));

        $other = Institution::create(['name' => 'Elsewhere', 'base_iri' => 'https://elsewhere.example.org']);
        app(RoleService::class)->seedDefaults($other);
        $stranger = app(AccountService::class)->create($other, 'Stranger', 'stranger@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($stranger, 'editor');
        $this->actingAs($stranger)->get('/activities/'.Activity::sole()->id)->assertNotFound();
        $translator = app(AccountService::class)->create($this->institution, 'Translator', 'translator@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($translator, 'translator');
        $this->actingAs($translator)->get('/activities/new')->assertForbidden();
    }
}
