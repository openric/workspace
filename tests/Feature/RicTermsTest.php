<?php

// OpenRiC Workspace - every field names the RiC term it is kept or exported as, and the term exists.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Concept;
use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RicTermsTest extends TestCase
{
    use RefreshDatabase;

    private const VOCAB = 'https://workspace.openric.org/vocab/';

    /** The vocabularies whose concepts stand for fields or the values of fields. */
    /** Terms proposed for the extension ontology and not yet in it: openric/spec#64 and #65. */
    private const PROPOSED = ['openricx:hasNameType', 'openricx:transcript'];

    private const MAPPED = ['description-element', 'date-property', 'level-of-description', 'agent-type', 'identifier-type',
        'relation-type', 'relation-state', 'relation-certainty', 'date-qualifier'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
    }

    public function test_every_field_names_a_ric_term_that_rico_or_the_extension_ontology_defines(): void
    {
        $defined = [
            'rico' => array_flip(file(resource_path('ontologies/rico-1.1.terms'), FILE_IGNORE_NEW_LINES)),
            'openricx' => array_flip(file(resource_path('ontologies/openricx-ext.terms'), FILE_IGNORE_NEW_LINES)),
        ];
        $standards = [self::VOCAB.'description-element/isdiah', self::VOCAB.'description-element/isad-g', self::VOCAB.'description-element/isaar-cpf', self::VOCAB.'description-element/place'];
        $checked = 0;
        foreach (self::MAPPED as $vocabulary) {
            foreach (Concept::choices(self::VOCAB.$vocabulary.'/')->get() as $concept) {
                if (in_array($concept->iri, $standards, true)) {
                    continue; // a standard groups its elements; it is not a field
                }
                $term = $concept->ricTerm();
                $this->assertNotNull($term, "$concept->iri names no RiC term in its scope note");
                [$prefix, $local] = explode(':', $term);
                if (! in_array($term, self::PROPOSED, true)) {
                    $this->assertArrayHasKey($local, $defined[$prefix], "$concept->iri names $term, which the ontology does not define");
                }
                // Every other term the note names exists too.
                preg_match_all('/\b(rico|openricx):(\w+)/', (string) $concept->labels->firstWhere('language', 'en')?->scope_note, $all, PREG_SET_ORDER);
                foreach ($all as [$named, $p, $l]) {
                    if (! in_array($named, self::PROPOSED, true)) {
                        $this->assertArrayHasKey($l, $defined[$p], "$concept->iri names $named, which the ontology does not define");
                    }
                }
                $checked++;
            }
        }
        $this->assertGreaterThan(120, $checked);
    }

    public function test_forms_and_pages_show_the_ric_term_of_each_field(): void
    {
        $institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($institution);
        $editor = app(AccountService::class)->create($institution, 'Editor', 'editor@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($editor, 'editor');
        $repository = app(RepositoryService::class)->create($institution, 'Riverside Archives', 'en', 'RA');
        $fonds = app(RecordService::class)->create($institution, self::VOCAB.'level-of-description/fonds', 'Council records', 'en', null, $repository, 'C');

        $this->actingAs($editor)->get("/records/{$fonds->id}/edit")->assertOk()
            ->assertSee('<a href="https://www.ica.org/standards/RiC/ontology#scopeAndContent"', false)
            ->assertSee('openricx:hasAppraisalInformation')->assertSee('rico:hasExtent')->assertSee('Fonds (rico:RecordSet)');
        $this->actingAs($editor)->get("/records/{$fonds->id}")->assertOk()
            ->assertSee('rico:RecordSet')->assertSee('rico:Title')->assertSee('rico:Identifier')->assertSee('rico:Date')
            ->assertDontSee('rico:scopeAndContent'); // not described, so not shown; the edit page shows its term
        $this->actingAs($editor)->get("/repositories/{$repository->id}")->assertOk()->assertSee('rico:CorporateBody')->assertSee('rico:AgentName');
        $this->actingAs($editor)->get("/repositories/{$repository->id}/edit")->assertOk()->assertSee('not in RiC-O');
    }
}
