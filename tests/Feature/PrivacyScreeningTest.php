<?php

// OpenRiC Workspace - privacy in the catalogue (roadmap phase 18b, ADR 0016): the scanner finds personal information
// by pattern (and checks a South African identity number), a scan reads a record's description, its files' embedded
// metadata and their transcripts, a field redacted for the public is redacted wherever the public reads it, and where
// screening is required a record's files wait until a scan of every file found nothing or a person released them.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\InstantiationService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Instantiation;
use App\Models\Institution;
use App\Models\PiiScanReport;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\User;
use App\Privacy\PiiScanner;
use App\Privacy\Redaction;
use App\Privacy\Screening;
use App\Search\SearchIndex;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Imagick;
use ImagickPixel;
use Tests\TestCase;

class PrivacyScreeningTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private string $store;

    private User $officer;

    private User $editor;

    private Repository $holder;

    private RecordResource $file;

    private Instantiation $scan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($institution);
        foreach (['officer' => 'administrator', 'editor' => 'editor'] as $who => $template) {
            $this->{$who} = app(AccountService::class)->create($institution, ucfirst($who), "$who@example.org", 'correct horse battery staple');
            app(AccountService::class)->grantTemplateRole($this->{$who}, $template);
        }
        $this->holder = app(RepositoryService::class)->create($institution, 'Riverside Archives', 'en', 'RA');
        $this->file = app(RecordService::class)->create($institution, self::LEVEL.'file', 'Application of Jan Botha', 'en', null, $this->holder, 'TL-7');
        app(RecordService::class)->describe($this->file, ['scope-and-content' => 'Application for a trading licence. Write to jan.botha@example.org or call 082 337 1406.'], 'en');
        $image = new Imagick;
        $image->newImage(300, 200, new ImagickPixel('#d8c9a3'));
        $image->setImageFormat('png');
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, $image->getImageBlob());
        $this->scan = app(InstantiationService::class)->upload($this->file, $path, 'application.png', 'en', 'Scan of the application', null, 'online', $this->editor);
        foreach ([$this->holder, $this->file] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->editor);
        }
        app(PublicationService::class)->apply($this->scan, 'publish', null, false, $this->editor);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->store)) {
            exec('rm -rf '.escapeshellarg($this->store));
        }
        parent::tearDown();
    }

    /** What a visitor receives of the file's reference copy. */
    private function visitorFile(): int
    {
        auth()->logout();

        return $this->get(route('discover.file', $this->scan->file('reference')))->getStatusCode();
    }

    public function test_the_scanner_finds_personal_information_by_pattern_and_checks_an_identity_number(): void
    {
        $found = collect((new PiiScanner('popia'))->scan('Born 1 January 1980, ID 8001015009087; mail jan@example.org; card 4111 1111 1111 1111. Not an ID: 8001015009088.'))
            ->map(fn ($f) => $f['type'].':'.$f['value'])->all();
        $this->assertContains('email:jan@example.org', $found);
        $this->assertContains('national_id:8001015009087', $found);
        $confidence = collect((new PiiScanner('popia'))->scan('8001015009087 and 8001015009088'))->pluck('confidence', 'value');
        $this->assertGreaterThan($confidence['8001015009088'], $confidence['8001015009087'], 'a wrong check digit is only a possible identity number, as Heratio has it');
        $this->assertTrue(collect($found)->contains(fn ($f) => str_starts_with($f, 'credit_card:')), 'a card number that passes Luhn');
    }

    public function test_a_scan_reads_the_description_the_embedded_metadata_and_the_transcripts(): void
    {
        $this->scan->file('master')->update(['embedded_metadata' => ['XMP:Creator' => 'Jan Botha', 'XMP:CreatorContactInfoCiEmailWork' => 'jan.botha@example.org']]);
        $this->actingAs($this->officer)->post(route('records.privacy.scan', $this->file), ['jurisdiction' => 'popia'])->assertRedirect()->assertSessionHasNoErrors();
        $report = PiiScanReport::sole();
        $this->assertSame(['pending', 'metadata'], [$report->status->notation, $report->coverage->notation], 'no transcript: the file itself was not read');
        $this->assertSame(['email' => 2, 'phone' => 1], collect($report->hits_by_type)->sortKeys()->all());
        $this->assertTrue(collect($report->findings)->contains(fn ($f) => str_starts_with($f['field'], 'embedded metadata of application.png')));

        app(InstantiationService::class)->describe($this->scan, ['instantiation-transcript' => 'Dear Sir, I apply for a licence.'], 'en', $this->editor);
        $this->actingAs($this->officer)->post(route('records.privacy.scan', $this->file), ['jurisdiction' => 'popia'])->assertRedirect();
        $this->assertSame('files', app(Screening::class)->latest($this->file)->coverage->notation, 'every file has its transcript');
        $this->actingAs($this->officer)->get(route('records.show', $this->file))->assertOk()->assertSee('jan.botha@example.org')->assertSee('Scan again');
        $this->actingAs($this->officer)->get(route('privacy.screening'))->assertOk()->assertSee('Application of Jan Botha');
    }

    public function test_a_redacted_field_is_redacted_wherever_the_public_reads_it(): void
    {
        $redact = fn (array $data) => $this->actingAs($this->officer)->post(route('records.privacy.redact', $this->file), $data);
        $redact(['field' => 'scope-and-content', 'type' => 'full', 'reason' => 'Contact details of a living person.', 'legal_basis' => 'POPIA s.11', 'is_sensitive' => '0'])->assertSessionHasNoErrors();
        $this->assertSame(['POPIA s.11', false], [app(Redaction::class)->of($this->file)['scope-and-content']->legal_basis, app(Redaction::class)->of($this->file)['scope-and-content']->is_sensitive]);
        $redact(['field' => 'title', 'type' => 'pseudonymised', 'reason' => 'The applicant is alive.'])->assertSessionHasNoErrors();
        $redact(['field' => 'title', 'type' => 'partial', 'reason' => 'x'])->assertSessionHasErrors('redaction');
        $pseudonym = app(Redaction::class)->pseudonymise('Application of Jan Botha');
        app(SearchIndex::class)->flush();
        auth()->logout();

        $this->get(route('discover.record', $this->file->id))->assertOk()->assertSee(Redaction::FULL_PLACEHOLDER)->assertSee($pseudonym)
            ->assertDontSee('jan.botha@example.org')->assertDontSee('Jan Botha');
        $this->assertStringNotContainsString('jan.botha', $this->get('/api/ric/v1/records/'.$this->file->id)->assertOk()->getContent());
        $public = DB::table('search_entries')->where(['subject_id' => $this->file->id, 'public' => true])->first();
        $this->assertStringNotContainsString('Botha', $public->body.$public->title, 'the public search entry holds what the public may read');
        $this->assertSame('Application of Jan Botha', $this->file->fresh()->described('title', ['en']), 'the record keeps the original');
        $this->actingAs($this->editor)->get(route('records.show', $this->file))->assertOk()->assertSee('jan.botha@example.org');

        $this->assertSame('j***@***', app(Redaction::class)->applyPattern('jan@example.org', 'email-partial'));
        $this->assertSame('*********9087', app(Redaction::class)->applyPattern('8001015009087', 'id-last4'));
        $this->assertSame('1980', app(Redaction::class)->applyPattern('1 January 1980', 'year-only'));
    }

    public function test_where_screening_is_required_files_wait_until_cleared(): void
    {
        $this->assertSame(200, $this->visitorFile(), 'screening is required nowhere yet');
        $this->actingAs($this->officer)->post(route('privacy.screening.require'), ['repository_id' => $this->holder->id])->assertSessionHasNoErrors();
        $this->assertSame(404, $this->visitorFile(), 'not scanned');
        $this->actingAs($this->editor)->get(route('discover.file', $this->scan->file('reference')))->assertOk();

        $this->actingAs($this->officer)->post(route('records.privacy.files', $this->file), ['decision' => 'release', 'note' => 'Checked'])->assertSessionHasErrors('screening');
        $this->actingAs($this->officer)->post(route('records.privacy.scan', $this->file), ['jurisdiction' => 'popia']);
        $this->assertSame(404, $this->visitorFile(), 'the scan found something');
        $this->actingAs($this->officer)->post(route('records.privacy.files', $this->file), ['decision' => 'release'])->assertSessionHasErrors('screening');
        $this->actingAs($this->officer)->post(route('records.privacy.files', $this->file), ['decision' => 'redacted', 'ground' => 'popia', 'note' => 'The contact details are redacted.'])
            ->assertSessionHasNoErrors();
        $this->assertSame(200, $this->visitorFile());
        $this->actingAs($this->officer)->post(route('records.privacy.files', $this->file), ['decision' => 'withhold', 'ground' => 'popia'])->assertSessionHasNoErrors();
        $this->assertSame(404, $this->visitorFile());

        // A scan that read every file and found nothing clears them by itself.
        app(RecordService::class)->describe($this->file, ['scope-and-content' => 'Application for a trading licence.'], 'en');
        app(InstantiationService::class)->describe($this->scan, ['instantiation-transcript' => 'Dear Sir, I apply for a licence.'], 'en', $this->editor);
        $this->actingAs($this->officer)->post(route('records.privacy.scan', $this->file), ['jurisdiction' => 'popia']);
        $this->assertSame(0, app(Screening::class)->latest($this->file)->hits_total);
        $this->assertSame(200, $this->visitorFile());
    }

    public function test_only_who_may_manage_privacy_does_this_work(): void
    {
        $this->actingAs($this->editor)->post(route('records.privacy.scan', $this->file))->assertForbidden();
        $this->actingAs($this->editor)->get(route('privacy.screening'))->assertForbidden();
        $this->actingAs($this->editor)->get(route('records.show', $this->file))->assertOk()->assertDontSee('id="privacy"', false);
        $this->actingAs($this->officer)->post(route('privacy.screening.require'), ['record' => 'https://elsewhere.example.org/x'])->assertSessionHasErrors('screening');
    }
}
