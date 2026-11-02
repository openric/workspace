<?php

// OpenRiC Workspace - the RiC-O mapping of the relation types (roadmap phase 23b): every bundled type is exported
// by a property, every term it names is defined by RiC-O 1.1 or the extension ontology, and its scope note, which
// says the same in words, agrees.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Relations\RelationTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RicMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_relation_type_is_mapped_to_terms_that_exist(): void
    {
        $this->loadBundledVocabularies();
        $defined = [
            'rico' => array_flip(file(resource_path('ontologies/rico-1.1.terms'), FILE_IGNORE_NEW_LINES)),
            'openricx' => array_flip(file(resource_path('ontologies/openricx-ext.terms'), FILE_IGNORE_NEW_LINES)),
        ];
        $exists = function (?string $term) use ($defined) {
            [$prefix, $local] = explode(':', (string) $term, 2) + [1 => ''];

            return $term === null || isset($defined[$prefix][$local]);
        };
        $types = collect(RelationTypes::all(null));
        $this->assertGreaterThanOrEqual(50, $types->count());
        foreach ($types as $key => $type) {
            $this->assertNotNull($type['ric_property'], "$key has no RiC-O property");
            foreach (['ric_class', 'ric_property', 'ric_inverse', 'record_set_term'] as $field) {
                $this->assertTrue($exists($type[$field]), "$key: {$type[$field]} is not a RiC-O 1.1 or extension term");
            }
            if ($type['symmetric']) {
                $this->assertSame($type['ric_property'], $type['ric_inverse'], "$key reads the same both ways");
            }
            // The scope note says the same in words.
            $note = (string) DB::table('concept_labels')->where(['concept_id' => $type['concept_id'], 'language' => 'en'])->value('scope_note');
            $this->assertStringContainsString($type['ric_property'], $note, "$key: the scope note does not name its property");
            if ($type['ric_class'] !== null && $type['ric_class'] !== 'rico:Relation') {
                $this->assertStringContainsString($type['ric_class'], $note, "$key: the scope note does not name its class");
            }
        }
        $this->assertSame(['rico:DerivationRelation', 'rico:hasOrHadDerivedInstantiation'], [$types['derivation']['ric_class'], $types['derivation']['ric_property']]);
        $this->assertSame([['instantiation', 'instantiation']], $types['derivation']['pairs']);
    }
}
