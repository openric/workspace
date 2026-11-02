<?php

// OpenRiC Workspace - security clearance checked by the one read check: levels, compartments, clearances,
// classification with inheritance, access above one's clearance, and declassification (roadmap phase 18c, ADR 0016).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\InstantiationService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Exchange\ExportView;
use App\Iiif\Auth as IiifAuth;
use App\Models\ClearanceRequest;
use App\Models\Instantiation;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\SecurityCompartment;
use App\Models\SecurityLevel;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Rights\Clearances;
use App\Search\SearchService;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Imagick;
use ImagickPixel;
use Tests\TestCase;

class SecurityClearanceTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private string $store;

    private Institution $institution;

    /** @var array<string, User> */
    private array $people = [];

    private RecordResource $fonds;

    private RecordResource $letter;

    private Instantiation $scan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        $this->travelTo('2026-11-05 09:00');
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        foreach (['officer' => 'administrator', 'deputy' => 'administrator', 'editor' => 'editor', 'colleague' => 'editor'] as $who => $template) {
            $this->people[$who] = app(AccountService::class)->create($this->institution, ucfirst($who), "$who@example.org", 'correct horse battery staple');
            app(AccountService::class)->grantTemplateRole($this->people[$who], $template);
        }
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $this->fonds = app(RecordService::class)->create($this->institution, self::LEVEL.'fonds', 'Defence committee records', 'en', null, $holder, 'DC');
        $this->letter = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Letter about the bunker', 'en', $this->fonds, null, '1');
        $image = new Imagick;
        $image->newImage(60, 40, new ImagickPixel('#c0c0c0'));
        $image->setImageFormat('png');
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, $image->getImageBlob());
        $this->scan = app(InstantiationService::class)->upload($this->letter, $path, 'bunker.png', 'en', 'Scan of the letter', null, 'online', $this->people['editor']);
        foreach ([$holder, $this->fonds, $this->letter, $this->scan] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->people['officer']);
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->store)) {
            exec('rm -rf '.escapeshellarg($this->store));
        }
        parent::tearDown();
    }

    private function level(string $code): SecurityLevel
    {
        return app(Clearances::class)->levels($this->institution->id)->firstWhere('code', $code);
    }

    private function publicSees(RecordResource $record): bool
    {
        return (new ExportView(null, false, null, $this->institution))->readable($record->fresh());
    }

    public function test_a_classified_record_and_what_is_inside_it_reach_only_who_is_cleared_to_it(): void
    {
        $officer = $this->people['officer'];
        $this->assertSame(['PUBLIC', 'INTERNAL', 'RESTRICTED', 'CONFIDENTIAL', 'SECRET', 'TOP_SECRET'], app(Clearances::class)->levels($this->institution->id)->pluck('code')->all());
        // Only someone cleared to a level classifies at it, and nobody clears themselves.
        $this->actingAs($officer)->post(route('records.classification', $this->fonds), ['level_id' => $this->level('CONFIDENTIAL')->id, 'justification' => 'Defence plans'])
            ->assertSessionHasErrors(['clearance' => 'You are not cleared to Confidential.']);
        $this->actingAs($officer)->post(route('clearance.step', 'grant'), ['user_id' => $officer->id, 'level_id' => $this->level('SECRET')->id])
            ->assertSessionHasErrors(['clearance' => 'Someone else grants your own clearance.']);
        $this->actingAs($this->people['deputy'])->post(route('clearance.step', 'grant'), ['user_id' => $officer->id, 'level_id' => $this->level('SECRET')->id])->assertSessionHasNoErrors();
        $this->actingAs($officer)->post(route('records.classification', $this->fonds), ['level_id' => $this->level('CONFIDENTIAL')->id])
            ->assertSessionHasErrors(['clearance' => 'Give the justification this level requires.']);
        $this->actingAs($officer)->post(route('records.classification', $this->fonds), ['level_id' => $this->level('CONFIDENTIAL')->id, 'justification' => 'Defence plans',
            'handling_instructions' => 'Read in the strongroom only'])->assertSessionHasNoErrors();

        // The public: not the fonds, not the letter inside it, not its file, not in search.
        $this->assertFalse($this->publicSees($this->fonds));
        $this->assertFalse($this->publicSees($this->letter));
        $this->assertFalse(app(InstantiationService::class)->deliverable(null, $this->scan->files()->first()));
        $this->assertSame(0, app(SearchService::class)->search($this->institution, 'bunker', true)['total']);
        $this->getJson('/api/ric/v1/records/'.$this->letter->id)->assertNotFound();

        // An editor without clearance does not read it, even on the staff side.
        $editor = $this->people['editor'];
        $this->assertFalse(EntityKinds::readable($editor, $this->letter->fresh()));
        $this->actingAs($editor)->get(route('records.show', $this->letter))->assertNotFound();
        $copy = $this->scan->files()->with('use')->get()->first(fn ($f) => $f->use->notation !== 'master');
        $this->assertSame(401, app(IiifAuth::class)->probe($copy, $editor)['status']);
        // Cleared to Confidential, they read it and see its file, but not its master: Confidential forbids downloads.
        $this->actingAs($this->people['deputy'])->post(route('clearance.step', 'grant'), ['user_id' => $editor->id, 'level_id' => $this->level('CONFIDENTIAL')->id, 'expires_on' => '2026-12-31'])->assertSessionHasNoErrors();
        app(Clearances::class)::flush();
        $this->assertTrue(EntityKinds::readable($editor, $this->letter->fresh()));
        $this->actingAs($editor)->get(route('records.show', $this->letter))->assertOk()->assertSee('Read in the strongroom only');
        $files = $this->scan->files()->with('use')->get()->keyBy(fn ($f) => $f->use->notation);
        $this->assertFalse(app(InstantiationService::class)->deliverable($editor, $files['master']));
        // The IIIF authorization probe answers as the read check does (phase 14's probe, with clearance).
        $this->assertSame(200, app(IiifAuth::class)->probe($copy, $editor)['status']);
        $this->assertSame(401, app(IiifAuth::class)->probe($files['master'], $editor)['status']);
        // The letter keeps its own lower classification, and is not passed the fonds's.
        $this->actingAs($officer)->post(route('records.classification', $this->letter), ['level_id' => $this->level('INTERNAL')->id])->assertSessionHasNoErrors();
        $this->assertSame('INTERNAL', app(Clearances::class)->effective($this->letter->fresh())->level->code);
        // Their clearance ends with its date.
        $this->travelTo('2027-01-02 09:00');
        app(Clearances::class)::flush();
        $this->assertFalse(EntityKinds::readable($editor, $this->fonds->fresh()));
    }

    public function test_compartments_access_above_ones_clearance_and_declassification(): void
    {
        [$officer, $deputy, $colleague] = [$this->people['officer'], $this->people['deputy'], $this->people['colleague']];
        $this->actingAs($deputy)->post(route('clearance.step', 'grant'), ['user_id' => $officer->id, 'level_id' => $this->level('TOP_SECRET')->id]);
        $this->actingAs($officer)->post(route('clearance.step', 'grant'), ['user_id' => $deputy->id, 'level_id' => $this->level('TOP_SECRET')->id]);
        $this->actingAs($officer)->post(route('clearance.step', 'compartment'), ['code' => 'nuclear', 'name' => 'Nuclear', 'min_level_id' => $this->level('SECRET')->id, 'is_active' => 1])->assertSessionHasNoErrors();
        $compartment = SecurityCompartment::sole();
        $this->actingAs($officer)->post(route('records.classification', $this->letter), ['level_id' => $this->level('SECRET')->id, 'justification' => 'Weapons', 'compartments' => [$compartment->id],
            'declassify_on' => '2026-11-10', 'declassify_to_level_id' => ''])->assertSessionHasNoErrors();
        // Secret is not enough without the compartment; and the compartment needs at least Secret.
        $this->actingAs($deputy)->post(route('clearance.step', 'grant'), ['user_id' => $colleague->id, 'level_id' => $this->level('CONFIDENTIAL')->id, 'compartments' => [$compartment->id]])
            ->assertSessionHasErrors(['clearance' => 'Nuclear needs at least Secret.']);
        $this->actingAs($deputy)->post(route('clearance.step', 'grant'), ['user_id' => $colleague->id, 'level_id' => $this->level('SECRET')->id])->assertSessionHasNoErrors();
        app(Clearances::class)::flush();
        $this->assertFalse(EntityKinds::readable($colleague, $this->letter->fresh()));
        $this->assertTrue(EntityKinds::readable($colleague, $this->fonds->fresh()), 'the fonds is not classified');

        // An editor asks for access above their clearance, by the record's IRI; someone cleared to it grants it for two hours.
        $editor = $this->people['editor'];
        $this->actingAs($editor)->post(route('clearance.request.submit'), ['iri' => $this->letter->iri, 'request_type' => 'view', 'justification' => 'Writing the official history'])->assertSessionHasNoErrors();
        $request = ClearanceRequest::sole();
        $this->actingAs($editor)->post(route('clearance.request.submit'), ['iri' => $this->letter->iri, 'request_type' => 'view', 'justification' => 'Again'])
            ->assertSessionHasErrors(['clearance' => 'You have asked already; it awaits a decision.']);
        $this->actingAs($officer)->get(route('clearance.index'))->assertOk()->assertSee('Writing the official history');
        $this->actingAs($officer)->post(route('clearance.step', ['approve', $request->id]), ['duration_hours' => 2])->assertSessionHasNoErrors();
        app(Clearances::class)::flush();
        $this->assertTrue(EntityKinds::readable($editor, $this->letter->fresh()));
        $this->travelTo('2026-11-05 11:30');
        app(Clearances::class)::flush();
        $this->assertFalse(EntityKinds::readable($editor, $this->letter->fresh()));

        // On its date it is declassified, and the public reads it again.
        $this->assertFalse($this->publicSees($this->letter));
        $this->travelTo('2026-11-10 06:00');
        $this->artisan('workspace:declassify')->expectsOutput('1 records declassified.')->assertSuccessful();
        app(Clearances::class)::flush();
        $this->assertTrue($this->publicSees($this->letter));
        $this->assertSame(1, app(SearchService::class)->search($this->institution, 'bunker', true)['total']);
    }
}
