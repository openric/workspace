<?php

// OpenRiC Workspace - reproductions, bibliographies, citations and saved searches (roadmap phase 19c, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Bibliography;
use App\Models\CitationLogEntry;
use App\Models\Favourite;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\ReproductionFile;
use App\Models\ReproductionRequest;
use App\Models\Researcher;
use App\Models\SavedSearch;
use App\Models\SearchAlertLogEntry;
use App\Models\User;
use App\Research\Reproductions;
use App\Search\SearchIndex;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ResearchToolsTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private string $store;

    private Institution $institution;

    private User $admin;

    private User $reader;

    private RecordResource $fonds;

    private RecordResource $letter;

    private RecordResource $draft;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        Mail::fake();
        $this->travelTo('2026-11-02 08:00');
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->admin = app(AccountService::class)->create($this->institution, 'Admin', 'admin@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->admin, 'administrator');
        $this->reader = app(AccountService::class)->create($this->institution, 'Rita', 'rita@example.org', 'correct horse battery staple');
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $this->fonds = app(RecordService::class)->create($this->institution, self::LEVEL.'fonds', 'Parish council records', 'en', null, $holder, 'PC');
        $this->letter = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Letter about the parish bridge', 'en', $this->fonds, null, '1');
        $this->draft = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Minutes not yet published', 'en', $this->fonds, null, '2');
        foreach ([$holder, $this->fonds, $this->letter] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->admin);
        }
        app(SearchIndex::class)->flush();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->store)) {
            exec('rm -rf '.escapeshellarg($this->store));
        }
        parent::tearDown();
    }

    private function approve(User $user): void
    {
        $this->actingAs($user)->post(route('research.register'), ['first_name' => $user->name, 'last_name' => 'Researcher', 'affiliation' => 'academic'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('reading-room.researchers.decide', [Researcher::where('user_id', $user->id)->sole(), 'approve']))->assertSessionHasNoErrors();
    }

    public function test_a_reproduction_is_estimated_invoiced_paid_made_and_downloaded(): void
    {
        $this->actingAs($this->reader)->post(route('research.reproductions.store'), ['delivery_method' => 'download'])->assertSessionHasErrors('research');
        $this->approve($this->reader);
        $this->actingAs($this->reader)->post(route('research.reproductions.store'), ['delivery_method' => 'post'])->assertSessionHasErrors('research');
        $this->actingAs($this->reader)->post(route('research.reproductions.store'), ['delivery_method' => 'download', 'purpose' => 'A book on the parish'])->assertRedirect();
        $request = ReproductionRequest::sole();
        $this->assertSame(['REP-2026-00001', 'draft'], [$request->reference, $request->status->notation]);
        $step = fn (string $step, array $data = [], ?User $as = null) => $this->actingAs($as ?? $this->reader)->post(route('research.reproductions.step', [$request, $step]), $data);
        $step('submit')->assertSessionHasErrors('research');
        $step('item', ['iri' => $this->letter->iri, 'reproduction_type' => 'scan', 'quantity' => 10, 'colour_mode' => 'colour', 'resolution' => '600 dpi'])->assertSessionHasNoErrors();
        $step('submit')->assertSessionHasNoErrors();
        $this->assertSame(['submitted', '28.13'], [$request->fresh()->status->notation, $request->fresh()->estimated_cost], '(5 + 1 x 10) x 1.5 for colour x 1.25 for 600 dpi');

        $step('status', ['status' => 'processing'])->assertSessionHasErrors('research');
        $step('status', ['status' => 'processing'], $this->admin)->assertSessionHasNoErrors();
        $step('status', ['status' => 'awaiting-payment'], $this->admin)->assertSessionHasNoErrors();
        $this->assertSame('INV-202611-00001', $request->fresh()->invoice_number);
        $step('payment', ['amount' => '28.13', 'reference' => 'EFT 991', 'method' => 'EFT'], $this->admin)->assertSessionHasNoErrors();
        $this->assertSame(['in-production', '28.13'], [$request->fresh()->status->notation, $request->fresh()->final_cost]);
        $step('status', ['status' => 'completed'], $this->admin)->assertSessionHasErrors('research');
        $item = $request->items()->sole();
        $this->actingAs($this->admin)->post(route('research.reproductions.item', [$item, 'finish']), ['status' => 'completed',
            'files' => [UploadedFile::fake()->createWithContent('letter-scan.pdf', '%PDF-1.4 test')]])->assertSessionHasNoErrors();
        $step('status', ['status' => 'completed'], $this->admin)->assertSessionHasNoErrors();
        $this->assertSame(['requested', 'draft', 'submitted', 'processing', 'awaiting-payment', 'in-production', 'completed'],
            ['requested', ...$request->statusChanges()->pluck('new_status')->all()]);

        $file = ReproductionFile::sole();
        $token = app(Reproductions::class)->downloadToken($item, $file->research_file_id);
        $this->actingAs($this->admin)->get(route('research.reproductions.download', $token))->assertNotFound();
        $this->actingAs($this->reader)->get(route('research.reproductions.download', $token))->assertOk();
        $this->assertSame(1, $file->fresh()->download_count);
        $this->actingAs($this->reader)->get(route('research.reproductions.show', $request))->assertOk()->assertSee('letter-scan.pdf')->assertSee('INV-202611-00001');
    }

    public function test_a_bibliography_takes_records_works_and_imports_and_is_exported(): void
    {
        $this->actingAs($this->reader)->post(route('research.bibliographies.store'), ['name' => 'Parish history', 'citation_style' => 'chicago'])->assertRedirect();
        $bibliography = Bibliography::sole();
        $step = fn (string $step, array $data) => $this->actingAs($this->reader)->post(route('research.bibliographies.step', [$bibliography, $step]), $data);
        $step('record', ['iri' => $this->letter->iri])->assertSessionHasNoErrors();
        $step('record', ['iri' => $this->letter->iri])->assertSessionHasErrors('research');
        $step('entry', ['entry_type' => 'book', 'title' => 'A History of the Parish', 'authors' => 'Botha, Anna', 'date' => '1998', 'publisher' => 'Juta'])->assertSessionHasNoErrors();
        $step('import', ['format' => 'bibtex', 'text' => "@article{smit2001,\n  title = {Bridges of the {Cape}},\n  author = {Smit, Jan and Botha, Anna},\n  journal = {Historia},\n  year = {2001},\n  volume = {46}\n}\n@book{x, title = \"Roads\", year = 1950}"])->assertSessionHasNoErrors();
        $step('import', ['format' => 'ris', 'text' => "TY  - THES\r\nTI  - Parish councils 1850-1910\r\nAU  - Naidoo, Priya\r\nPY  - 2015\r\nER  - "])->assertSessionHasNoErrors();
        $step('import', ['format' => 'citation', 'text' => 'Van Wyk, "The council and the river," 1987, https://example.org/river.'])->assertSessionHasNoErrors();
        $bibliography->refresh();
        $this->assertSame(['Letter about the parish bridge', 'A History of the Parish', 'Bridges of the Cape', 'Roads', 'Parish councils 1850-1910', 'The council and the river'],
            $bibliography->entries->pluck('title')->all());
        $this->assertSame(['archival', 'Smit, Jan; Botha, Anna', 'RA PC/1'], [$bibliography->entries[0]->entryType->notation, $bibliography->entries[2]->authors, $bibliography->entries[0]->archive_location]);

        $export = fn (string $format) => $this->actingAs($this->reader)->get(route('research.bibliographies.export', [$bibliography, $format]))->assertOk()->getContent();
        $this->assertStringContainsString("TY  - THES\r\nTI  - Parish councils 1850-1910\r\nAU  - Naidoo, Priya", $export('ris'));
        $this->assertStringContainsString("@article{smit2001,\n  title = {Bridges of the Cape},\n  author = {Smit, Jan and Botha, Anna}", $export('bibtex'));
        $this->assertStringContainsString('<dc:title>A History of the Parish</dc:title>', $export('zotero'));
        $this->assertSame('thesis', json_decode($export('mendeley'), true)[4]['type']);
        $this->assertSame([['family' => 'Smit', 'given' => 'Jan'], ['family' => 'Botha', 'given' => 'Anna']], json_decode($export('csl'), true)[2]['author']);
        $this->assertStringContainsString('Botha, Anna, "A History of the Parish," Juta, 1998.', $export('text'));

        // Shared by a link; favourites sent to it.
        $step('share', ['share' => '1'])->assertSessionHas('share_link');
        auth()->logout();
        $this->get(session('share_link'))->assertOk()->assertSee('A History of the Parish');
        $this->actingAs($this->reader)->post(route('favourites.toggle', ['records', $this->fonds->id]));
        $this->actingAs($this->reader)->post(route('research.bibliographies.from-favourites'), ['ids' => Favourite::pluck('id')->all(), 'bibliography_id' => $bibliography->id])
            ->assertSessionHas('status', '1 record was added.');
    }

    public function test_a_record_is_cited_in_every_style_and_the_citation_logged(): void
    {
        $this->get(route('cite', $this->letter->id))->assertOk()->assertSee('Letter about the parish bridge')->assertSee('Riverside Archives (n.d.)', false)
            ->assertSee('Riverside Archives. RA PC/1. Letter about the parish bridge.');
        $this->assertStringContainsString('TI  - Letter about the parish bridge', $this->get(route('cite', [$this->letter->id, 'format' => 'ris']))->assertOk()->getContent());
        $this->assertSame('ris', CitationLogEntry::sole()->format);
        $this->get(route('cite', $this->draft->id))->assertNotFound();
        $this->actingAs($this->admin)->get(route('cite', $this->draft->id))->assertOk();
    }

    public function test_a_saved_search_shows_what_is_new_and_alerts_its_owner(): void
    {
        $this->actingAs($this->reader)->post(route('research.searches.store'), ['name' => 'Bridges', 'search_query' => 'bridge', 'alert_enabled' => '1', 'alert_frequency' => 'weekly'])->assertSessionHasNoErrors();
        $search = SavedSearch::sole();
        $this->assertSame(1, $search->total_results_at_save);
        $more = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Plans of the bridge', 'en', $this->fonds, null, '3');
        app(PublicationService::class)->apply($more, 'publish', null, false, $this->admin);
        app(SearchIndex::class)->flush();
        $this->actingAs($this->reader)->get(route('research.searches.step', [$search, 'diff']))->assertOk()->assertSee('Plans of the bridge');

        $this->artisan('workspace:saved-search-alerts')->expectsOutputToContain('1 owners told')->assertSuccessful();
        $this->assertSame([1, 2, 1, true], [SearchAlertLogEntry::sole()->previous_count, SearchAlertLogEntry::sole()->new_count, SearchAlertLogEntry::sole()->new_items_count, SearchAlertLogEntry::sole()->notification_sent]);
        $this->artisan('workspace:saved-search-alerts')->assertSuccessful();
        $this->assertSame(1, SearchAlertLogEntry::count(), 'weekly: not again the next day');
        $this->actingAs($this->reader)->post(route('research.searches.step', [$search, 'snapshot']))->assertSessionHas('status', 'The snapshot holds 2 results.');
        $this->actingAs($this->admin)->get(route('research.searches.step', [$search, 'diff']))->assertNotFound();
    }
}
