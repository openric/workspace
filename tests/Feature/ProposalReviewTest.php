<?php

// OpenRiC Workspace - the review queue (ADR 0007, step 3): accepting writes through the record's services as a
// draft, as edited; rejecting keeps the proposal with its note; only reviewers who may change the record review.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Accounts\ApiKeyService;
use App\Description\AgentService;
use App\Description\InstantiationService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Agent;
use App\Models\Institution;
use App\Models\Place;
use App\Models\Proposal;
use App\Models\RecordResource;
use App\Models\Relation;
use App\Models\User;
use App\Proposals\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ProposalReviewTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://archives.example.org/api/ric/v1';

    private Institution $institution;

    private User $editor;

    private User $contributor;

    private RecordResource $record;

    private Agent $jan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $accounts = app(AccountService::class);
        foreach (['editor' => 'editor', 'contributor' => 'contributor'] as $property => $role) {
            $this->$property = $accounts->create($this->institution, ucfirst($property), "$property@example.org", 'correct horse battery staple');
            $accounts->grantTemplateRole($this->$property, $role);
        }
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $this->record = app(RecordService::class)->create($this->institution, 'https://workspace.openric.org/vocab/level-of-description/fonds', 'Parish records', 'en', null, $holder, 'P');
        app(PublicationService::class)->apply($holder, 'publish', null, false, $this->editor);
        app(PublicationService::class)->apply($this->record, 'publish', null, false, $this->editor);
        $this->jan = app(AgentService::class)->create($this->institution, 'person', 'Botha, Jan', 'en');
    }

    private function propose(array $payload): void
    {
        $secret = app(ApiKeyService::class)->issue($this->contributor, 'MCP', ['read', 'write'], null, null, $this->contributor)[1];
        $this->postJson(self::API."/records/{$this->record->id}/proposals", ['source' => 'glam-mcp', ...$payload], ['X-API-Key' => $secret])->assertCreated();
    }

    private function proposal(string $value): Proposal
    {
        return Proposal::where('value', $value)->firstOrFail();
    }

    public function test_accepting_a_value_writes_it_as_a_draft_as_the_reviewer_edited_it(): void
    {
        $this->propose(['fields' => [['field' => 'scope_and_content', 'text' => 'Minutes of the counsil.'], ['field' => 'scope_and_content', 'text' => 'Notule van die raad.', 'language' => 'af']]]);
        $reviews = app(ReviewService::class);
        $reviews->accept($this->proposal('Minutes of the counsil.'), $this->editor, ['value' => 'Minutes of the council.', 'note' => 'Spelling']);
        $reviews->accept($this->proposal('Notule van die raad.'), $this->editor);

        $record = $this->record->fresh();
        $this->assertSame('Minutes of the council.', $record->described('scope-and-content', ['en']));
        $this->assertSame('Notule van die raad.', $record->described('scope-and-content', ['af']));
        $this->assertSame('draft', $record->descriptionValues->first(fn ($v) => $v->element->notation === 'scope-and-content' && $v->language === 'en')->state->notation);
        $accepted = $this->proposal('Minutes of the counsil.');
        $this->assertSame(['accepted', $this->editor->id, 'Spelling'], [$accepted->status->notation, $accepted->reviewed_by_id, $accepted->review_note]);
        $this->assertDatabaseHas('audit_log', ['subject_id' => $accepted->id, 'actor_id' => $this->editor->id]);

        $this->expectException(InvalidArgumentException::class);
        $reviews->reject($accepted, $this->editor);
    }

    public function test_accepting_a_transcript_and_a_date(): void
    {
        $instantiation = app(InstantiationService::class)->create($this->record, 'Scan of the register', 'en');
        $this->propose(['transcripts' => [['text' => 'Page one of the register.']], 'entities' => [['type' => 'DATE', 'value' => '3 May 1901']]]);
        $reviews = app(ReviewService::class);
        $reviews->accept($this->proposal('Page one of the register.'), $this->editor);
        $reviews->accept($this->proposal('3 May 1901'), $this->editor, ['normalized' => '1901-05-03']);

        $this->assertSame('Page one of the register.', $instantiation->fresh()->described('instantiation-transcript', ['en']));
        $date = $this->record->fresh()->dates->firstOrFail();
        $this->assertSame(['associated', '3 May 1901', '1901-05-03'], [$date->property->notation, $date->expressed, $date->normalized_value]);
    }

    public function test_a_person_or_place_becomes_an_access_point_to_the_chosen_or_a_new_record(): void
    {
        $this->propose(['entities' => [
            ['type' => 'PERSON', 'value' => 'Jan Botha', 'agent_id' => $this->jan->id],
            ['type' => 'ORG', 'value' => 'Riverside Parish Council', 'authority' => ['history' => 'Met monthly from 1880.']],
            ['type' => 'GPE', 'value' => 'Riverside'],
        ]]);
        $reviews = app(ReviewService::class);
        $this->assertTrue($reviews->accept($this->proposal('Jan Botha'), $this->editor)->is($this->jan));
        $council = $reviews->accept($this->proposal('Riverside Parish Council'), $this->editor, ['agent_type' => 'corporate-body']);
        $town = $reviews->accept($this->proposal('Riverside'), $this->editor);

        $this->assertSame(['corporate-body', 'Met monthly from 1880.'], [$council->typeNotation(), $council->described('agent-history', ['en'])]);
        $this->assertInstanceOf(Place::class, $town);
        $this->assertSame([$this->jan->id, $council->id], Relation::whereHas('relationType', fn ($q) => $q->where('notation', 'subject'))
            ->where('source_id', $this->record->id)->orderBy('created_at')->pluck('target_id')->all());
        $place = Relation::whereHas('relationType', fn ($q) => $q->where('notation', 'place'))->firstOrFail();
        $this->assertSame([$town->id, $this->record->id, 'accepted'], [$place->source_id, $place->target_id, $place->standing()]);
        $this->assertSame($council->id, $this->proposal('Riverside Parish Council')->linked_id);
    }

    public function test_the_queue_is_for_reviewers_who_may_change_the_record(): void
    {
        $this->propose(['entities' => [['type' => 'DATE', 'value' => '1901'], ['type' => 'DATE', 'value' => '1902']],
            'relationships' => [['relation' => 'sibling', 'subject_agent_id' => $this->jan->id, 'object_agent_id' => app(AgentService::class)->create($this->institution, 'person', 'Botha, Maria', 'en')->id]]]);
        $this->actingAs($this->contributor)->get('/proposals')->assertForbidden();
        $this->actingAs($this->contributor)->post('/proposals/'.$this->proposal('1901')->id.'/reject')->assertForbidden();

        $this->actingAs($this->editor)->get('/proposals')->assertOk()->assertSee('Parish records')->assertSee('1902')
            ->assertSee('is a sibling of')->assertSee('Contributor, through glam-mcp');
        $this->actingAs($this->editor)->post('/proposals/'.$this->proposal('1901')->id.'/accept', ['value' => '1901', 'normalized' => '1901'])->assertRedirect();
        $this->actingAs($this->editor)->post('/proposals/'.$this->proposal('1902')->id.'/reject', ['note' => 'Not in the record'])->assertRedirect();
        $this->actingAs($this->editor)->post('/proposals/'.$this->proposal('1902')->id.'/accept', ['value' => '1902'])->assertSessionHasErrors();
        $assertion = Relation::firstOrFail()->assertions()->firstOrFail();
        $this->actingAs($this->editor)->post("/proposals/relations/{$assertion->id}", ['decision' => 'accept'])->assertRedirect();
        $this->assertSame('accepted', Relation::firstOrFail()->standing());

        $this->actingAs($this->editor)->get('/proposals')->assertOk()->assertDontSee('1902');
        $this->actingAs($this->editor)->get('/proposals?status=rejected')->assertOk()->assertSee('Not in the record');

        // The proposer sees what became of each.
        $secret = app(ApiKeyService::class)->issue($this->contributor, 'MCP', ['read'], null, null, $this->contributor)[1];
        $results = collect($this->getJson(self::API.'/proposals', ['X-API-Key' => $secret])->json('results'));
        $this->assertSame(['accepted', 'Editor'], [$results->firstWhere('value', '1901')['status'], $results->firstWhere('value', '1901')['reviewed_by']]);
        $this->assertSame(['rejected', 'Not in the record'], [$results->firstWhere('value', '1902')['status'], $results->firstWhere('value', '1902')['review_note']]);
        $this->assertSame('accepted', $results->firstWhere('kind', 'relationship')['status']);
    }
}
