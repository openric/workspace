<?php

// OpenRiC Workspace - repatriation claims (roadmap phase 17d2), after Heratio's ahg-semantic-search: a claim
// registered from its description, every change of its status kept and told to those who manage claims and to the
// claimant; the dialogue, shared or internal; a link to the shared record that opens it without an account, writes
// only as the claimant it was given to, and stops when revoked; the register and each claim's virtual return in the
// public catalogue only where the institution shows them and only for a published description, with knowledge shown
// once reviewed and its contributor named only with consent; and lodging from the public catalogue only where allowed.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Mail\RepatriationClaimantMail;
use App\Mail\RepatriationClaimMail;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\RepatriationClaim;
use App\Models\RepatriationKnowledge;
use App\Models\RepatriationSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RepatriationTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private Institution $institution;

    private User $admin;

    private User $archivist;

    private RecordResource $mask;

    private RecordResource $draft;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        Mail::fake();
        $this->institution = Institution::create(['name' => 'Riverside Museum', 'base_iri' => 'https://riverside.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->admin = app(AccountService::class)->create($this->institution, 'Admin', 'admin@riverside.example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->admin, 'administrator');
        $this->archivist = app(AccountService::class)->create($this->institution, 'Archivist', 'archivist@riverside.example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->archivist, 'editor');
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Museum', 'en', 'RM');
        $this->mask = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Carved mask', 'en', null, $holder, 'RM1');
        $this->draft = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Beaded apron', 'en', null, $holder, 'RM2');
        foreach ([$holder, $this->mask] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->admin);
        }
    }

    public function test_a_claim_is_registered_and_moved_on_with_its_history_told(): void
    {
        $this->actingAs($this->archivist)->get(route('repatriation.index'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('repatriation.create', ['record' => $this->mask->id]))->assertOk()->assertSee('Carved mask');
        $this->actingAs($this->admin)->post(route('repatriation.store'), ['record' => $this->mask->id, 'claimant_community' => 'Valley people', 'origin_place' => 'Upper valley',
            'status' => 'registered', 'contact_email' => 'elder@valley.example.org', 'evidence_summary' => 'Collected in 1898.'])->assertRedirect();
        $claim = RepatriationClaim::sole();
        $this->assertSame(['Valley people', 'Riverside Museum', 'registered'], [$claim->claimant_community, $claim->current_holder, $claim->status->notation], 'the holder is the description\'s repository');
        Mail::assertNotQueued(RepatriationClaimMail::class, 'the one who registered it is not told');
        Mail::assertQueued(RepatriationClaimantMail::class, fn ($m) => $m->hasTo('elder@valley.example.org') && $m->toStatus === null);

        $this->actingAs($this->admin)->post(route('repatriation.status', $claim), ['status' => 'acknowledged', 'note' => 'Letter sent to the elders.'])->assertRedirect();
        $this->assertSame('acknowledged', $claim->fresh()->status->notation);
        $this->assertSame(['registered', 'acknowledged'], $claim->statusChanges()->with('to')->get()->reverse()->map(fn ($c) => $c->to->notation)->values()->all());
        Mail::assertQueued(RepatriationClaimantMail::class, fn ($m) => $m->toStatus === 'Acknowledged' && $m->fromStatus === 'Registered');
        $this->actingAs($this->admin)->get(route('repatriation.dialogue', $claim))->assertOk()->assertSee('Letter sent to the elders.')->assertSee('Collected in 1898.');
        $this->actingAs($this->admin)->get(route('records.show', $this->mask))->assertSee('Register a repatriation claim')->assertSee('Valley people');

        [, $otherAdmin] = $this->otherInstitution();
        $this->actingAs($otherAdmin)->get(route('repatriation.dialogue', $claim))->assertNotFound();
        $this->actingAs($this->admin)->post(route('repatriation.store'), ['record' => $this->mask->id, 'status' => 'no-such-status'])->assertSessionHasErrors('claim');
    }

    public function test_the_shared_record_opens_by_its_link_until_revoked(): void
    {
        $claim = $this->claim();
        $this->actingAs($this->admin)->post(route('repatriation.message', $claim), ['body' => 'We have the accession register.', 'role' => 'institution', 'visibility' => 'shared']);
        $this->actingAs($this->admin)->post(route('repatriation.message', $claim), ['body' => 'Check with the director first.', 'role' => 'institution', 'visibility' => 'internal']);
        $this->actingAs($this->admin)->post(route('repatriation.message', $claim), ['body' => 'x', 'role' => 'claimant', 'visibility' => 'shared'])->assertSessionHasErrors('claim');
        $url = $this->actingAs($this->admin)->post(route('repatriation.grant', $claim), ['grantee_name' => 'Elder Mokoena', 'grantee_role' => 'claimant', 'can_message' => '1'])
            ->assertSessionHas('new_share_url')->getSession()->get('new_share_url');
        $token = basename($url);
        $this->assertDatabaseHas('repatriation_shares', ['token_hash' => hash('sha256', $token)]);
        $this->assertDatabaseMissing('repatriation_shares', ['token_hash' => $token]);

        auth()->logout();
        $this->get($url)->assertOk()->assertSee('We have the accession register.')->assertDontSee('Check with the director first.')->assertDontSee('elder@valley.example.org');
        $this->post(route('repatriation.shared.message', $token), ['body' => 'Our grandmothers remember it.'])->assertRedirect($url);
        $said = $claim->messages()->with('role')->get()->last();
        $this->assertSame(['Our grandmothers remember it.', 'claimant', 'Elder Mokoena'], [$said->body, $said->role->notation, $said->author_name]);

        $observer = basename($this->actingAs($this->admin)->post(route('repatriation.grant', $claim), ['grantee_role' => 'observer', 'can_message' => '1'])->getSession()->get('new_share_url'));
        auth()->logout();
        $this->get(route('repatriation.shared', $observer))->assertOk()->assertSee('This link is read-only.');
        $this->post(route('repatriation.shared.message', $observer), ['body' => 'Hello'])->assertSessionHasErrors('claim');

        $this->actingAs($this->admin)->post(route('repatriation.revoke', [$claim, $claim->shares()->where('grantee_name', 'Elder Mokoena')->sole()]))->assertRedirect();
        auth()->logout();
        $this->get($url)->assertOk()->assertSee('This shared-record link is not active');
        $this->get(route('repatriation.shared', str_repeat('a', 48)))->assertSee('This shared-record link is not active');
    }

    public function test_the_public_register_and_its_virtual_returns_show_only_what_is_published(): void
    {
        $claim = $this->claim();
        $hidden = $this->claim($this->draft);
        $this->get(route('discover.repatriation'))->assertNotFound();
        $this->get(route('discover.repatriation.show', $claim->id))->assertNotFound();
        RepatriationSetting::create(['institution_id' => $this->institution->id, 'public_register' => true]);

        $this->get(route('discover.repatriation'))->assertOk()->assertSee('Documented claims')->assertSee('Upper valley')->assertSee('Carved mask')->assertDontSee('Beaded apron');
        $this->getJson(route('discover.repatriation.json'))->assertOk()->assertJsonPath('total', 2)->assertJsonCount(1, 'recent');
        $this->get(route('discover.repatriation.show', $hidden->id))->assertNotFound();
        $this->get(route('discover.repatriation.show', $claim->id))->assertOk()->assertSee('In its origin context')->assertDontSee('elder@valley.example.org');
        $this->get(route('discover.record', $this->mask->id))->assertSee(route('discover.repatriation.show', $claim->id), false);

        $this->post(route('discover.repatriation.contribute', $claim->id), ['type' => 'oral-history', 'body' => 'The mask was danced at harvest.', 'contributor_name' => 'Thandi'])
            ->assertRedirect();
        $this->post(route('discover.repatriation.contribute', $claim->id), ['type' => 'provenance', 'body' => 'Sold in 1902.', 'contributor_name' => 'Pieter', 'credit_consent' => '1']);
        $this->get(route('discover.repatriation.show', $claim->id))->assertDontSee('The mask was danced at harvest.');
        $this->assertNull(RepatriationKnowledge::where('body', 'The mask was danced at harvest.')->value('contributor_name'), 'no name kept without consent');

        foreach (RepatriationKnowledge::all() as $k) {
            $this->actingAs($this->admin)->post(route('repatriation.moderate', $k), ['status' => 'approved'])->assertRedirect();
        }
        auth()->logout();
        $this->get(route('discover.repatriation.show', $claim->id))->assertSee('The mask was danced at harvest.')->assertSee('Anonymous contributor')->assertSee('Pieter');
    }

    public function test_the_public_lodges_a_claim_only_where_allowed(): void
    {
        $form = ['claimant_community' => 'Valley people', 'claimant_name' => 'Elder Mokoena', 'contact_email' => 'elder@valley.example.org', 'consent' => '1', 'rendered_at' => time() - 60];
        $this->get(route('discover.repatriation.lodge', $this->mask->id))->assertNotFound();
        RepatriationSetting::create(['institution_id' => $this->institution->id, 'public_lodging' => true]);
        $this->get(route('discover.record', $this->mask->id))->assertSee('Lodge a repatriation claim');
        $this->get(route('discover.repatriation.lodge', $this->draft->id))->assertNotFound();

        $this->post(route('discover.repatriation.submit', $this->mask->id), [...$form, 'website' => 'http://spam.example'])->assertRedirect(route('discover.repatriation.thanks'));
        $this->post(route('discover.repatriation.submit', $this->mask->id), [...$form, 'rendered_at' => time()])->assertRedirect(route('discover.repatriation.thanks'));
        $this->assertSame(0, RepatriationClaim::count(), 'a robot\'s claim is not kept');
        $this->post(route('discover.repatriation.submit', $this->mask->id), [...$form, 'consent' => null])->assertSessionHasErrors('consent');

        $this->post(route('discover.repatriation.submit', $this->mask->id), $form)->assertRedirect(route('discover.repatriation.thanks'));
        $claim = RepatriationClaim::sole();
        $this->assertSame([true, 'registered', 'Elder Mokoena <elder@valley.example.org>', null], [$claim->lodged_publicly, $claim->status->notation, $claim->contact, $claim->created_by_id]);
        Mail::assertQueued(RepatriationClaimMail::class, fn ($m) => $m->recipient->is($this->admin));
        Mail::assertQueued(RepatriationClaimantMail::class);
        $this->assertSame('Valley people', $claim->statusChanges()->sole()->changed_by_name);
    }

    private function claim(?RecordResource $record = null): RepatriationClaim
    {
        $record ??= $this->mask;
        $this->actingAs($this->admin)->post(route('repatriation.store'), ['record' => $record->id, 'claimant_community' => 'Valley people', 'origin_place' => 'Upper valley',
            'status' => 'registered', 'contact_email' => 'elder@valley.example.org'])->assertRedirect();
        auth()->logout();

        return RepatriationClaim::where('record_resource_id', $record->id)->sole();
    }

    /** @return array{Institution, User} */
    private function otherInstitution(): array
    {
        $institution = Institution::create(['name' => 'Hillside Archives', 'base_iri' => 'https://hillside.example.org']);
        app(RoleService::class)->seedDefaults($institution);
        $admin = app(AccountService::class)->create($institution, 'Admin', 'admin@hillside.example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($admin, 'administrator');

        return [$institution, $admin];
    }
}
