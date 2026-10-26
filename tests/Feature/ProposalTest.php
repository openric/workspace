<?php

// OpenRiC Workspace - proposals through the API (ADR 0007): what is queued, skipped and refused, relationships as
// proposed assertions, and who may list whose proposals.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\AccessControl;
use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Accounts\ApiKeyService;
use App\Description\AgentService;
use App\Description\InstantiationService;
use App\Description\PlaceService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Agent;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\Permission;
use App\Models\Place;
use App\Models\Proposal;
use App\Models\RecordResource;
use App\Models\Relation;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProposalTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://archives.example.org/api/ric/v1';

    private Institution $institution;

    private User $admin;

    private User $editor;

    private User $contributor;

    private RecordResource $record;

    private Agent $jan;

    private Agent $maria;

    private Agent $council;

    private Place $town;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $accounts = app(AccountService::class);
        foreach (['admin' => 'administrator', 'editor' => 'editor', 'contributor' => 'contributor'] as $property => $role) {
            $this->$property = $accounts->create($this->institution, ucfirst($property), "$property@example.org", 'correct horse battery staple');
            $accounts->grantTemplateRole($this->$property, $role);
        }
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $this->record = app(RecordService::class)->create($this->institution, 'https://workspace.openric.org/vocab/level-of-description/fonds', 'Parish records', 'en', null, $holder, 'P');
        app(PublicationService::class)->apply($holder, 'publish', null, false, $this->admin);
        app(PublicationService::class)->apply($this->record, 'publish', null, false, $this->admin);
        $agents = app(AgentService::class);
        $this->jan = $agents->create($this->institution, 'person', 'Botha, Jan', 'en');
        $this->maria = $agents->create($this->institution, 'person', 'Botha, Maria', 'en');
        $this->council = $agents->create($this->institution, 'corporate-body', 'Riverside Parish Council', 'en');
        $this->town = app(PlaceService::class)->create($this->institution, 'Riverside', 'en');
    }

    private function key(User $owner, array $scopes = ['read', 'write']): string
    {
        return app(ApiKeyService::class)->issue($owner, 'MCP', $scopes, null, null, $owner)[1];
    }

    private function propose(string $secret, array $payload, ?string $record = null)
    {
        return $this->postJson(self::API.'/records/'.($record ?? $this->record->id).'/proposals', $payload, ['X-API-Key' => $secret]);
    }

    public function test_names_places_dates_values_and_transcripts_are_queued_once(): void
    {
        $instantiation = app(InstantiationService::class)->create($this->record, 'Scan of the register', 'en');
        $payload = [
            'source' => 'glam-mcp',
            'entities' => [
                ['type' => 'person', 'value' => '  Jan   Botha ', 'confidence' => 0.9, 'evidence' => 'Signed the minutes', 'sources' => ['https://example.org/a', 'not a url'],
                    'authority' => ['dates_of_existence' => '1850-1910'], 'agent_id' => $this->jan->id, 'link_reason' => 'Same clerk'],
                ['type' => 'GPE', 'value' => 'Riverside', 'place_id' => $this->town->id],
                ['type' => 'DATE', 'value' => '3 May 1901'],
                ['type' => 'PERSON', 'value' => 'jan botha'],
            ],
            'fields' => [
                ['field' => 'scope_and_content', 'text' => 'Minutes of the parish council.'],
                ['field' => 'archival-history', 'text' => 'Notule van die raad.', 'culture' => 'af'],
            ],
            'transcripts' => [['text' => 'Page one of the register.']],
        ];
        $response = $this->propose($this->key($this->contributor), $payload)->assertCreated();
        // The second Jan Botha is the same name: one proposal.
        $this->assertSame([['type' => 'PERSON', 'value' => 'Jan Botha', 'linked_to' => $this->jan->id], ['type' => 'GPE', 'value' => 'Riverside', 'linked_to' => $this->town->id],
            ['type' => 'DATE', 'value' => '3 May 1901']], $response->json('queued'));
        $this->assertSame([['field' => 'scope-and-content'], ['field' => 'archival-history', 'language' => 'af'], ['field' => 'transcript', 'instantiation_id' => $instantiation->id]],
            $response->json('fields_queued'));

        $jan = Proposal::where('value', 'Jan Botha')->firstOrFail();
        $this->assertSame(['agent', 'person', 'proposed'], [$jan->kind->notation, $jan->agentType->notation, $jan->status->notation]);
        $this->assertSame(['https://example.org/a'], $jan->sources);
        $this->assertSame(['dates_of_existence' => '1850-1910'], $jan->authority);
        $this->assertSame(['agents', $this->jan->id, 'Same clerk', 'glam-mcp', $this->contributor->id], [$jan->linked_table, $jan->linked_id, $jan->link_reason, $jan->origin_label, $jan->proposed_by_id]);
        $this->assertNotNull($jan->api_key_id);
        $this->assertSame('af', Proposal::where('value', 'Notule van die raad.')->value('language'));
        $this->assertSame($instantiation->id, Proposal::whereHas('kind', fn ($q) => $q->where('notation', 'transcript'))->value('instantiation_id'));

        // Nothing on the record changed, and sending it again queues nothing.
        $this->assertSame('Parish records', $this->record->fresh()->names()->value('value'));
        $again = $this->propose($this->key($this->contributor), $payload)->assertOk();
        $this->assertSame([3, 3], [$again->json('skipped_existing'), $again->json('fields_skipped_existing')]);
        $this->assertSame(6, Proposal::count());
    }

    public function test_a_malformed_part_refuses_the_whole_request(): void
    {
        $secret = $this->key($this->contributor);
        foreach ([
            [[], 'is required'],
            [['entities' => [['type' => 'THING', 'value' => 'x']]], 'Entity 0: type must be one of'],
            [['entities' => [['type' => 'DATE', 'value' => '1901', 'agent_id' => $this->jan->id]]], 'a date cannot link'],
            [['entities' => [['type' => 'PERSON', 'value' => 'x', 'agent_id' => $this->town->id]]], 'agent_id is not an authority record'],
            [['fields' => [['field' => 'not_a_field', 'text' => 'x']]], 'Field 0: field must be'],
            [['fields' => [['field' => 'title', 'text' => 'x', 'language' => 'English']]], 'BCP 47'],
            [['transcripts' => [['text' => 'x']]], 'no instantiation to transcribe'],
            [['relationships' => [['relation' => 'is the cousin of', 'subject_agent_id' => $this->jan->id, 'object_agent_id' => $this->maria->id]]], 'relation must be a relation type between agents'],
            [['relationships' => [['relation' => 'spouse', 'subject_agent_id' => $this->jan->id, 'object_agent_id' => $this->maria->id, 'start_date' => '1901-02-30']]], 'start_date must be a date'],
            [['entities' => [['type' => 'DATE', 'value' => '1901']], 'fields' => [['field' => 'nope', 'text' => 'x']]], 'Field 0'],
        ] as [$payload, $detail]) {
            $response = $this->propose($secret, $payload)->assertStatus(400)->assertHeader('Content-Type', 'application/problem+json');
            $this->assertStringContainsString($detail, $response->json('detail'));
        }
        $this->assertSame(0, Proposal::count());
    }

    public function test_a_relationship_is_a_relation_with_a_proposed_assertion_and_the_record_as_evidence(): void
    {
        $secret = $this->key($this->contributor);
        $response = $this->propose($secret, ['source' => 'glam-mcp', 'relationships' => [
            // Read from Maria: she is the child of Jan, so Jan is the parent of Maria.
            ['relation' => 'is the child of', 'subject_agent_id' => $this->maria->id, 'object_agent_id' => $this->jan->id, 'start_date' => '1880-01-01',
                'evidence' => 'Named as his daughter', 'date' => 'about 1880', 'sources' => ['https://example.org/b']],
            ['relation' => 'spouse', 'subject_name' => 'Jan Botha', 'object_name' => 'Anna Botha'],
            ['relation' => 'spouse', 'subject_agent_id' => $this->jan->id, 'object_agent_id' => $this->council->id],
        ]])->assertCreated();

        [$child, $unknown, $council] = $response->json('relationships');
        $this->assertSame(['Botha, Jan', 'child', 'Botha, Maria', true], [$child['subject'], $child['relation'], $child['object'], $child['queued']]);
        $this->assertFalse($unknown['queued']);
        $this->assertStringContainsString('"Jan Botha" is not an authority record of this archive', $unknown['error']);
        $this->assertFalse($council['queued']);
        $this->assertSame(1, Relation::count());

        $relation = Relation::with('assertions', 'evidence')->firstOrFail();
        $this->assertSame([$this->jan->id, $this->maria->id, 'proposed'], [$relation->source_id, $relation->target_id, $relation->standing()]);
        $assertion = $relation->assertions->first();
        $this->assertSame(['model', $this->contributor->id, 'glam-mcp', 'und'], [$assertion->origin->notation, $assertion->user_id, $assertion->origin_label, $assertion->basis_language]);
        $this->assertSame("Named as his daughter\n\nDate: about 1880\n\nSources: https://example.org/b", $assertion->basis);
        $this->assertSame('Contributor, through glam-mcp', $assertion->asserter());
        $this->assertSame([$this->record->id], $relation->evidence->pluck('record_resource_id')->all());
        $this->assertSame('1880-01-01', $relation->datedAs('beginning')?->normalized_value);

        // Proposed again, either way round, it is skipped.
        $again = $this->propose($secret, ['relationships' => [['relation' => 'is the parent of', 'subject_agent_id' => $this->jan->id, 'object_agent_id' => $this->maria->id]]])->assertOk();
        $this->assertSame(1, $again->json('relationships_skipped_existing'));
    }

    public function test_proposing_needs_a_write_key_of_an_account_that_may_change_the_record(): void
    {
        $this->postJson(self::API.'/records/'.$this->record->id.'/proposals', ['entities' => [['type' => 'DATE', 'value' => '1901']]])->assertStatus(401);
        $this->propose($this->key($this->contributor, ['read']), ['entities' => [['type' => 'DATE', 'value' => '1901']]])->assertStatus(403);
        $this->propose($this->key($this->contributor), ['entities' => [['type' => 'DATE', 'value' => '1901']]], '00000000-0000-0000-0000-000000000000')->assertNotFound();
        $this->propose($this->key($this->contributor), [], 'not-a-key')->assertNotFound();

        // The contributor's role is denied updating this one record.
        Permission::create(['institution_id' => $this->institution->id, 'role_id' => app(RoleService::class)->byTemplate($this->institution, 'contributor')->id,
            'kind_id' => Concept::where('iri', Vocab::kind('description'))->value('id'), 'action_id' => Concept::where('iri', Vocab::permission('update'))->value('id'),
            'subject_table' => 'record_resources', 'subject_id' => $this->record->id, 'is_grant' => false]);
        app(AccessControl::class)->flush();
        $this->propose($this->key($this->contributor), ['entities' => [['type' => 'DATE', 'value' => '1901']]])->assertStatus(403);
        $this->assertSame(0, Proposal::count());
    }

    public function test_the_caller_lists_their_proposals_and_reviewers_everyones(): void
    {
        $this->propose($this->key($this->contributor), ['entities' => [['type' => 'DATE', 'value' => '1901']],
            'relationships' => [['relation' => 'sibling', 'subject_agent_id' => $this->jan->id, 'object_agent_id' => $this->maria->id]]])->assertCreated();
        $this->propose($this->key($this->editor), ['fields' => [['field' => 'scope_and_content', 'text' => 'Minutes.']]])->assertCreated();

        $mine = $this->getJson(self::API.'/proposals', ['X-API-Key' => $this->key($this->contributor, ['read'])])->assertOk();
        $this->assertSame(2, $mine->json('total'));
        $this->assertEqualsCanonicalizing(['entity', 'relationship'], array_column($mine->json('results'), 'kind'));
        $this->assertSame(['Contributor'], array_values(array_unique(array_column($mine->json('results'), 'proposed_by'))));
        $relationship = collect($mine->json('results'))->firstWhere('kind', 'relationship');
        $this->assertSame(['Botha, Jan is a sibling of Botha, Maria', 'proposed', $this->record->id, 'Parish records'],
            [$relationship['value'], $relationship['status'], $relationship['record'], $relationship['title']]);

        $this->getJson(self::API.'/proposals?mine=0', ['X-API-Key' => $this->key($this->contributor)])->assertStatus(403);
        $everyone = $this->getJson(self::API.'/proposals?mine=0&count=2', ['X-API-Key' => $this->key($this->editor)])->assertOk();
        $this->assertSame([3, 2, 0], [$everyone->json('total'), count($everyone->json('results')), $everyone->json('start')]);
        $this->assertSame(3, $this->getJson(self::API.'/proposals?mine=0&status=pending', ['X-API-Key' => $this->key($this->admin)])->json('total'));
        $this->assertSame(0, $this->getJson(self::API.'/proposals?mine=0&status=accepted', ['X-API-Key' => $this->key($this->admin)])->json('total'));
        $this->getJson(self::API.'/proposals')->assertStatus(401);
    }

    public function test_review_proposals_is_given_to_existing_roles_without_giving_back_what_was_taken_away(): void
    {
        $roles = app(RoleService::class);
        $editor = $roles->byTemplate($this->institution, 'editor');
        $grant = fn (string $action) => Permission::where(['role_id' => $editor->id, 'kind_id' => Concept::where('iri', Vocab::kind('description'))->value('id'),
            'action_id' => Concept::where('iri', Vocab::permission($action))->value('id')]);
        $this->assertTrue($grant('review-proposals')->exists());
        $grant('review-proposals')->delete();
        $grant('delete')->delete();

        $roles->grantKind($this->institution, 'description', ['review-proposals']);
        $this->assertTrue($grant('review-proposals')->exists());
        $this->assertFalse($grant('delete')->exists());
        $this->assertFalse($roles->byTemplate($this->institution, 'contributor')->permissions()->whereHas('action', fn ($q) => $q->where('notation', 'review-proposals'))->exists());
    }
}
