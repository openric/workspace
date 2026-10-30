<?php

// OpenRiC Workspace - the Governance profile 0.45.0, section 4 (roadmap phase 11b, ADR 0013): deprecate, don't
// delete. A deleted or merged entity the public could read keeps answering at its IRI, in the API, its export and the
// public catalogue, as deprecated, with the reason and what replaces it; one the public never saw stays unknown.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\AgentService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Deprecation;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GovernanceTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private const API = '/api/ric/v1';

    private Institution $institution;

    private Repository $holder;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->administrator = app(AccountService::class)->create($this->institution, 'Administrator', 'admin@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->administrator, 'administrator');
        $this->holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        app(PublicationService::class)->apply($this->holder, 'publish', null, false, $this->administrator);
    }

    private function fonds(string $title, bool $published = true): RecordResource
    {
        $fonds = app(RecordService::class)->create($this->institution, self::LEVEL.'fonds', $title, 'en', null, $this->holder, null);
        if ($published) {
            app(PublicationService::class)->apply($fonds, 'publish', null, false, $this->administrator);
        }

        return $fonds;
    }

    public function test_a_published_record_deleted_answers_as_deprecated_with_its_reason_and_successor(): void
    {
        $old = $this->fonds('Council minutes, first catalogue');
        $new = $this->fonds('Council minutes');
        $this->actingAs($this->administrator)->post("/records/{$old->id}/delete", ['reason' => 'Described again as one fonds.', 'replaced_by' => $new->iri])
            ->assertRedirect()->assertSessionHasNoErrors();
        auth()->logout();

        $body = $this->get(self::API."/records/{$old->id}")->assertStatus(410)->assertHeader('Content-Type', 'application/ld+json')->json();
        $this->assertSame([$old->iri, 'rico:RecordSet', 'Council minutes, first catalogue', true, ['@id' => $new->iri], 'Described again as one fonds.'],
            [$body['@id'], $body['@type'], $body['rico:title'], $body['owl:deprecated'], $body['dcterms:isReplacedBy'], $body['rdfs:comment']]);
        $turtle = $this->get(self::API."/records/{$old->id}/export?format=ttl")->assertStatus(410)->getContent();
        $this->assertStringContainsString('owl:deprecated true', $turtle);
        $this->assertStringContainsString($new->iri, $turtle);
        // The IRI goes where it always went, which now says so.
        $this->get($old->iri, ['Accept' => 'application/ld+json'])->assertRedirect(route('api.record', $old->id));
        $this->get(route('discover.record', $old->id))->assertStatus(410)->assertSee('Described again as one fonds.')->assertSee($new->iri);
        $this->assertSame(1, $this->get(self::API.'/changelog')->json('deprecated_entities'));
    }

    public function test_a_merged_authority_record_points_at_the_one_it_was_merged_into(): void
    {
        $agents = app(AgentService::class);
        $duplicate = $agents->create($this->institution, 'corporate-body', 'Riverside Town Council', 'en');
        $kept = $agents->create($this->institution, 'corporate-body', 'Riverside Municipality', 'en');
        foreach ([$duplicate, $kept] as $agent) {
            app(PublicationService::class)->apply($agent, 'publish', null, false, $this->administrator);
        }
        $agents->merge($duplicate, $kept, $this->administrator);

        $body = $this->get(self::API."/agents/{$duplicate->id}")->assertStatus(410)->json();
        $this->assertSame(['Riverside Town Council', ['@id' => $kept->iri], 'merged into '.$kept->iri], [$body['rico:name'], $body['dcterms:isReplacedBy'], $body['rdfs:comment']]);
        $this->get(route('discover.agent', $duplicate->id))->assertStatus(410)->assertSee($kept->iri);
    }

    public function test_what_the_public_never_saw_stays_unknown_and_a_successor_must_be_of_the_same_kind(): void
    {
        $draft = $this->fonds('Unpublished correspondence', false);
        $this->actingAs($this->administrator)->post("/records/{$draft->id}/delete", ['replaced_by' => $this->holder->iri])
            ->assertRedirect(route('deletion.confirm', ['records', $draft->id]))->assertSessionHasErrors('replaced_by');
        $this->assertNull($draft->fresh()->deleted_at, 'not deleted when what replaces it is not a record');
        $this->actingAs($this->administrator)->post("/records/{$draft->id}/delete")->assertRedirect();
        auth()->logout();

        $this->assertFalse(Deprecation::where('subject_id', $draft->id)->sole()->was_public);
        $this->get(self::API."/records/{$draft->id}")->assertNotFound();
        $this->get(self::API."/records/{$draft->id}/export?format=ttl")->assertNotFound();
        $this->get(route('discover.record', $draft->id))->assertNotFound();
        $this->assertSame(0, $this->get(self::API.'/changelog')->json('deprecated_entities'));
    }
}
