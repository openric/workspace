<?php

// OpenRiC Workspace - embargoes on units of description and their files (roadmap phase 13a, ADR 0015): each type
// withholds what it says from the public and from staff who may not change the record, in the catalogue, the API
// and the file route; an exception opens it to an account, a role or an IP range; an embargo is in force by its
// dates and until lifted; it can cover everything inside a record; the daily run warns before one ends and gives
// notice once it has; the list, statistics and CSV.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\InstantiationService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Mail\EmbargoMail;
use App\Models\Concept;
use App\Models\Embargo;
use App\Models\Instantiation;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\Role;
use App\Models\User;
use App\Rights\Embargoes;
use App\Vocabulary\SkosImporter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Imagick;
use ImagickPixel;
use Tests\TestCase;

class EmbargoTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private const API = '/api/ric/v1';

    private string $store;

    private User $editor;

    private User $translator;

    private RecordResource $fonds;

    private RecordResource $letter;

    private Instantiation $scan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        Mail::fake();
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($institution);
        $this->editor = app(AccountService::class)->create($institution, 'Editor', 'editor@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->editor, 'editor');
        $this->translator = app(AccountService::class)->create($institution, 'Translator', 'translator@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->translator, 'translator');
        $holder = app(RepositoryService::class)->create($institution, 'Riverside Archives', 'en', 'RA');
        $this->fonds = app(RecordService::class)->create($institution, self::LEVEL.'fonds', 'Riverside Parish Council records', 'en', null, $holder, 'RPC');
        $this->letter = app(RecordService::class)->create($institution, self::LEVEL.'item', 'Letter of 3 May 1902', 'en', $this->fonds, null, '1');
        $image = new Imagick;
        $image->newImage(600, 400, new ImagickPixel('#d8c9a3'));
        $image->setImageFormat('png');
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, $image->getImageBlob());
        $this->scan = app(InstantiationService::class)->upload($this->letter, $path, 'letter.png', 'en', 'Scan of the letter', null, 'online', $this->editor);
        foreach ([$holder, $this->fonds, $this->letter] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->editor);
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->store)) {
            exec('rm -rf '.escapeshellarg($this->store));
        }
        parent::tearDown();
    }

    private function impose(array $data, bool $inside = false): void
    {
        $this->actingAs($this->editor)->post(route('records.embargoes.store', $this->letter), [...$data, 'inside' => $inside ? '1' : '0'])->assertRedirect()->assertSessionHasNoErrors();
        auth()->logout();
    }

    /** What a visitor gets of the letter: the page, the API record, and each file. */
    private function visitor(): array
    {
        return [$this->get(route('discover.record', $this->letter->id))->getStatusCode(), $this->get(self::API.'/records/'.$this->letter->id)->getStatusCode(),
            ...array_map(fn ($use) => $this->get(route('discover.file', $this->scan->file($use)))->getStatusCode(), ['thumbnail', 'reference', 'master'])];
    }

    public function test_each_type_withholds_what_it_says(): void
    {
        $this->assertSame([200, 200, 200, 200, 404], $this->visitor(), 'before: the public has the reference copy and thumbnail, not the master');
        $this->impose(['type' => 'partial', 'reason' => 'copyright', 'public_message' => 'In copyright until 2031.']);
        $this->assertSame([200, 200, 200, 200, 404], $this->visitor());
        $this->get(route('discover.record', $this->letter->id))->assertSee('Under embargo')->assertSee('In copyright until 2031.');

        Embargo::sole()->update(['type_id' => Concept::where('iri', Embargoes::TYPES.'digital-only')->value('id')]);
        $this->assertSame([200, 200, 200, 404, 404], $this->visitor(), 'files withheld: the thumbnail still shows');
        Embargo::sole()->update(['type_id' => Concept::where('iri', Embargoes::TYPES.'metadata-only')->value('id')]);
        $this->assertSame([200, 200, 404, 404, 404], $this->visitor(), 'description only');
        Embargo::sole()->update(['type_id' => Concept::where('iri', Embargoes::TYPES.'full')->value('id')]);
        $this->assertSame([404, 404, 404, 404, 404], $this->visitor(), 'full: the record is not there');
        $this->assertNotContains($this->letter->id, array_column($this->get(self::API.'/records')->json('items') ?? [], 'id'));

        // Staff who may not change it do not see it either; who may change it does.
        $this->actingAs($this->translator)->get(route('records.show', $this->letter))->assertNotFound();
        $this->actingAs($this->translator)->get(route('records.index', ['top' => 0]))->assertDontSee('Letter of 3 May 1902');
        $this->actingAs($this->editor)->get(route('records.show', $this->letter))->assertOk()->assertSee(__('Embargoes'));
    }

    public function test_an_exception_opens_it_to_an_account_a_role_or_an_ip_range(): void
    {
        $this->impose(['type' => 'full', 'reason' => 'privacy']);
        $embargo = Embargo::sole();
        $this->actingAs($this->translator)->get(route('records.show', $this->letter))->assertNotFound();
        $this->actingAs($this->editor)->post(route('records.embargoes.exceptions.add', [$this->letter, $embargo]), ['user_id' => $this->translator->id, 'note' => 'Researcher'])->assertSessionHasNoErrors();
        $this->actingAs($this->translator)->get(route('records.show', $this->letter))->assertOk();

        $this->actingAs($this->editor)->post(route('records.embargoes.exceptions.add', [$this->letter, $embargo]), ['ip_from' => '10.0.0.1', 'ip_to' => '10.0.0.50'])->assertSessionHasNoErrors();
        auth()->logout();
        $this->get(route('discover.record', $this->letter->id))->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.7'])->get(route('discover.record', $this->letter->id))->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.51'])->get(route('discover.record', $this->letter->id))->assertNotFound();

        $role = Role::where('institution_id', $this->letter->institution_id)->whereHas('template', fn ($q) => $q->where('iri', 'like', '%/contributor'))->sole();
        $this->actingAs($this->editor)->post(route('records.embargoes.exceptions.add', [$this->letter, $embargo]), ['role_id' => $role->id])->assertSessionHasNoErrors();
        $this->assertSame(3, $embargo->exceptions()->count());
        $this->actingAs($this->editor)->post(route('records.embargoes.exceptions.add', [$this->letter, $embargo]), ['role_id' => $role->id, 'user_id' => $this->translator->id])
            ->assertSessionHasErrors('exception');
        $this->actingAs($this->editor)->post(route('records.embargoes.exceptions.add', [$this->letter, $embargo]), ['ip_from' => '10.0.0.9', 'ip_to' => '10.0.0.1'])->assertSessionHasErrors('exception');
    }

    public function test_it_is_in_force_by_its_dates_until_lifted_and_can_cover_everything_inside(): void
    {
        $today = CarbonImmutable::now(config('workspace.timezone'));
        $this->actingAs($this->editor)->post(route('records.embargoes.store', $this->fonds), ['type' => 'full', 'starts_on' => $today->addDay()->toDateString(), 'inside' => '1'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', '2 embargoes imposed, on it and everything inside it.');
        auth()->logout();
        $this->assertSame(200, $this->visitor()[0], 'not begun');
        $this->travelTo($today->addDays(2));
        $this->assertSame(404, $this->visitor()[0], 'begun');
        $letterEmbargo = Embargo::where('record_resource_id', $this->letter->id)->sole();
        $this->actingAs($this->editor)->post(route('records.embargoes.lift', [$this->letter, $letterEmbargo]), ['lift_reason' => 'Cleared by the donor.'])->assertSessionHasNoErrors();
        auth()->logout();
        $this->assertSame(200, $this->visitor()[0], 'the letter\'s own embargo lifted');
        $this->get(route('discover.record', $this->fonds->id))->assertNotFound();
        Mail::assertQueued(EmbargoMail::class, fn ($m) => $m->event === 'lifted' && $m->hasTo('editor@example.org'));

        $this->actingAs($this->editor)->post(route('records.embargoes.store', $this->letter), ['type' => 'full', 'starts_on' => '2026-01-10', 'ends_on' => '2026-01-01'])
            ->assertSessionHasErrors('embargo');
    }

    public function test_the_daily_run_warns_before_one_ends_and_tells_once_it_has(): void
    {
        $today = CarbonImmutable::now(config('workspace.timezone'))->startOfDay();
        $this->impose(['type' => 'metadata-only', 'ends_on' => $today->addDays(40)->toDateString(), 'notify_days_before' => 30]);
        $embargoes = app(Embargoes::class);
        $this->assertSame(['warned' => 0, 'ended' => 0], $embargoes->process($today));
        $this->assertSame(['warned' => 1, 'ended' => 0], $embargoes->process($today->addDays(10)));
        $this->assertSame(['warned' => 0, 'ended' => 0], $embargoes->process($today->addDays(11)), 'once');
        $this->assertSame(['warned' => 0, 'ended' => 1], $embargoes->process($today->addDays(41)));
        $this->assertSame(['warned' => 0, 'ended' => 0], $embargoes->process($today->addDays(42)));
        Mail::assertQueued(EmbargoMail::class, fn ($m) => $m->event === 'ending');
        Mail::assertQueued(EmbargoMail::class, fn ($m) => $m->event === 'ended');
        $this->artisan('workspace:embargoes')->assertSuccessful();
    }

    public function test_the_list_statistics_and_csv(): void
    {
        $this->impose(['type' => 'partial', 'reason' => 'donor-restriction', 'ends_on' => CarbonImmutable::now(config('workspace.timezone'))->addDays(10)->toDateString()]);
        $this->actingAs($this->editor)->get(route('embargoes.index'))->assertOk()->assertSee('Letter of 3 May 1902');
        $this->actingAs($this->editor)->get(route('embargoes.index', ['show' => 'ending']))->assertOk()->assertSee('Letter of 3 May 1902');
        $csv = $this->actingAs($this->editor)->get(route('embargoes.index', ['format' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('"Letter of 3 May 1902",'.$this->letter->iri.',partial,donor-restriction', $csv);
        $this->assertEquals(['in_force' => 1, 'by_type' => ['partial' => 1], 'ending' => 1, 'total' => 1],
            array_intersect_key(app(Embargoes::class)->statistics($this->letter->institution_id), array_flip(['in_force', 'by_type', 'ending', 'total'])));
        $this->actingAs($this->translator)->get(route('embargoes.index'))->assertForbidden();
        $this->actingAs($this->translator)->post(route('records.embargoes.store', $this->letter), ['type' => 'full'])->assertForbidden();
    }
}
