<?php

// OpenRiC Workspace - the text layer of an uploaded PDF becomes a proposed transcript (roadmap phase 5): read in a job,
// proposed by the account that gave the file, accepted in the review queue; a scan gives none; older PDFs by command.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\InstantiationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Jobs\ExtractFileText;
use App\Jobs\JobsService;
use App\Jobs\ReadEmbeddedMetadata;
use App\Models\BackgroundJob;
use App\Models\Instantiation;
use App\Models\Institution;
use App\Models\Proposal;
use App\Models\RecordResource;
use App\Models\User;
use App\Proposals\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PdfTextTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private string $store;

    private Institution $institution;

    private User $editor;

    private User $admin;

    private RecordResource $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        $this->loadBundledVocabularies();
        $accounts = app(AccountService::class);
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        foreach (['editor' => 'editor', 'admin' => 'administrator'] as $who => $role) {
            $this->{$who} = $accounts->create($this->institution, ucfirst($who), "$who@example.org", 'correct horse battery staple');
            $accounts->grantTemplateRole($this->{$who}, $role);
        }
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $this->item = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Minute book, 1902', 'en', null, $holder, 'MB1');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->store)) {
            exec('rm -rf '.escapeshellarg($this->store));
        }
        parent::tearDown();
    }

    /** A PDF of one page per text given, in Helvetica; an empty text gives a page with no text layer, as a scan has. */
    private function pdf(string $name, string ...$pages): UploadedFile
    {
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
        $kids = [];
        foreach ($pages as $text) {
            $stream = $text === '' ? '' : 'BT /F1 14 Tf 72 720 Td ('.addcslashes($text, '()\\').') Tj ET';
            $objects[] = '<< /Length '.strlen($stream)." >>\nstream\n$stream\nendstream";
            $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents '.count($objects).' 0 R >>';
            $kids[] = count($objects).' 0 R';
        }
        $objects[1] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n$object\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";

        return UploadedFile::fake()->createWithContent($name, $pdf);
    }

    private function upload(UploadedFile $file): Instantiation
    {
        $this->actingAs($this->editor)->post("/records/{$this->item->id}/instantiations", ['file' => $file, 'language' => 'en', 'availability' => 'online'])
            ->assertRedirect()->assertSessionHasNoErrors();

        return Instantiation::where('record_resource_id', $this->item->id)->latest('id')->firstOrFail();
    }

    private function textJob(Instantiation $instantiation): BackgroundJob
    {
        return BackgroundJob::where(['subject_table' => 'instantiations', 'subject_id' => $instantiation->id])
            ->whereHas('type', fn ($q) => $q->where('notation', 'file-text'))->firstOrFail();
    }

    public function test_the_text_layer_of_an_uploaded_pdf_is_proposed_as_its_transcript_and_accepted_in_review(): void
    {
        Queue::fake();
        $instantiation = $this->upload($this->pdf('minutes.pdf', 'Minutes of the meeting held on 3 March 1902', 'Present: the Mayor and six councillors'));
        $job = $this->textJob($instantiation);
        $this->assertSame('Text of minutes.pdf', $job->name);
        $this->assertSame($this->editor->id, $job->user_id);
        Queue::assertPushed(ExtractFileText::class, fn ($queued) => $queued->jobId === $job->id);

        app()->call([new ExtractFileText($job->id, $instantiation->id), 'handle']);
        $this->assertSame('completed', $job->fresh()->status->notation);
        $proposal = Proposal::with('kind', 'status')->sole();
        $this->assertSame(['transcript', 'proposed', $instantiation->id, $this->editor->id, ExtractFileText::LABEL],
            [$proposal->kind->notation, $proposal->status->notation, $proposal->instantiation_id, $proposal->proposed_by_id, $proposal->origin_label]);
        $this->assertSame("Minutes of the meeting held on 3 March 1902\n\nPresent: the Mayor and six councillors", $proposal->value, 'each page, a blank line between');
        $this->assertStringContainsString('The text layer of minutes.pdf, read with pdftotext', $proposal->evidence);
        $this->assertTrue($job->fresh()->output['proposed']);

        // The same text is not proposed twice while it waits.
        $again = app(JobsService::class)->create($this->institution->id, 'file-text', 'Again', $this->editor, $instantiation);
        app()->call([new ExtractFileText($again->id, $instantiation->id), 'handle']);
        $this->assertFalse($again->fresh()->output['proposed']);
        $this->assertSame(1, Proposal::count());

        $this->actingAs($this->admin)->get('/proposals?kind=transcript')->assertOk()->assertSee('Present: the Mayor and six councillors');
        app(ReviewService::class)->accept($proposal, $this->admin, ['language' => 'en']);
        $this->assertSame("Minutes of the meeting held on 3 March 1902\n\nPresent: the Mayor and six councillors",
            $instantiation->fresh()->described('instantiation-transcript', ['en']));
    }

    public function test_a_scanned_pdf_without_a_text_layer_proposes_nothing_and_an_image_queues_no_text_job(): void
    {
        Queue::fake();
        $scan = $this->upload($this->pdf('scan.pdf', ''));
        $job = $this->textJob($scan);
        app()->call([new ExtractFileText($job->id, $scan->id), 'handle']);
        $this->assertSame('completed', $job->fresh()->status->notation);
        $this->assertEquals(['proposed' => false, 'reason' => 'the PDF has no text layer'], $job->fresh()->output);
        $this->assertSame(0, Proposal::count());

        $this->upload(UploadedFile::fake()->image('photo.png', 400, 300));
        Queue::assertPushed(ExtractFileText::class, 1);
        Queue::assertPushed(ReadEmbeddedMetadata::class, 2);
    }

    public function test_older_pdfs_are_queued_by_command_once(): void
    {
        Queue::fake();
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, $this->pdf('ledger.pdf', 'Ledger of rates, 1903')->getContent());
        // Kept the way an upload before this release kept it: no text job.
        app(InstantiationService::class)->upload($this->item, $path, 'ledger.pdf', 'en', null, null, 'online', $this->editor);
        unlink($path);
        BackgroundJob::query()->delete();
        Queue::fake();

        $this->artisan('workspace:files:read')->assertFailed();
        $this->artisan('workspace:files:read', ['--as' => 'nobody@example.org'])->expectsOutputToContain('No account has the email address nobody@example.org.')->assertFailed();
        $this->artisan('workspace:files:read', ['--as' => 'admin@example.org'])->expectsOutputToContain('Queued: the embedded metadata of 1 files, the text of 1 PDFs.')->assertSuccessful();
        $job = BackgroundJob::whereHas('type', fn ($q) => $q->where('notation', 'file-text'))->sole();
        $this->assertSame($this->admin->id, $job->user_id);
        app()->call([new ExtractFileText($job->id, $job->subject_id), 'handle']);
        $this->assertSame('Ledger of rates, 1903', Proposal::sole()->value);
        $this->artisan('workspace:files:read', ['--as' => 'admin@example.org'])->expectsOutputToContain('Queued: the embedded metadata of 0 files, the text of 0 PDFs.')->assertSuccessful();
    }
}
