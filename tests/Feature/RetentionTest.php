<?php

// OpenRiC Workspace - retention and disposal (roadmap phase 13b, ADR 0015): a schedule is drafted, given classes and
// approved; a record's class gives its due date from its trigger; records fall due and go to review; a review starts a
// disposal; a disposal passes four pairs of hands, never the same twice where Heratio forbids it; destruction erases
// the record's files (not those another record uses), keeps its description as evidence with a step of its custody
// history, makes its IRI answer 410, and issues a certificate that verifies; transfer and permanent retention.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\DateService;
use App\Description\InstantiationService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Files\FileStore;
use App\Models\DestructionCertificate;
use App\Models\DisposalAction;
use App\Models\DisposalClass;
use App\Models\Instantiation;
use App\Models\Institution;
use App\Models\ProvenanceEntry;
use App\Models\RecordDisposalClass;
use App\Models\RecordResource;
use App\Models\RetentionReview;
use App\Models\RetentionSchedule;
use App\Models\User;
use App\Retention\DisposalService;
use App\Retention\RetentionService;
use App\Vocabulary\SkosImporter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Imagick;
use ImagickPixel;
use InvalidArgumentException;
use Tests\TestCase;

class RetentionTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private string $store;

    private Institution $institution;

    /** @var array<string, User> */
    private array $people = [];

    private RecordResource $file;

    private Instantiation $scan;

    private DisposalClass $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        foreach (['initiator' => 'editor', 'approver' => 'editor', 'clearer' => 'editor', 'administrator' => 'administrator'] as $who => $template) {
            $this->people[$who] = app(AccountService::class)->create($this->institution, ucfirst($who), "$who@example.org", 'correct horse battery staple');
            app(AccountService::class)->grantTemplateRole($this->people[$who], $template);
        }
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $this->file = app(RecordService::class)->create($this->institution, self::LEVEL.'file', 'Trading licence applications', 'en', null, $holder, 'TL-1');
        app(DateService::class)->add($this->file, ['property' => 'creation', 'normalized' => '1990/1995']);
        $this->scan = app(InstantiationService::class)->upload($this->file, $this->image('#c0c0c0'), 'applications.png', 'en', 'Scan', null, 'online', $this->people['initiator']);
        foreach ([$holder, $this->file] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->people['initiator']);
        }

        $retention = app(RetentionService::class);
        $schedule = $retention->saveSchedule($this->institution, ['reference' => 'RS-1', 'title' => 'Licensing records', 'authority' => 'NARSSA Standing Disposal Authority PAR 1'],
            null, $this->people['administrator']);
        $this->class = $retention->saveClass($schedule, ['reference' => '4.2', 'title' => 'Trading licence applications', 'retention_years' => 10, 'trigger' => 'creation-end',
            'action' => 'destroy', 'review_required' => true, 'citation' => 'PAR 1, item 4.2'], null, $this->people['administrator']);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->store)) {
            exec('rm -rf '.escapeshellarg($this->store));
        }
        parent::tearDown();
    }

    private function image(string $colour): string
    {
        $image = new Imagick;
        $image->newImage(300, 200, new ImagickPixel($colour));
        $image->setImageFormat('png');
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, $image->getImageBlob());

        return $path;
    }

    private function as(string $who, string $step, array $data = [])
    {
        return $this->actingAs($this->people[$who])->post(route('disposals.step', [DisposalAction::sole(), $step]), $data);
    }

    public function test_a_class_of_an_approved_schedule_gives_the_due_date_and_records_fall_due_to_review(): void
    {
        $retention = app(RetentionService::class);
        try {
            $retention->assign($this->file, $this->class, null, $this->people['initiator']);
            $this->fail('a class of a draft schedule is given');
        } catch (InvalidArgumentException) {
        }
        $this->actingAs($this->people['administrator'])->post(route('retention.schedules.approve', $this->class->schedule), ['approved_by' => 'Dr Johan Pieterse, Records Manager'])->assertSessionHasNoErrors();
        $this->actingAs($this->people['initiator'])->post(route('records.retention.assign', $this->file), ['disposal_class_id' => $this->class->id])->assertSessionHasNoErrors();
        $assignment = RecordDisposalClass::sole();
        $this->assertSame(['1995-12-31', '2005-12-31'], [$assignment->retention_starts_on->format('Y-m-d'), $assignment->disposal_due_on->format('Y-m-d')], 'ten years from the end of creation');
        $this->actingAs($this->people['initiator'])->post(route('records.retention.assign', $this->file), ['override' => '1', 'override_due_on' => '2030-01-01'])->assertSessionHasErrors('retention');

        $this->assertSame([$this->file->id], $retention->dueForDisposal($this->institution->id)->pluck('record_resource_id')->all());
        $this->actingAs($this->people['initiator'])->get(route('retention.index'))->assertOk()->assertSee('Trading licence applications')->assertSee('Review');
        $this->actingAs($this->people['initiator'])->post(route('records.retention.review', $this->file), ['due_on' => '2026-11-01'])->assertSessionHasNoErrors();
        $this->assertSame([], $retention->dueForDisposal($this->institution->id)->all(), 'in review, no longer listed as due');
        $review = RetentionReview::sole();
        $this->actingAs($this->people['initiator'])->post(route('retention.reviews.decide', $review), ['decision' => 'retain'])->assertSessionHasErrors('retention');
        $this->actingAs($this->people['initiator'])->post(route('retention.reviews.decide', $review), ['decision' => 'dispose', 'decision_notes' => 'No further value.'])->assertSessionHasNoErrors();
        $this->assertSame(['destroy', 'pending'], [DisposalAction::sole()->action->notation, DisposalAction::sole()->status->notation]);
    }

    public function test_destruction_passes_four_pairs_of_hands_erases_the_files_and_keeps_the_evidence(): void
    {
        // Another record holds the same file: it is kept for that record.
        $twin = app(RecordService::class)->create($this->institution, self::LEVEL.'file', 'Copy kept elsewhere', 'en', null, $this->file->repository, 'TL-2');
        $copyImage = $this->image('#c0c0c0');
        app(InstantiationService::class)->upload($twin, $copyImage, 'copy.png', 'en', 'Same scan', null, 'online', $this->people['initiator']);
        $store = app(FileStore::class);
        $master = $this->scan->file('master');
        $paths = $this->scan->files()->pluck('storage_path', 'id');

        app(RetentionService::class)->approve($this->class->schedule, 'Records Manager', $this->people['administrator']);
        app(RetentionService::class)->assign($this->file, $this->class->fresh(), null, $this->people['initiator']);
        $this->actingAs($this->people['initiator'])->post(route('records.retention.initiate', $this->file), ['action' => 'destroy', 'reason' => 'Retention ended.'])->assertRedirect();
        $this->as('initiator', 'approve')->assertSessionHasErrors('retention');
        $this->as('approver', 'recommend')->assertSessionHasNoErrors();
        $this->as('approver', 'approve')->assertSessionHasNoErrors();
        $this->as('approver', 'clear')->assertSessionHasErrors('retention');
        $this->as('clearer', 'clear')->assertSessionHasNoErrors();
        $this->as('clearer', 'execute')->assertSessionHasErrors('retention');
        $this->as('initiator', 'execute')->assertSessionHasErrors('retention');
        $this->assertTrue(is_file($store->path($master->storage_path)), 'nothing gone yet');
        $this->as('administrator', 'execute', ['witness' => 'Records officer'])->assertSessionHasNoErrors();

        $disposal = DisposalAction::sole();
        $certificate = DestructionCertificate::sole();
        $this->assertSame('executed', $disposal->status->notation);
        $this->assertMatchesRegularExpression('/^DC-\d{4}-00001$/', $certificate->number);
        $this->assertSame(['Trading licence applications', 'RA TL-1', $this->file->iri, 'NARSSA Standing Disposal Authority PAR 1, RS-1 v1', '4.2 Trading licence applications', 'Records officer'],
            [$certificate->record_title, $certificate->reference_code, $certificate->record_iri, $certificate->authority, $certificate->disposal_class, $certificate->witness]);
        $this->assertEqualsCanonicalizing(['master', 'reference', 'thumbnail'], array_column($certificate->files, 'use'));
        $this->assertSame($master->sha256, collect($certificate->files)->firstWhere('use', 'master')['sha256']);
        $this->assertSame(64, strlen($certificate->content_hash));
        foreach ($paths as $path) {
            $shared = collect($certificate->files)->firstWhere('storage_path', $path)['shared'];
            $this->assertSame(! $shared, ! is_file($store->path($path)), 'erased unless another record uses it');
        }
        $this->assertTrue(collect($certificate->files)->firstWhere('use', 'master')['shared'], 'the twin record holds the same master');

        // The description is kept as evidence: deleted, its destruction in its custody history, its IRI 410 with the reason.
        $this->assertNotNull($this->file->fresh()->deleted_at);
        $step = ProvenanceEntry::where('record_resource_id', $this->file->id)->sole();
        $this->assertSame('destruction', $step->transferType->notation);
        $this->assertStringContainsString($certificate->number, $step->transfer_details);
        $this->get('/api/ric/v1/records/'.$this->file->id)->assertStatus(410)->assertJsonFragment(['owl:deprecated' => true]);
        $this->assertStringContainsString($certificate->number, $this->get('/api/ric/v1/records/'.$this->file->id)->json('rdfs:comment'));

        $this->as('administrator', 'verify')->assertSessionHasNoErrors();
        $this->assertSame([true, true, true, true, true, true], array_column(DisposalAction::sole()->verification, 'passed'));
        $this->actingAs($this->people['initiator'])->get(route('retention.certificate', $certificate))->assertOk()->assertSee($certificate->number)->assertSee($master->sha256);
    }

    public function test_a_record_with_units_inside_is_not_destroyed_and_only_an_administrator_destroys(): void
    {
        app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'One application', 'en', $this->file, null, '1');
        $disposals = app(DisposalService::class);
        $disposal = $disposals->initiate($this->file, 'destroy', null, $this->people['initiator']);
        $disposals->approve($disposal, null, $this->people['approver']);
        $disposals->clear($disposal->fresh(), null, $this->people['clearer']);
        try {
            $disposals->execute($disposal->fresh(), $this->people['initiator']);
            $this->fail('an editor destroys');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('administers', $e->getMessage());
        }
        try {
            $disposals->execute($disposal->fresh(), $this->people['administrator']);
            $this->fail('a record with units inside is destroyed');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('inside it', $e->getMessage());
        }
        $this->assertNull($this->file->fresh()->deleted_at);
    }

    public function test_transfer_and_permanent_retention(): void
    {
        $retention = app(RetentionService::class);
        $retention->approve($this->class->schedule, 'Records Manager', $this->people['administrator']);
        $retention->assign($this->file, $this->class->fresh(), null, $this->people['initiator']);
        $disposals = app(DisposalService::class);

        $disposal = $disposals->initiate($this->file, 'retain', 'Archival value', $this->people['initiator']);
        $disposals->approve($disposal, null, $this->people['approver']);
        $disposals->clear($disposal->fresh(), null, $this->people['clearer']);
        $disposals->execute($disposal->fresh(), $this->people['initiator'], ['reason' => 'Evidence of licensing policy']);
        $this->assertSame('retained', $disposal->fresh()->status->notation);
        $this->assertNull(RecordDisposalClass::sole()->disposal_due_on, 'its retention no longer ends');
        $this->assertSame([], $retention->dueForDisposal($this->institution->id, CarbonImmutable::parse('2099-01-01'))->all());

        $transfer = $disposals->initiate($this->file, 'transfer', null, $this->people['initiator']);
        $disposals->approve($transfer, null, $this->people['approver']);
        $disposals->clear($transfer->fresh(), null, $this->people['clearer']);
        $disposals->execute($transfer->fresh(), $this->people['initiator'], ['destination' => 'Western Cape Archives and Records Service']);
        $this->assertSame(['executed', 'Western Cape Archives and Records Service'], [$transfer->fresh()->status->notation, $transfer->fresh()->transfer_destination]);
        $this->assertSame('transfer', ProvenanceEntry::where('record_resource_id', $this->file->id)->sole()->transferType->notation);
        $this->assertNull($this->file->fresh()->deleted_at, 'a transferred record stays described');
        $this->assertTrue(RetentionSchedule::sole()->is($this->class->schedule));
    }
}
