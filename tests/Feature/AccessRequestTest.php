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
        $image = new Imagick;
        $image->newImage(900, 600, new ImagickPixel('#d8c9a3'));
        $image->setImageFormat('png');
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, $image->getImageBlob());
        $this->scan = app(InstantiationService::class)->upload($this->record, $path, 'minutes.png', 'en', 'Scan of the minute book', null, 'on-request', $this->editor);
        unlink($path);
        foreach ([$holder, $this->record] as $entity) {
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
