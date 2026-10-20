<?php

// OpenRiC Workspace - authority records: persons, families, corporate bodies, positions (ISAAR(CPF); ADR 0006).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\AccessControl;
use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\AgentService;
use App\Description\DateService;
use App\Description\NameService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Agent;
use App\Models\Concept;
use App\Models\Identifier;
use App\Models\Institution;
use App\Models\Name;
use App\Models\Permission;
use App\Models\RecordResource;
use App\Models\Relation;
use App\Models\Repository;
use App\Models\User;
use App\Relations\RelationService;
use App\Support\Vocab;
use App\Vocabulary\ConceptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class AgentTest extends TestCase
{
    use RefreshDatabase;

    private const FONDS = 'https://workspace.openric.org/vocab/level-of-description/fonds';

    private Institution $institution;

    private Repository $holder;

    private RecordResource $fonds;

    private User $administrator;

    private User $contributor;

    private User $translator;

    private AgentService $agents;

    private RelationService $relations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        $accounts = app(AccountService::class);
        $this->agents = app(AgentService::class);
        $this->relations = app(RelationService::class);
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        foreach (['administrator' => 'admin', 'contributor' => 'contributor', 'translator' => 'translator'] as $role => $who) {
            $this->{$role} = $accounts->create($this->institution, ucfirst($who), "$who@example.org", 'correct horse battery staple');
            $accounts->grantTemplateRole($this->{$role}, $role);
        }
        $this->holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'ZA-RA');
        $this->fonds = app(RecordService::class)->create($this->institution, self::FONDS, 'Engelbrecht family papers', 'en', null, $this->holder, 'EFP');
    }

    public function test_an_authority_record_is_created_and_described_by_isaar_cpf(): void
    {
        $this->actingAs($this->contributor)->post('/agents', ['type' => 'person', 'name' => 'Engelbrecht, Johanna (1871-1949)', 'language' => 'en', 'identifier' => 'P-001'])
            ->assertRedirect();
        $agent = Agent::sole();
        $this->assertSame("https://archives.example.org/agent/{$agent->id}", $agent->iri);
        $this->assertSame(['person', 'P-001'], [$agent->typeNotation(), $agent->identifier]);

        $this->actingAs($this->contributor)->post("/agents/{$agent->id}", ['language' => 'en', 'type' => 'person', 'identifier' => 'P-001',
            'values' => ['agent-authorised-name' => 'Engelbrecht, Johanna (1871-1949)', 'agent-other-name' => "Johanna du Toit\nAnna Engelbrecht",
                'agent-dates-of-existence' => '1871-1949', 'agent-history' => 'Farmer and diarist of Rietvlei.', 'agent-general-context' => 'The Cape Colony after 1870.'],
            'description_identifier' => 'ZA-RA-AR-001', 'status' => Vocab::DESCRIPTION_STATUSES.'final'])->assertRedirect(route('agents.show', $agent));
        $agent->refresh();
        $this->assertSame(['Anna Engelbrecht', 'Johanna du Toit'], $agent->namesOfType('other')->pluck('value')->sort()->values()->all());
        $this->assertSame('ZA-RA-AR-001', $agent->description_identifier);

        $this->actingAs($this->contributor)->get("/agents/{$agent->id}")->assertOk()
            ->assertSeeInOrder(['5.1 Identity', '5.2 Description', '5.2.2 History', 'Farmer and diarist of Rietvlei.', '5.4 Control'])
            ->assertSee('Occupations (5.2.5)')->assertSee('Export as EAC-CPF');
        $this->actingAs($this->contributor)->get('/agents?q=du+toit')->assertOk()->assertSee('Engelbrecht, Johanna (1871-1949)');
        $this->actingAs($this->contributor)->get('/agents?type=family')->assertDontSee('Engelbrecht, Johanna (1871-1949)');
        $this->actingAs($this->contributor)->get('/')->assertSee('Authority records');
    }

    public function test_permissions_follow_the_authority_kind_and_reach_existing_institutions(): void
    {
        $agent = $this->agents->create($this->institution, 'corporate-body', 'Riverside Parish Council', 'en');
        $this->actingAs($this->translator)->get("/agents/{$agent->id}")->assertOk();
        $this->actingAs($this->translator)->post('/agents', ['type' => 'person', 'name' => 'X', 'language' => 'en'])->assertForbidden();
        $this->actingAs($this->contributor)->post("/agents/{$agent->id}/delete")->assertForbidden();
        $this->actingAs($this->contributor)->get("/agents/{$agent->id}/merge")->assertForbidden();

        // An institution installed before authority records gets the default grants once.
        $kind = Concept::where('iri', Vocab::kind('authority'))->value('id');
        Permission::where(['institution_id' => $this->institution->id, 'kind_id' => $kind])->delete();
        app(AccessControl::class)->flush();
        $this->assertFalse($this->contributor->fresh()->may('create', 'authority'));
        app(RoleService::class)->grantKind($this->institution, 'authority');
        app(RoleService::class)->grantKind($this->institution, 'authority');
        app(AccessControl::class)->flush();
        $this->assertTrue($this->contributor->fresh()->may('create', 'authority'));
        $this->assertTrue($this->administrator->fresh()->may('delete', 'authority'));
        $this->assertSame(1 + 4 + 3 + 1 + 5, Permission::where(['institution_id' => $this->institution->id, 'kind_id' => $kind])->count(), 'public, editor, contributor, translator, administrator');
    }

    public function test_relations_take_the_agents_their_ric_class_allows(): void
    {
        $johanna = $this->agents->create($this->institution, 'person', 'Engelbrecht, Johanna', 'en');
        $pieter = $this->agents->create($this->institution, 'person', 'Engelbrecht, Pieter', 'en');
        $family = $this->agents->create($this->institution, 'family', 'Engelbrecht family', 'en');
        $council = $this->agents->create($this->institution, 'corporate-body', 'Riverside Parish Council', 'en');
        $clerk = $this->agents->create($this->institution, 'position', 'Town Clerk of Riverside', 'en');

        $this->actingAs($this->administrator)->post("/records/{$this->fonds->id}/relations", ['relate' => 'creation|source', 'other' => 'agent:'.$family->id])->assertRedirect();
        $this->actingAs($this->administrator)->post("/agents/{$johanna->id}/relations", ['relate' => 'spouse|source', 'other' => 'agent:'.$pieter->id, 'from' => '1893'])->assertRedirect();
        $this->actingAs($this->administrator)->post("/agents/{$johanna->id}/relations", ['relate' => 'spouse|source', 'other' => 'agent:'.$council->id])->assertStatus(422);
        $this->actingAs($this->administrator)->post("/agents/{$family->id}/relations", ['relate' => 'membership|source', 'other' => 'agent:'.$johanna->id])->assertRedirect();
        $this->actingAs($this->administrator)->post("/agents/{$pieter->id}/relations", ['relate' => 'position-holding|source', 'other' => 'agent:'.$clerk->id])->assertRedirect();
        $this->actingAs($this->administrator)->post("/agents/{$clerk->id}/relations", ['relate' => 'position-in-group|source', 'other' => 'agent:'.$council->id])->assertRedirect();
        $this->actingAs($this->administrator)->get("/agents/{$johanna->id}/relations/new?relate=spouse|source&q=riverside")->assertOk()->assertDontSee('Riverside Parish Council');

        // Occupations are for persons, from an occupation-type vocabulary.
        $occupations = app(ConceptService::class)->createScheme($this->institution, 'occupation-type', 'Occupations', 'en');
        $farmer = app(ConceptService::class)->create($occupations, ['label' => 'Farmer', 'language' => 'en']);
        $this->actingAs($this->administrator)->post("/agents/{$johanna->id}/relations", ['relate' => 'occupation|source', 'other' => 'concept:'.$farmer->id, 'from' => '1893', 'to' => '1949'])->assertRedirect();
        $this->expectsNoOptionFor($council, 'occupation');

        $this->assertSame(6, Relation::count());
        $this->actingAs($this->contributor)->get("/agents/{$johanna->id}")->assertSee('Is or was married to Engelbrecht, Pieter')
            ->assertSee('Is or was a member of Engelbrecht family')->assertSee('Pursues or pursued the occupation Farmer, 1893 to 1949');
        $this->actingAs($this->contributor)->get("/records/{$this->fonds->id}")->assertSee('Was created by Engelbrecht family');
        $this->actingAs($this->contributor)->get("/agents/{$clerk->id}")->assertSee('Is or was occupied by Engelbrecht, Pieter')->assertSee('Exists or existed in Riverside Parish Council');
    }

    public function test_a_creator_not_yet_described_is_made_on_the_spot(): void
    {
        $this->actingAs($this->administrator)->get("/records/{$this->fonds->id}/relations/new?relate=creation|source&q=Engelbrecht")
            ->assertSee('Make a new person (authority record)')->assertSee('Make a new family (authority record)');
        $this->actingAs($this->administrator)->post("/records/{$this->fonds->id}/relations", ['relate' => 'creation|source', 'other' => 'new:agent-family',
            'new_name' => 'Engelbrecht family', 'new_language' => 'en'])->assertRedirect(route('records.show', $this->fonds));
        $this->assertSame(['family', 'Engelbrecht family'], [Agent::sole()->typeNotation(), Agent::sole()->described('agent-authorised-name', ['en'])]);
        $this->actingAs($this->translator)->post("/records/{$this->fonds->id}/relations", ['relate' => 'creation|source', 'other' => 'new:agent-person',
            'new_name' => 'Someone', 'new_language' => 'en'])->assertForbidden();
    }

    public function test_only_a_person_has_birth_and_death_dates_and_a_type_changes_only_while_nothing_depends_on_it(): void
    {
        $council = $this->agents->create($this->institution, 'corporate-body', 'Riverside Parish Council', 'en');
        $this->actingAs($this->administrator)->post("/agents/{$council->id}/dates", ['property' => 'birth', 'normalized' => '1902'])->assertSessionHasErrors('property');
        $this->actingAs($this->administrator)->post("/agents/{$council->id}/dates", ['property' => 'existence', 'normalized' => '1902/1950'])->assertRedirect();
        $person = $this->agents->create($this->institution, 'person', 'Engelbrecht, Johanna', 'en');
        $this->actingAs($this->administrator)->post("/agents/{$person->id}/dates", ['property' => 'birth', 'normalized' => '1871'])->assertRedirect();

        // The type changes while nothing depends on it, and not once a relation does.
        $this->actingAs($this->administrator)->post("/agents/{$council->id}", ['language' => 'en', 'type' => 'family', 'values' => []])->assertRedirect();
        $this->assertSame('family', $council->fresh()->typeNotation());
        $this->relations->create($council->fresh(), 'membership', $person, [], $this->administrator);
        $this->actingAs($this->administrator)->post("/agents/{$council->id}", ['language' => 'en', 'type' => 'position', 'values' => []])->assertSessionHasErrors('values');
        $this->actingAs($this->administrator)->post("/agents/{$person->id}", ['language' => 'en', 'type' => 'family', 'values' => []])->assertSessionHasErrors('values');
        $this->assertSame('person', $person->fresh()->typeNotation());
    }

    public function test_merging_moves_names_identifiers_and_relations_and_the_duplicate_leads_to_the_one_kept(): void
    {
        $kept = $this->agents->create($this->institution, 'person', 'Engelbrecht, Johanna', 'en', 'P-001');
        $duplicate = $this->agents->create($this->institution, 'person', 'Engelbrecht, J.', 'en', 'P-099');
        $other = $this->agents->create($this->institution, 'person', 'Engelbrecht, Pieter', 'en');
        $this->relations->create($this->fonds, 'creation', $duplicate, [], $this->administrator);
        $this->relations->create($this->fonds, 'creation', $kept, [], $this->administrator);
        $this->relations->create($duplicate, 'spouse', $other, [], $this->administrator);

        $this->actingAs($this->administrator)->get("/agents/{$duplicate->id}/merge?q=johanna")->assertOk()->assertSee('Engelbrecht, Johanna');
        $this->actingAs($this->administrator)->post("/agents/{$duplicate->id}/merge", ['into' => $kept->id])->assertRedirect(route('agents.show', $kept));

        $duplicate->refresh();
        $this->assertNotNull($duplicate->deleted_at);
        $this->assertSame($kept->id, $duplicate->merged_into_id);
        $this->assertSame(['Engelbrecht, J.'], $kept->fresh()->namesOfType('other')->pluck('value')->all());
        $this->assertSame('P-099', Identifier::where(['subject_id' => $kept->id, 'identifier_type_id' => Concept::where('iri', Vocab::IDENTIFIER_TYPES.'previous')->value('id')])->value('value'));
        $this->assertSame(1, Relation::where(['source_id' => $this->fonds->id])->count(), 'the creator is not recorded twice');
        $this->assertSame($kept->id, Relation::whereHas('relationType', fn ($q) => $q->where('notation', 'spouse'))->sole()->source_id);
        $this->actingAs($this->administrator)->get("/agents/{$duplicate->id}")->assertRedirect(route('agents.show', $kept));
    }

    public function test_splitting_moves_the_chosen_names_and_relations(): void
    {
        $agent = $this->agents->create($this->institution, 'person', 'Engelbrecht, Johanna', 'en');
        app(NameService::class)->add($agent, ['type' => 'other', 'value' => 'Engelbrecht, Anna', 'language' => 'en']);
        $relation = $this->relations->create($this->fonds, 'creation', $agent, [], $this->administrator);
        $anna = Name::where('value', 'Engelbrecht, Anna')->sole();

        $authorised = $agent->fresh()->namesOfType('authorised')->sole();
        $this->actingAs($this->administrator)->post("/agents/{$agent->id}/split", ['type' => 'person', 'names' => [$authorised->id]])->assertSessionHasErrors('names');
        $this->actingAs($this->administrator)->post("/agents/{$agent->id}/split", ['type' => 'person', 'names' => [$anna->id], 'relations' => [$relation->id]])->assertRedirect();

        $new = Agent::whereKeyNot($agent->id)->sole();
        $this->assertSame('Engelbrecht, Anna', $new->described('agent-authorised-name', ['en']));
        $this->assertSame($new->id, $relation->fresh()->target_id);
        $this->assertSame('Engelbrecht, Johanna', $agent->fresh()->described('agent-authorised-name', ['en']));
    }

    public function test_possible_duplicates_are_found_and_can_be_dismissed(): void
    {
        $a = $this->agents->create($this->institution, 'person', 'Engelbrecht, Johanna', 'en');
        $b = $this->agents->create($this->institution, 'person', 'ENGELBRECHT Johanna', 'en');
        $this->agents->create($this->institution, 'family', 'Engelbrecht, Johanna', 'en');
        $this->assertCount(1, $this->agents->duplicates($this->institution->id), 'the family is another type');
        $this->actingAs($this->administrator)->get('/agents/duplicates')->assertOk()->assertSee('ENGELBRECHT Johanna')->assertSee('Not duplicates');
        $this->actingAs($this->administrator)->post("/agents/{$b->id}/not-a-duplicate", ['other' => $a->id])->assertRedirect(route('agents.duplicates'));
        $this->assertCount(0, $this->agents->duplicates($this->institution->id));
    }

    public function test_an_embargoed_record_exists_only_for_those_who_may_update_it(): void
    {
        $agent = $this->agents->create($this->institution, 'person', 'Living, Person', 'en');
        $this->agents->updateControl($agent, ['type' => 'person', 'embargo_until' => now()->addYear()->format('Y-m-d')]);
        $this->actingAs($this->translator)->get("/agents/{$agent->id}")->assertNotFound();
        $this->actingAs($this->translator)->get('/agents')->assertDontSee('Living, Person');
        $this->actingAs($this->contributor)->get("/agents/{$agent->id}")->assertOk()->assertSee('Embargoed until');
        $this->assertTrue($this->relations->candidates($this->translator, ['agent'], 'living')->isEmpty());
        $this->agents->updateControl($agent, ['type' => 'person', 'embargo_until' => null]);
        $this->actingAs($this->translator)->get("/agents/{$agent->id}")->assertOk();
    }

    public function test_eac_cpf_export_carries_names_dates_and_relations(): void
    {
        $person = $this->agents->create($this->institution, 'person', 'Engelbrecht, Johanna', 'en', 'P-001');
        $this->agents->describe($person, ['agent-history' => 'Farmer and diarist.', 'agent-dates-of-existence' => '1871-1949'], 'en');
        app(DateService::class)->add($person, ['property' => 'birth', 'normalized' => '1871']);
        $spouse = $this->agents->create($this->institution, 'person', 'Engelbrecht, Pieter', 'en');
        $this->relations->create($person, 'spouse', $spouse, ['from' => '1893'], $this->administrator);
        $this->relations->create($this->fonds, 'creation', $person, [], $this->administrator);

        $xml = $this->actingAs($this->translator)->get("/agents/{$person->id}/eac-cpf")->assertOk()->assertHeader('Content-Type', 'application/xml; charset=utf-8')->getContent();
        $doc = simplexml_load_string($xml);
        $this->assertNotFalse($doc);
        $doc->registerXPathNamespace('e', 'urn:isbn:1-931666-33-4');
        $this->assertSame('person', (string) $doc->xpath('//e:entityType')[0]);
        $this->assertSame('Engelbrecht, Johanna', (string) $doc->xpath('//e:nameEntry[@localType="authorised"]/e:part')[0]);
        $this->assertSame('1871-01-01', (string) $doc->xpath('//e:existDates//e:fromDate/@standardDate')[0]);
        $this->assertSame('family', (string) $doc->xpath('//e:cpfRelation/@cpfRelationType')[0]);
        $this->assertSame('creatorOf', (string) $doc->xpath('//e:resourceRelation/@resourceRelationType')[0]);
        $this->assertSame('Farmer and diarist.', (string) $doc->xpath('//e:biogHist/e:p')[0]);

        $position = $this->agents->create($this->institution, 'position', 'Town Clerk', 'en');
        $this->actingAs($this->translator)->get("/agents/{$position->id}/eac-cpf")->assertNotFound();
        $this->actingAs($this->translator)->get("/agents/{$person->id}/print")->assertOk()->assertDontSee('Edit description');
    }

    public function test_the_dashboard_queues_records_that_lack_an_essential_element_and_deletion_takes_their_relations(): void
    {
        $incomplete = $this->agents->create($this->institution, 'person', 'Engelbrecht, Johanna', 'en');
        $complete = $this->agents->create($this->institution, 'corporate-body', 'Riverside Parish Council', 'en');
        $this->agents->updateControl($complete, ['type' => 'corporate-body', 'description_identifier' => 'AR-2']);
        $this->agents->describe($complete, ['agent-dates-of-existence' => '1902-1950'], 'en');
        $this->actingAs($this->contributor)->get('/agents/dashboard')->assertOk()->assertSee('Person: 1')
            ->assertSee('Engelbrecht, Johanna</a>: ', false)->assertDontSee('Riverside Parish Council</a>: ', false);

        $this->relations->create($this->fonds, 'creation', $incomplete, [], $this->administrator);
        $this->actingAs($this->administrator)->get("/agents/{$incomplete->id}/delete")->assertOk()->assertSee('1 relation');
        $this->actingAs($this->administrator)->post("/agents/{$incomplete->id}/delete")->assertRedirect(route('agents.index'));
        $this->assertSame(0, Relation::count());
        $this->actingAs($this->administrator)->get("/agents/{$incomplete->id}")->assertNotFound();
    }

    private function expectsNoOptionFor(Agent $agent, string $type): void
    {
        $this->assertFalse(collect($this->relations->options($agent))->contains('type', $type), "a {$agent->typeNotation()} has no $type");
        try {
            $occupations = Concept::where('institution_id', $this->institution->id)->first();
            $this->relations->create($agent, $type, $occupations, [], $this->administrator);
            $this->fail("a {$agent->typeNotation()} took an $type");
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }
}
