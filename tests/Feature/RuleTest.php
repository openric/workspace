<?php

// OpenRiC Workspace - rules and mandates (rico:Rule, rico:Mandate; roadmap phase 23b): made, described and typed, a
// mandate giving an agent its authority for a time, a rule regulating a unit of description, and none of it reached
// from another institution.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// tests/Fixtures/rule-type.ttl is the published OpenRiC rule-type vocabulary (https://openric.org/vocab/rule-type.ttl).

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\AgentService;
use App\Description\DateService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Description\RuleService;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\Rule;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Relations\RelationService;
use App\Support\Vocab;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class RuleTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        foreach (['role-type', 'rule-type'] as $fixture) {
            app(SkosImporter::class)->import(file_get_contents(base_path("tests/Fixtures/$fixture.ttl")));
        }
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->editor = app(AccountService::class)->create($this->institution, 'Editor', 'editor@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->editor, 'editor');
    }

    public function test_a_mandate_is_made_described_and_gives_an_agent_its_authority_for_a_time(): void
    {
        $mandate = Concept::where('iri', Rule::TYPES.'mandate')->value('id');
        $this->actingAs($this->editor)->post(route('rules.store'), ['name' => 'Mandate of the Town Clerk', 'language' => 'en', 'type' => $mandate])->assertRedirect();
        $rule = Rule::sole();
        $this->assertSame('https://archives.example.org/rule/'.$rule->id, $rule->iri);
        $this->assertSame('rico:Mandate', EntityKinds::ricClass($rule));

        $this->actingAs($this->editor)->post(route('rules.update', $rule), ['language' => 'en', 'type' => $mandate, 'authority_iri' => 'https://www.gov.za/documents/ordinance-17-1939',
            'values' => ['rule-description' => 'Ordinance 17 of 1939, section 12: the town clerk keeps the minutes of the council.']])->assertRedirect(route('rules.show', $rule));
        $this->actingAs($this->editor)->post(route('rules.update', $rule), ['language' => 'en', 'authority_iri' => 'gazette 17'])->assertSessionHasErrors('values');
        $this->actingAs($this->editor)->get(route('rules.show', $rule))->assertOk()
            ->assertSee('Ordinance 17 of 1939, section 12')->assertSee('https://www.gov.za/documents/ordinance-17-1939')->assertSee('rico:Mandate')->assertSee('Gives authority to');

        // The mandate gives the office its authority for a time; a rule regulates a unit of description.
        $clerk = app(AgentService::class)->create($this->institution, 'position', 'Town Clerk of Riverside', 'en');
        $relations = app(RelationService::class);
        $authority = $relations->create($rule, 'mandate', $clerk, [], $this->editor);
        app(DateService::class)->add($authority, ['property' => 'beginning', 'normalized' => '1939-07-01', 'language' => 'en'], $this->editor);
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $minutes = app(RecordService::class)->create($this->institution, Vocab::BASE.'level-of-description/series', 'Council minutes', 'en', null, $holder, 'CM');
        $relations->create($rule, 'regulation', $minutes, [], $this->editor);
        $this->assertSame(['rico:MandateRelation', 'rico:regulatesOrRegulated'], $relations->of($rule)->map->ricTerm()->sort()->values()->all());
        $this->assertSame('rico:MandateRelation', $authority->ricTerm());
        // A mandate gives authority to an agent, not to a unit of description.
        $this->expectException(InvalidArgumentException::class);
        $relations->create($rule, 'mandate', $minutes, [], $this->editor);
    }

    public function test_a_rule_type_comes_from_the_rule_type_vocabularies_and_another_institution_finds_nothing(): void
    {
        $rule = app(RuleService::class)->create($this->institution, 'Riverside by-laws', 'en', null, $this->editor);
        $this->expectExceptionMessage('a rule type is a concept of the OpenRiC rule-type vocabulary');
        try {
            app(RuleService::class)->updateType($rule, Concept::where('iri', Vocab::BASE.'level-of-description/fonds')->value('id'), $this->editor);
        } finally {
            $other = Institution::create(['name' => 'Hillside Archives', 'base_iri' => 'https://hills.example.org']);
            app(RoleService::class)->seedDefaults($other);
            $theirs = app(AccountService::class)->create($other, 'Other', 'other@example.org', 'correct horse battery staple');
            app(AccountService::class)->grantTemplateRole($theirs, 'administrator');
            $this->actingAs($theirs)->get(route('rules.show', $rule))->assertNotFound();
            $this->actingAs($this->editor)->get(route('rules.index'))->assertOk()->assertSee('Riverside by-laws');
        }
    }
}
