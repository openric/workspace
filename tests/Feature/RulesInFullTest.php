<?php

// OpenRiC Workspace - rules and mandates in full (roadmap step 12d): a new rule asks for its number, status, dates and
// history; it is related to the agent that issued it, the agent that enforces it, the record that carries its text, and
// the rules it amends and supersedes, as RiC-O 1.1 says the first three and the extension ontology the last two; a
// mandate's relation cites its section as its basis; and a retention schedule names the rule it is issued under, the
// rule's name its authority where none is written.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\AgentService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Description\RuleService;
use App\Models\Institution;
use App\Models\RetentionSchedule;
use App\Models\Rule;
use App\Relations\RelationService;
use App\Retention\RetentionService;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RulesInFullTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    public function test_a_rule_is_recorded_as_it_stands_with_who_issued_and_enforces_it_its_text_and_its_amendments(): void
    {
        $this->loadBundledVocabularies();
        foreach (['role-type', 'rule-type'] as $fixture) {
            app(SkosImporter::class)->import(file_get_contents(base_path("tests/Fixtures/$fixture.ttl")));
        }
        $institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($institution);
        $admin = app(AccountService::class)->create($institution, 'Admin', 'admin@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($admin, 'administrator');

        // A new rule asks for what a records manager records first.
        $mandate = app(RuleService::class)->types($institution->id)->first(fn ($t) => $t->notation === 'mandate');
        $this->actingAs($admin)->get(route('rules.create'))->assertOk()->assertSee('In force from')->assertSee('openricx:hasRuleStatus');
        $this->actingAs($admin)->post(route('rules.store'), ['name' => 'National Archives and Records Service of South Africa Act', 'language' => 'en', 'type' => $mandate->id,
            'status' => 'amended', 'identifier' => '43 of 1996', 'commencement' => '1997-01-01', 'history' => 'Amended by the Cultural Laws Amendment Act 36 of 2001.'])->assertRedirect();
        $act = Rule::sole();
        $this->assertSame('amended', $act->status->notation);
        $this->assertSame(['43 of 1996'], $act->identifiers()->pluck('value')->all());
        $this->assertSame('1997-01-01', $act->dates()->sole()->normalized_value);

        $parliament = app(AgentService::class)->create($institution, 'corporate-body', 'Parliament of the Republic of South Africa', 'en');
        $narssa = app(AgentService::class)->create($institution, 'corporate-body', 'National Archives and Records Service of South Africa', 'en');
        $holder = app(RepositoryService::class)->create($institution, 'Riverside Archives', 'en', 'RA');
        $gazette = app(RecordService::class)->create($institution, self::LEVEL.'item', 'Government Gazette 17471, 2 October 1996', 'en', null, $holder, 'GG17471');
        $amending = app(RuleService::class)->create($institution, 'Cultural Laws Amendment Act', 'en');
        $relations = app(RelationService::class);
        $relations->create($act, 'issuance', $parliament, [], $admin);
        $relations->create($act, 'enforcement', $narssa, [], $admin);
        $relations->create($act, 'expression', $gazette, [], $admin);
        $relations->create($amending, 'amendment', $act, [], $admin);
        // The section that gives the authority is the basis of the mandate relation.
        $relations->create($act, 'mandate', $narssa, ['basis' => 'Section 13(2) of the Act', 'basis_language' => 'en'], $admin);

        $this->actingAs($admin)->get(route('rules.show', $act))->assertOk()->assertSee('Issued by')->assertSee('Parliament of the Republic of South Africa')
            ->assertSee('Its text')->assertSee('Government Gazette 17471')->assertSee('In force, amended')->assertSee('Amendments');

        foreach ([$holder, $act, $amending, $parliament, $narssa, $gazette] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $admin);
        }
        $doc = $this->getJson(route('api.rule', $act->id))->assertOk()->json();
        $iris = fn ($v) => array_column(isset($v['@id']) ? [$v] : (array) $v, '@id');
        $this->assertSame([$parliament->iri], $iris($doc['rico:issuedBy']));
        $this->assertSame([$narssa->iri], $iris($doc['rico:isOrWasEnforcedBy']));
        $this->assertSame([$gazette->iri], $iris($doc['rico:isOrWasExpressedBy']));
        $this->assertSame([$amending->iri], $iris($doc['openricx:isOrWasAmendedBy']));
        $this->assertSame('https://workspace.openric.org/vocab/rule-status/amended', $doc['openricx:hasRuleStatus']['@id']);
        $this->assertSame('Amended by the Cultural Laws Amendment Act 36 of 2001.', collect((array) $doc['rico:history'])->flatten()->first(fn ($v) => is_string($v) && str_starts_with($v, 'Amended')));
        $this->assertSame([$act->iri], $iris($this->getJson(route('api.rule', $amending->id))->json('openricx:amendsOrAmended')));

        // A retention schedule names the rule it is issued under, whose name is its authority where none is written.
        $schedule = app(RetentionService::class)->saveSchedule($institution, ['reference' => 'RS-1', 'title' => 'Council schedule', 'authority_rule_id' => $act->id], null, $admin);
        $this->assertSame([$act->id, 'National Archives and Records Service of South Africa Act'], [$schedule->authority_rule_id, $schedule->authority]);
        $this->assertSame($act->id, app(RetentionService::class)->newVersion($schedule->fresh(), $admin)->authority_rule_id, 'kept by the next version');
        $this->assertSame(2, RetentionSchedule::count());
    }
}
