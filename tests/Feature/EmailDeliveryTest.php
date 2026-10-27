<?php

// OpenRiC Workspace - email delivery (roadmap phase 7): the signed bounce webhook, holding an address after a hard bounce,
// a complaint or five soft bounces, the suppression gate before each send, the sent-mail log, and lifting a hold.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Mail\Outbox;
use App\Mail\WorkspaceMail;
use App\Models\EmailBounce;
use App\Models\Institution;
use App\Models\SentEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class EmailDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'a test secret for the bounce webhook';

    private Institution $institution;

    private User $admin;

    private User $researcher;

    protected function setUp(): void
    {
        parent::setUp();
        config(['workspace.email_bounce_secret' => self::SECRET]);
        $this->loadBundledVocabularies();
        $accounts = app(AccountService::class);
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->admin = $accounts->create($this->institution, 'Admin', 'admin@example.org', 'correct horse battery staple');
        $accounts->grantTemplateRole($this->admin, 'administrator');
        $this->researcher = $accounts->create($this->institution, 'Thandi Mokoena', 'Thandi@Example.org', 'correct horse battery staple');
    }

    private function bounce(array $payload, ?string $secret = self::SECRET): TestResponse
    {
        $body = json_encode($payload);
        $headers = ['CONTENT_TYPE' => 'application/json'] + ($secret ? ['HTTP_X_AHG_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, $secret)] : []);

        return $this->call('POST', '/webhooks/email/bounce', [], [], [], $headers, $body);
    }

    /** @return list<string> the addresses the array mailer delivered to */
    private function delivered(): array
    {
        return app('mailer')->getSymfonyTransport()->messages()->map(fn ($m) => $m->getEnvelope()->getRecipients()[0]->getAddress())->values()->all();
    }

    public function test_a_hard_bounce_holds_the_address_and_the_gate_keeps_mail_from_it_until_the_hold_is_lifted(): void
    {
        $this->assertTrue(app(Outbox::class)->send($this->researcher, new TestNotice($this->researcher)));
        $this->assertSame(['thandi@example.org'], $this->delivered());
        $sent = SentEmail::with('status')->sole();
        $this->assertSame(['sent', 'A test notice', TestNotice::class, 'thandi@example.org', $this->researcher->id, $this->institution->id],
            [$sent->status->notation, $sent->subject, $sent->mailable, $sent->recipient_email, $sent->user_id, $sent->institution_id]);
        $this->assertNotNull($sent->sent_at);
        $message = app('mailer')->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertFalse($message->getHeaders()->has('X-Workspace-Mailable'), 'the class name does not leave the server');

        $this->bounce(['email' => 'thandi@example.org', 'type' => 'hard', 'reason' => 'mailbox unknown'], null)->assertStatus(401);
        $this->bounce(['email' => 'thandi@example.org', 'type' => 'hard', 'reason' => 'mailbox unknown'], 'the wrong secret')->assertStatus(401);
        $this->bounce(['email' => 'thandi@example.org', 'type' => 'hard', 'reason' => 'mailbox unknown'])->assertOk()->assertJson(['ok' => true, 'processed' => 1]);
        $this->assertNotNull($this->researcher->fresh()->email_bounced_at);
        $bounce = EmailBounce::with('type')->sole();
        $this->assertSame(['hard', 'generic', 'mailbox unknown'], [$bounce->type->notation, $bounce->provider, $bounce->reason]);
        $this->assertNotNull($bounce->processed_at);

        $this->assertFalse(app(Outbox::class)->send($this->researcher->fresh(), new TestNotice($this->researcher)));
        $this->assertCount(1, $this->delivered(), 'nothing more is sent');
        $this->assertSame(['sent', 'suppressed'], SentEmail::with('status')->orderBy('id')->get()->map(fn ($m) => $m->status->notation)->all());

        $this->actingAs($this->admin)->get('/email')->assertOk()->assertSee('thandi@example.org')->assertSee('mailbox unknown')->assertSee('Hard bounce');
        $this->actingAs($this->admin)->post("/email/held/{$this->researcher->id}/clear")->assertSessionHas('status', 'Mail goes to thandi@example.org again.');
        $this->assertNull($this->researcher->fresh()->email_bounced_at);
        $this->assertTrue(app(Outbox::class)->send($this->researcher->fresh(), new TestNotice($this->researcher)));
        $this->actingAs($this->researcher)->get('/email')->assertForbidden();
    }

    public function test_five_soft_bounces_in_30_days_hold_the_address_and_a_complaint_holds_it_for_a_year(): void
    {
        foreach (range(1, 4) as $i) {
            $this->bounce(['email' => 'thandi@example.org', 'type' => 'soft', 'reason' => 'mailbox full'])->assertOk();
        }
        $this->assertNull($this->researcher->fresh()->email_bounced_at, 'four are not enough');
        $this->bounce(['email' => 'thandi@example.org', 'type' => 'soft', 'reason' => 'mailbox full'])->assertOk();
        $this->assertNotNull($this->researcher->fresh()->email_bounced_at, 'the fifth holds it');

        // Postmark's own shape, found from the payload.
        $this->bounce(['RecordType' => 'SpamComplaint', 'Type' => 'SpamComplaint', 'Email' => 'thandi@example.org', 'Description' => 'marked as spam', 'MessageID' => 'abc'])->assertOk();
        $this->assertSame(['complaint', 'postmark'], [EmailBounce::latest('id')->first()->type->notation, EmailBounce::latest('id')->first()->provider]);
        $this->actingAs($this->admin)->post("/email/held/{$this->researcher->id}/clear")
            ->assertSessionHas('status', 'The hold on thandi@example.org is lifted, but a complaint from it still holds it until it is 12 months old.');
        $this->assertFalse(app(Outbox::class)->send($this->researcher->fresh(), new TestNotice($this->researcher)));

        $this->travel(13)->months();
        $this->assertTrue(app(Outbox::class)->send($this->researcher->fresh(), new TestNotice($this->researcher)));
    }

    public function test_mail_is_written_in_the_language_the_account_chose_and_a_disabled_account_gets_none(): void
    {
        app('translator')->addLines(['*.A test notice' => "'n Toetskennisgewing"], 'af');
        $this->researcher->update(['interface_language' => 'af']);
        app(Outbox::class)->send($this->researcher->fresh(), new TestNotice($this->researcher));
        $this->assertSame(['af', "'n Toetskennisgewing"], [SentEmail::sole()->locale, SentEmail::sole()->subject]);

        $this->researcher->update(['disabled_at' => now()]);
        $this->assertFalse(app(Outbox::class)->send($this->researcher->fresh(), new TestNotice($this->researcher)));
        $this->assertSame(1, SentEmail::count());
    }
}

class TestNotice extends WorkspaceMail
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: __('A test notice'));
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>'.e(__('A test notice')).'</p>');
    }
}
