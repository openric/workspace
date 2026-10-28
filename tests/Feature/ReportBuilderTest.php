<?php

// OpenRiC Workspace - the report builder (roadmap phase 8): reports from templates, columns from the standard, sections, exports and access.
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
use App\Models\CustomReport;
use App\Models\Institution;
use App\Models\ReportTemplate;
use App\Models\User;
use App\Reports\ReportBuilder;
use App\Support\Vocab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class ReportBuilderTest extends TestCase
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
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $fonds = app(RecordService::class)->create($this->institution, Vocab::BASE.'level-of-description/fonds', 'Council records', 'en', null, $holder, 'C1');
        $minutes = app(RecordService::class)->create($this->institution, Vocab::BASE.'level-of-description/item', 'Minutes of 1952', 'en', $fonds, $holder, '1');
        app(RecordService::class)->describe($minutes, ['extent' => '12 pages', 'scope-and-content' => 'The council decides on the bridge.'], 'en');
        app(AgentService::class)->create($this->institution, 'person', 'Botha, Maria', 'en');
        app(PublicationService::class)->apply($fonds, 'publish', null, false, $this->editor);
    }

    public function test_a_report_from_a_template_is_built_shown_and_exported_in_every_format(): void
    {
        $template = ReportTemplate::where('name', 'List of archival descriptions')->firstOrFail();
        $this->assertTrue($template->isBundled());
        $this->actingAs($this->editor)->get('/reports/builder')->assertOk()->assertSee('Holdings summary')->assertSee('List of authority records');

        $this->actingAs($this->editor)->post('/reports/builder', ['template' => $template->id, 'name' => 'Council list'])->assertRedirect();
        $report = CustomReport::where('name', 'Council list')->firstOrFail();
        $this->assertSame(['record', 'draft'], [$report->source, $report->status->notation]);
        $this->assertSame(['summary', 'table'], $report->sections()->with('type')->get()->pluck('type.notation')->all());
        $this->assertDatabaseHas('audit_log', ['subject_id' => $report->id, 'actor_id' => $this->editor->id]);

        // Its columns are the fixed fields and the ISAD(G) elements, never a table or SQL.
        $columns = app(ReportBuilder::class)->columns('record');
        $this->assertArrayHasKey('element:scope-and-content', $columns);
        $this->assertArrayNotHasKey('element:title', $columns, 'the title is the Name column');
        $this->assertSame('3.3.1 Scope and content', $columns['element:scope-and-content']);

        $this->actingAs($this->editor)->get("/reports/builder/{$report->id}")->assertOk()
            ->assertSeeInOrder(['Summary', 'Archival descriptions', '<td>2</td>', 'Published', '<td>1</td>', 'Council records', 'Minutes of 1952', '12 pages', 'The council decides on the bridge.'], false);

        // Filtered to the items, with a counts section and a text section, moved to the top.
        $item = Concept::where('iri', Vocab::BASE.'level-of-description/item')->value('id');
        $this->actingAs($this->editor)->post("/reports/builder/{$report->id}", ['name' => 'Council items', 'columns' => ['name', 'level', 'element:extent'],
            'filters' => ['level' => $item], 'sort' => ['by' => 'name', 'direction' => 'desc'], 'shared' => '1'])->assertRedirect();
        $this->actingAs($this->editor)->post("/reports/builder/{$report->id}", ['name' => 'x', 'columns' => ['DROP TABLE users']])->assertSessionHasErrors('columns.0');
        $this->actingAs($this->editor)->post("/reports/builder/{$report->id}/sections", ['type' => 'counts', 'title' => 'By level', 'by' => 'level'])->assertRedirect();
        $this->actingAs($this->editor)->post("/reports/builder/{$report->id}/sections", ['type' => 'counts', 'title' => 'Wrong'])->assertSessionHasErrors('section');
        $this->actingAs($this->editor)->post("/reports/builder/{$report->id}/sections", ['type' => 'text', 'title' => 'Introduction', 'content' => "The items of the fonds.\n\nCounted in October."])->assertRedirect();
        $text = $report->sections()->where('title', 'Introduction')->firstOrFail();
        foreach (range(1, 3) as $step) {
            $this->actingAs($this->editor)->post("/reports/builder/{$report->id}/sections/{$text->id}/move", ['direction' => 'up']);
        }
        $this->assertSame('Introduction', $report->sections()->first()->title);
        $page = $this->actingAs($this->editor)->get("/reports/builder/{$report->id}")->assertOk()
            ->assertSeeInOrder(['Introduction', 'The items of the fonds.', 'Counted in October.', 'Minutes of 1952', 'By level', 'Item', '<td>1</td>'], false);
        $this->assertStringNotContainsString('<td>Council records</td>', $page->getContent());

        $csv = $this->actingAs($this->editor)->get("/reports/builder/{$report->id}/export/csv")->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();
        $this->assertStringContainsString("Name,\"Level of description\",\"3.1.5 Extent and medium\"\n\"Minutes of 1952\",Item,\"12 pages\"", $csv);
        $pdf = $this->actingAs($this->editor)->get("/reports/builder/{$report->id}/export/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        foreach (['xlsx' => 'xl/worksheets/sheet2.xml', 'docx' => 'word/document.xml'] as $format => $part) {
            $file = tempnam(sys_get_temp_dir(), 'report');
            file_put_contents($file, $this->actingAs($this->editor)->get("/reports/builder/{$report->id}/export/$format")->assertOk()->getContent());
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($file) === true, "$format is a zip");
            $this->assertStringContainsString('Minutes of 1952', (string) $zip->getFromName($format === 'xlsx' ? 'xl/sharedStrings.xml' : $part));
            $zip->close();
            unlink($file);
        }
        $this->actingAs($this->editor)->get("/reports/builder/{$report->id}/export/exe")->assertNotFound();

        // Shared: another editor reads and copies it, but only its owner changes it.
        $other = app(AccountService::class)->create($this->institution, 'Other', 'other@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($other, 'editor');
        $this->actingAs($other)->get("/reports/builder/{$report->id}")->assertOk();
        $this->actingAs($other)->get("/reports/builder/{$report->id}/edit")->assertForbidden();
        $this->actingAs($other)->post("/reports/builder/{$report->id}/remove")->assertForbidden();
        $this->actingAs($other)->post("/reports/builder/{$report->id}/copy")->assertRedirect();
        $copy = CustomReport::where('user_id', $other->id)->firstOrFail();
        $this->assertSame(['Copy of Council items', false, 4], [$copy->name, $copy->shared, $copy->sections()->count()]);

        // Unshared, it is the owner's alone; another institution's does not exist here.
        $this->actingAs($this->editor)->post("/reports/builder/{$report->id}", ['name' => 'Council items', 'columns' => ['name'], 'shared' => '0']);
        $this->actingAs($other)->get("/reports/builder/{$report->id}")->assertForbidden();
        $elsewhere = Institution::create(['name' => 'Elsewhere', 'base_iri' => 'https://elsewhere.example.org']);
        app(RoleService::class)->seedDefaults($elsewhere);
        $stranger = app(AccountService::class)->create($elsewhere, 'Stranger', 'stranger@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($stranger, 'editor');
        $this->actingAs($stranger)->get("/reports/builder/{$report->id}")->assertNotFound();

        // The status changes, its structure becomes the institution's template, and it is removed.
        $approved = Concept::where('iri', ReportBuilder::STATUSES.'approved')->value('id');
        $this->actingAs($this->editor)->post("/reports/builder/{$report->id}/status", ['status' => $approved])->assertRedirect();
        $this->assertSame('approved', $report->fresh()->status->notation);
        $this->actingAs($this->editor)->post("/reports/builder/{$report->id}/template", ['name' => 'Items of a fonds'])->assertRedirect();
        $own = ReportTemplate::where('name', 'Items of a fonds')->firstOrFail();
        $this->assertSame([$this->institution->id, 'record', 4], [$own->institution_id, $own->structure['source'], count($own->structure['sections'])]);
        $this->actingAs($stranger)->get('/reports/builder')->assertOk()->assertDontSee('<strong>Items of a fonds</strong>', false);
        $this->actingAs($this->editor)->post("/reports/builder/{$report->id}/remove")->assertRedirect(route('reports.builder.index'));
        $this->assertNull(CustomReport::find($report->id));
        $this->assertDatabaseMissing('custom_report_sections', ['report_id' => $report->id]);
    }

    public function test_a_report_on_authority_records_counts_by_type(): void
    {
        $this->actingAs($this->editor)->post('/reports/builder', ['template' => ReportTemplate::where('name', 'List of authority records')->value('id'), 'name' => 'People'])->assertRedirect();
        $report = CustomReport::where('name', 'People')->firstOrFail();
        $this->actingAs($this->editor)->get("/reports/builder/{$report->id}")->assertOk()->assertSeeInOrder(['By type of entity', 'Person', '<td>1</td>', 'Botha, Maria'], false);
        $this->actingAs($this->editor)->post('/reports/builder', ['name' => 'Bad', 'source' => 'users'])->assertSessionHasErrors('source');
    }
}
