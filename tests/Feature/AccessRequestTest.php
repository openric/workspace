<?php

// OpenRiC Workspace - access requests (roadmap phase 7): a researcher asks for the files of an instantiation available on
// request, a reviewer approves for some days or refuses with a reason, each step is emailed, the approved request opens
// the files until it ends and an expired one does not; requesters see only their own, reviewers those they may review.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\InstantiationService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Mail\AccessRequestApprovedMail;
use App\Models\AccessRequest;
use App\Models\Instantiation;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\SentEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Imagick;
use ImagickPixel;
use Tests\TestCase;

class AccessRequestTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://archives.example.org';

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private string $store;

    private Institution $institution;

    private User $editor;

    private User $researcher;

    private User $other;

    private User $contributor;

    private RecordResource $record;

    private Instantiation $scan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        $this->loadBundledVocabularies();
        $accounts = app(AccountService::class);
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => self::SITE]);
        app(RoleService::class)->seedDefaults($this->institution);
        foreach (['editor' => 'editor', 'researcher' => null, 'other' => null, 'contributor' => 'contributor'] as $who => $role) {
            $this->{$who} = $accounts->create($this->institution, ucfirst($who), "$who@example.org", 'correct horse battery staple');
            if ($role) {
                $accounts->grantTemplateRole($this->{$who}, $role);
            }
        }
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $this->record = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Minute book, 1902', 'en', null, $holder, 'MB1');
        $this->scan = $this->scanOf($this->record, 'Scan of the minute book');
        foreach ([$holder, $this->record] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->editor);
        }
    }

    private function scanOf(RecordResource $record, string $title): Instantiation
    {
        $image = new Imagick;
        $image->newImage(900, 600, new ImagickPixel('#d8c9a3'));
        $image->setImageFormat('png');
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, $image->getImageBlob());
        $scan = app(InstantiationService::class)->upload($record, $path, 'scan.png', 'en', $title, null, 'on-request', $this->editor);
        unlink($path);

        return $scan;
    }

    protected function tearDown(): void
    {
        if (is_dir($this->store)) {
            exec('rm -rf '.escapeshellarg($this->store));
        }
        parent::tearDown();
    }

    /** @return list<array{0: string, 1: string}> [recipient, subject] of each message the array mailer delivered */
    private function mails(): array
    {
        return app('mailer')->getSymfonyTransport()->messages()
            ->map(fn ($m) => [$m->getEnvelope()->getRecipients()[0]->getAddress(), $m->getOriginalMessage()->getSubject()])->values()->all();
    }

    private function ask(User $who): AccessRequest
    {
        $this->actingAs($who)->post("/instantiations/{$this->scan->id}/request-access", ['reason' => 'For a history of the parish council.', 'urgency' => 'high'])
            ->assertSessionHasNoErrors()->assertRedirect();

        return AccessRequest::where('user_id', $who->id)->whereHas('status', fn ($q) => $q->where('notation', 'pending'))->sole();
    }

    public function test_an_approved_request_opens_the_files_until_it_ends_and_an_expired_one_does_not(): void
    {
        $master = $this->scan->file('master');
        $this->actingAs($this->researcher)->get("/files/{$master->id}")->assertNotFound();
        $this->actingAs($this->researcher)->get("/instantiations/{$this->scan->id}")->assertOk()->assertSee('Ask for access to these files');
        $this->get(self::SITE."/discover/records/{$this->record->id}")->assertOk()->assertSee('Available on request: ask for access');
        $this->actingAs($this->researcher)->get("/instantiations/{$this->scan->id}/request-access")->assertOk()->assertSee('Scan of the minute book');

        $request = $this->ask($this->researcher);
        $this->assertSame(['pending', 'high'], [$request->status->notation, $request->urgency->notation]);
        $this->assertEqualsCanonicalizing([['researcher@example.org', 'Your request for access was received'], ['editor@example.org', 'A request for access waits for review']], $this->mails(),
            'the requester and each reviewer; not the contributor, who may not review');
        $this->actingAs($this->researcher)->post("/instantiations/{$this->scan->id}/request-access", ['reason' => 'Again.', 'urgency' => 'normal'])->assertSessionHasErrors('reason');
        $this->actingAs($this->researcher)->get("/instantiations/{$this->scan->id}")->assertSee('Your request for these files waits for review.');

        $this->actingAs($this->editor)->get('/access-requests')->assertOk()->assertSee('Scan of the minute book')->assertSee('Researcher');
        $this->actingAs($this->editor)->post("/access-requests/{$request->id}/approve", ['days' => 7, 'notes' => 'Handle with care.'])
            ->assertSessionHas('status', 'Approved: the requester may open the files and has been told.');
        $request = $request->fresh();
        $this->assertSame(['approved', $this->editor->id, 'Handle with care.'], [$request->status->notation, $request->reviewed_by_id, $request->review_notes]);
        $this->assertSame(now()->addDays(7)->toDateString(), $request->expires_at->toDateString());
        $this->assertSame(['researcher@example.org', 'Your request for access was approved'], $this->mails()[2]);
        $body = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('Handle with care.', $body);
        $this->assertStringContainsString('Scan of the minute book', $body);

        $this->actingAs($this->researcher)->get("/files/{$master->id}")->assertOk();
        $this->actingAs($this->researcher)->get("/files/{$this->scan->file('reference')->id}")->assertOk();
        $this->actingAs($this->other)->get("/files/{$master->id}")->assertNotFound(); // only the requester
        $this->get(self::SITE."/discover/files/{$master->id}")->assertNotFound();

        $this->travel(8)->days();
        $this->actingAs($this->researcher)->get("/files/{$master->id}")->assertNotFound();
        $this->artisan('workspace:access-requests:expire')->expectsOutputToContain('1 access requests expired.')->assertSuccessful();
        $this->assertSame('expired', $request->fresh()->status->notation);
        // A new request may follow once the old one is over.
        $this->ask($this->researcher);
    }

    public function test_a_refusal_needs_a_reason_and_requesters_and_reviewers_see_only_what_is_theirs(): void
    {
        $request = $this->ask($this->researcher);
        $this->actingAs($this->researcher)->get("/access-requests/{$request->id}")->assertOk()->assertDontSee('Approve');
        $this->actingAs($this->researcher)->post("/access-requests/{$request->id}/approve", ['days' => 7])->assertSessionHasErrors(['request' => 'You may not review requests for this record.']);
        $this->assertSame('pending', $request->fresh()->status->notation);
        $this->actingAs($this->other)->get("/access-requests/{$request->id}")->assertNotFound();
        $this->actingAs($this->contributor)->get('/access-requests')->assertForbidden();
        $this->actingAs($this->researcher)->get('/access-requests/mine')->assertOk()->assertSee('Scan of the minute book');
        $this->actingAs($this->other)->get('/access-requests/mine')->assertOk()->assertDontSee('Scan of the minute book');

        $this->actingAs($this->editor)->post("/access-requests/{$request->id}/deny", ['notes' => ''])->assertSessionHasErrors('notes');
        $this->actingAs($this->editor)->post("/access-requests/{$request->id}/approve", ['days' => 400])->assertSessionHasErrors('days');
        $this->actingAs($this->editor)->post("/access-requests/{$request->id}/deny", ['notes' => 'The minute book is closed until 2030.'])
            ->assertSessionHas('status', 'Refused: the requester has been told why.');
        $this->assertSame('denied', $request->fresh()->status->notation);
        $this->assertSame(['researcher@example.org', 'Your request for access was refused'], last($this->mails()));
        $this->actingAs($this->editor)->post("/access-requests/{$request->id}/approve", ['days' => 7])->assertSessionHasErrors('request');
        $this->actingAs($this->researcher)->get("/files/{$this->scan->file('master')->id}")->assertNotFound();

        // A cancelled approval ends access at once.
        $second = $this->ask($this->researcher);
        $this->actingAs($this->editor)->post("/access-requests/{$second->id}/approve", ['days' => 30]);
        $this->actingAs($this->researcher)->get("/files/{$this->scan->file('master')->id}")->assertOk();
        $this->actingAs($this->researcher)->post("/access-requests/{$second->id}/cancel")->assertSessionHas('status', 'Your request is cancelled.');
        $this->actingAs($this->researcher)->get("/files/{$this->scan->file('master')->id}")->assertNotFound();
    }

    public function test_a_general_request_for_a_collection_an_item_or_everything_opens_what_it_covers(): void
    {
        $holder = $this->record->repository;
        $fonds = app(RecordService::class)->create($this->institution, self::LEVEL.'fonds', 'Council records', 'en', null, $holder, 'CR');
        $letter = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Letter to the council', 'en', $fonds, $holder, 'CR1');
        $letterScan = $this->scanOf($letter, 'Scan of the letter');
        foreach ([$fonds, $letter] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->editor);
        }
        $form = ['subject' => 'Parish history', 'request_type' => 'research', 'description' => 'For a history of the parish council.', 'urgency' => 'normal'];

        // A collection is chosen from the collection-level records; one is required when the scope is a collection.
        $this->actingAs($this->researcher)->get('/access-requests/new')->assertOk()->assertSee('Council records (Fonds)')->assertDontSee('Minute book, 1902 (')
            ->assertSee('To ask for a single item, open its record and choose Ask for access.');
        $this->actingAs($this->researcher)->post('/access-requests', $form + ['scope' => 'collection'])->assertSessionHasErrors(['collection' => 'Choose which collection you need access to.']);
        $this->actingAs($this->researcher)->post('/access-requests', $form + ['scope' => 'collection', 'collection' => $this->record->id])->assertSessionHasErrors('scope');
        $this->actingAs($this->researcher)->post('/access-requests', $form + ['scope' => 'collection', 'collection' => $fonds->id])->assertRedirect()->assertSessionHasNoErrors();
        $request = AccessRequest::where('user_id', $this->researcher->id)->sole();
        $this->assertSame(['collection', $fonds->id, 'research', 'Parish history', null], [$request->covers->notation, $request->record_resource_id, $request->requestType->notation, $request->subject, $request->instantiation_id]);
        $this->assertContains(['editor@example.org', 'A request for access waits for review'], $this->mails());
        $this->actingAs($this->researcher)->post('/access-requests', $form + ['scope' => 'collection', 'collection' => $fonds->id])->assertSessionHasErrors(['scope' => 'You have asked for this already.']);
        $this->actingAs($this->editor)->get("/access-requests/{$request->id}")->assertOk()->assertSee('Parish history')->assertSee('Research access')->assertSee('Council records');

        // Approved, it opens the files of what is inside the collection, not those outside it.
        $this->actingAs($this->researcher)->get("/files/{$letterScan->file('master')->id}")->assertNotFound();
        $this->actingAs($this->editor)->post("/access-requests/{$request->id}/approve", ['days' => 7])->assertSessionHasNoErrors();
        $this->actingAs($this->researcher)->get("/files/{$letterScan->file('master')->id}")->assertOk();
        $this->actingAs($this->researcher)->get("/instantiations/{$letterScan->id}")->assertSee('An approved access request opens these files.');
        $this->actingAs($this->researcher)->get("/files/{$this->scan->file('master')->id}")->assertNotFound();

        // A single item is asked for from its record's page.
        $this->actingAs($this->researcher)->get("/records/{$this->record->id}")->assertOk()->assertSee(route('access-requests.new', ['record' => $this->record->id, 'scope' => 'item']));
        $this->actingAs($this->researcher)->get("/access-requests/new?record={$this->record->id}&scope=item")->assertOk()->assertSee('A single item: Minute book, 1902');

        // Everything opens every file on request that the requester may see.
        $this->actingAs($this->other)->post('/access-requests', $form + ['scope' => 'all', 'request_type' => 'view', 'collection' => $fonds->id])->assertRedirect()->assertSessionHasNoErrors();
        $all = AccessRequest::where('user_id', $this->other->id)->sole();
        $this->assertSame(['all', null], [$all->covers->notation, $all->record_resource_id]);
        $this->actingAs($this->other)->get('/access-requests/mine')->assertSee('Parish history')->assertSee('Everything available on request');
        $this->actingAs($this->editor)->post("/access-requests/{$all->id}/approve", ['days' => 7])->assertSessionHasNoErrors();
        $this->actingAs($this->other)->get("/files/{$this->scan->file('master')->id}")->assertOk();
        $this->actingAs($this->other)->get("/files/{$letterScan->file('master')->id}")->assertOk();

        // The request for one instantiation keeps how the files will be used, which Heratio asks but does not keep.
        $this->actingAs($this->contributor)->post("/instantiations/{$this->scan->id}/request-access", ['reason' => 'An exhibition.', 'urgency' => 'normal', 'access_type' => 'download'])->assertRedirect();
        $this->assertSame(['download', 'item', $this->record->id], [AccessRequest::where('user_id', $this->contributor->id)->sole()->accessType->notation,
            AccessRequest::where('user_id', $this->contributor->id)->sole()->covers->notation, AccessRequest::where('user_id', $this->contributor->id)->sole()->record_resource_id]);
    }

    public function test_an_online_file_cannot_be_asked_for_and_a_held_address_gets_no_mail(): void
    {
        app(InstantiationService::class)->updateFacts($this->scan, 'digital-file', 'online', $this->editor);
        $this->actingAs($this->researcher)->get("/instantiations/{$this->scan->fresh()->id}/request-access")->assertNotFound();
        app(InstantiationService::class)->updateFacts($this->scan, 'digital-file', 'on-request', $this->editor);

        $this->researcher->update(['email_bounced_at' => now()]);
        $request = $this->ask($this->researcher->fresh());
        $this->assertSame(['editor@example.org'], array_column($this->mails(), 0), 'the reviewer is still told');
        $this->assertSame(1, SentEmail::whereHas('status', fn ($q) => $q->where('notation', 'suppressed'))->where('recipient_email', 'researcher@example.org')->count());
        $this->actingAs($this->editor)->post("/access-requests/{$request->id}/approve", ['days' => 7]);
        $this->assertSame(AccessRequestApprovedMail::class, SentEmail::latest('id')->first()->mailable);
    }
}
