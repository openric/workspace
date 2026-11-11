<?php

// OpenRiC Workspace - Turtle and RDF/XML carry the JSON-LD's triples (Export-Only 3.3, openric/workspace#7): the Workspace's
// JSON-LD read into the graph directly gives the same graph as the general JSON-LD processor, for every public entity of
// the demonstration dataset and for a fonds's dump, and a document outside the Workspace's part of JSON-LD still goes to
// the general processor.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Api\JsonLd;
use App\Api\PublicCatalogue;
use App\Api\Rdf;
use App\Description\RepositoryService;
use App\Models\Institution;
use App\Vocabulary\SkosImporter;
use EasyRdf\Graph;
use EasyRdf\Isomorphic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RdfTest extends TestCase
{
    use RefreshDatabase;

    /** The graph the general JSON-LD processor makes, as every export did before #7. */
    private function reference(array $doc): Graph
    {
        $graph = new Graph;
        $graph->parse(json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'jsonld');

        return $graph;
    }

    private function assertSameGraph(array $doc, string $what): void
    {
        $direct = Rdf::graph($doc);
        $this->assertTrue(Isomorphic::isomorphic($direct, $this->reference($doc)), "$what: the same triples\n".$direct->serialise('ntriples'));
    }

    public function test_every_public_entity_of_the_demonstration_has_the_same_triples_read_directly(): void
    {
        // Installed as the accessibility test installs it, then the demonstration dataset loaded into it.
        config(['workspace.object_store' => $store = sys_get_temp_dir().'/workspace-rdf-'.bin2hex(random_bytes(6))]);
        $this->beforeApplicationDestroyed(fn () => exec('rm -rf '.escapeshellarg($store)));
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $institution = Institution::create(['name' => 'Riverside Demonstration Archive (synthetic)', 'base_iri' => 'https://demo.example.org']);
        app(RoleService::class)->seedDefaults($institution);
        app(RepositoryService::class)->create($institution, 'Riverside Demonstration Archive (synthetic)', 'en');
        $admin = app(AccountService::class)->create($institution, 'Admin', 'admin@demo.invalid', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($admin, 'administrator');
        $this->artisan('workspace:demo', ['--password' => 'demonstration-password'])->assertSuccessful();
        $catalogue = new PublicCatalogue(Institution::firstOrFail());
        $writer = new JsonLd($catalogue->view);
        $before = Rdf::$fallbacks;
        $seen = 0;
        foreach (['records' => 'record', 'agents' => 'agent', 'repositories' => 'repository', 'places' => 'place', 'activities' => 'activity', 'rules' => 'rule'] as $kind => $method) {
            foreach ($catalogue->query($kind)->get() as $entity) {
                if ($kind === 'activities' && $entity->is_function) {
                    continue;
                }
                $this->assertSameGraph($writer->{$method}($entity), "$kind {$entity->id}");
                if ($kind === 'records') {
                    $this->assertSameGraph($writer->dump($entity), "the dump of {$entity->id}");
                }
                $seen++;
            }
        }
        $this->assertGreaterThan(20, $seen, 'the demonstration has entities of every kind');
        $this->assertSame($before, Rdf::$fallbacks, 'none needed the general processor');
    }

    public function test_a_document_outside_the_workspaces_json_ld_goes_to_the_general_processor(): void
    {
        $before = Rdf::$fallbacks;
        $doc = ['@context' => ['ex' => 'https://example.org/', 'parts' => ['@id' => 'ex:parts', '@container' => '@list']],
            '@id' => 'https://example.org/a', 'parts' => ['one', 'two'], 'ex:weight' => 1.5];
        $this->assertSameGraph($doc, 'a list and a double');
        $this->assertSame($before + 1, Rdf::$fallbacks);
    }
}
