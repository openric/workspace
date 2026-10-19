<?php

// OpenRiC Workspace - the Workspace's vocabulary IRIs resolve, publicly.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VocabularyPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
    }

    public function test_a_scheme_iri_serves_html_without_signing_in(): void
    {
        $this->get('/vocab/account-role/')->assertOk()->assertSee('OpenRiC Workspace Account Roles')->assertSee('administrator');
    }

    public function test_a_scheme_iri_serves_turtle_when_asked(): void
    {
        $this->get('/vocab/permission/', ['Accept' => 'text/turtle'])->assertOk()
            ->assertHeader('Content-Type', 'text/turtle; charset=utf-8')
            ->assertSee('skos:ConceptScheme', false);
        $this->get('/vocab/audit-action.ttl')->assertOk()->assertSee('sign-in-failed', false);
    }

    public function test_a_concept_iri_serves_its_page(): void
    {
        $this->get('/vocab/permission/view-audit-log')->assertOk()->assertSee('View audit log')
            ->assertSee('https://workspace.openric.org/vocab/permission/view-audit-log');
    }

    public function test_only_bundled_vocabularies_and_known_concepts_are_served(): void
    {
        $this->get('/vocab/role-type/')->assertNotFound();
        $this->get('/vocab/permission/delete-everything')->assertNotFound();
        $this->get('/vocab/..%2F..%2Fenv.ttl')->assertNotFound();
    }
}
