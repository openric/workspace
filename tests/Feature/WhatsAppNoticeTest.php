<?php

// OpenRiC Workspace - staff notices on WhatsApp (5 November 2026), after CallHub's WhatsAppNotifier: a staff account
// that gives its number and asks has each notice the Workspace mails it written into the estate's spool as the
// approved template (the institution, the subject, the link), whole and never half-written; nothing is written while
// the installation has WhatsApp off, for an account that did not ask, or for one without a staff role; and the
// sending number is never accepted as an account's own.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Mail\Outbox;
use App\Mail\RepatriationClaimMail;
use App\Mail\WhatsApp;
use App\Models\Institution;
use App\Models\RepatriationClaim;
use App\Models\User;
use App\Repatriation\Claims;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WhatsAppNoticeTest extends TestCase
{
    use RefreshDatabase;

    private string $spool;

    private User $admin;

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        Mail::fake();
        $this->spool = sys_get_temp_dir().'/wa-spool-'.bin2hex(random_bytes(4));
        mkdir($this->spool);
        config(['workspace.whatsapp.enabled' => true, 'workspace.whatsapp.spool' => $this->spool]);
        $institution = Institution::create(['name' => 'Riverside Museum', 'base_iri' => 'https://riverside.example.org']);
        app(RoleService::class)->seedDefaults($institution);
        $this->admin = app(AccountService::class)->create($institution, 'Admin', 'admin@riverside.example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->admin, 'administrator');
        $this->reader = app(AccountService::class)->create($institution, 'Reader', 'reader@riverside.example.org', 'correct horse battery staple');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->spool.'/{,.}*.json', GLOB_BRACE) ?: []);
        @rmdir($this->spool);
        parent::tearDown();
    }

    public function test_a_staff_notice_is_spooled_as_the_approved_template(): void
    {
        $this->actingAs($this->admin)->get(route('account.profile'))->assertSee('Your WhatsApp number');
        $this->actingAs($this->admin)->post(route('account.whatsapp'), ['whatsapp_number' => '+27 64 883 0533', 'whatsapp_notices' => '1'])->assertSessionHasErrors('whatsapp_number');
        $this->actingAs($this->admin)->post(route('account.whatsapp'), ['whatsapp_number' => '12', 'whatsapp_notices' => '1'])->assertSessionHasErrors('whatsapp_number');
        $this->actingAs($this->admin)->post(route('account.whatsapp'), ['whatsapp_number' => '082 123 4567', 'whatsapp_notices' => '1'])->assertRedirect();
        $this->actingAs($this->reader)->post(route('account.whatsapp'), ['whatsapp_number' => '082 765 4321', 'whatsapp_notices' => '1'])->assertNotFound();

        $claim = $this->claim();
        $this->assertTrue(app(Outbox::class)->send($this->admin->fresh(), new RepatriationClaimMail($this->admin->fresh(), $claim)));
        $files = glob($this->spool.'/*.json');
        $this->assertCount(1, $files);
        $this->assertSame([], glob($this->spool.'/.*.json'), 'nothing left half-written');
        $payload = json_decode(file_get_contents($files[0]), true);
        $this->assertSame(['27821234567', 'ahg_system_notice', 'en', 'transactional'], [$payload['to'], $payload['template'], $payload['language'], $payload['purpose']]);
        $this->assertSame(['Riverside Museum', 'New repatriation claim registered', route('repatriation.dialogue', $claim)], array_column($payload['components'][0]['parameters'], 'text'));
    }

    public function test_nothing_is_spooled_unless_on_asked_for_and_staff(): void
    {
        $claim = $this->claim();
        $this->admin->update(['whatsapp_number' => '0821234567', 'whatsapp_notices' => false]);
        app(Outbox::class)->send($this->admin, new RepatriationClaimMail($this->admin, $claim));
        $this->reader->update(['whatsapp_number' => '0827654321', 'whatsapp_notices' => true]);
        $this->assertFalse(app(WhatsApp::class)->notify($this->reader, new RepatriationClaimMail($this->reader, $claim)), 'not staff');
        $this->admin->update(['whatsapp_notices' => true]);
        config(['workspace.whatsapp.enabled' => false]);
        app(Outbox::class)->send($this->admin, new RepatriationClaimMail($this->admin, $claim));
        $this->actingAs($this->admin)->get(route('account.profile'))->assertDontSee('Your WhatsApp number');
        $this->assertSame([], glob($this->spool.'/*.json'));
        Mail::assertQueued(RepatriationClaimMail::class, 2);
    }

    private function claim(): RepatriationClaim
    {
        $holder = app(RepositoryService::class)->create($this->admin->institution, 'Riverside Museum', 'en', 'RM');
        $record = app(RecordService::class)->create($this->admin->institution, 'https://workspace.openric.org/vocab/level-of-description/item', 'Carved mask', 'en', null, $holder, 'RM1');
        config(['workspace.whatsapp.enabled' => false]);
        $claim = app(Claims::class)->register($record, ['claimant_community' => 'Valley people'], $this->admin);
        config(['workspace.whatsapp.enabled' => true]);

        return $claim;
    }
}
