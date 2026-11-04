<?php

// OpenRiC Workspace - RiC-O 1.1's specific relations beside the broad ones (roadmap step 12c): a record set directly
// includes its units and each is directly included in it; the units arranged together follow and precede one another
// in their order; and a carrier is said to be digital or analogue, both ways, as the Archives nationales de France's
// RiC-O converter says them. Only what the public may read is named.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\InstantiationService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Institution;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RicSpecificRelationsTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    public function test_direct_inclusion_sequence_and_the_kind_of_carrier_are_said_as_ric_o_1_1_says_them(): void
    {
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($institution);
        $editor = app(AccountService::class)->create($institution, 'Editor', 'editor@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($editor, 'editor');
        $records = app(RecordService::class);
        $holder = app(RepositoryService::class)->create($institution, 'Riverside Archives', 'en', 'RA');
        $series = $records->create($institution, self::LEVEL.'series', 'Minutes', 'en', null, $holder, 'A');
        $first = $records->create($institution, self::LEVEL.'item', 'Minute book, 1900 to 1910', 'en', $series, null, '1');
        $hidden = $records->create($institution, self::LEVEL.'item', 'Minute book, 1911 to 1920', 'en', $series, null, '2');
        $third = $records->create($institution, self::LEVEL.'item', 'Minute book, 1921 to 1930', 'en', $series, null, '3');
        $scan = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($scan, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $digital = app(InstantiationService::class)->upload($first, $scan, 'book.png', 'en', 'Scan of the minute book', null, 'online', $editor);
        $paper = app(InstantiationService::class)->create($first, 'The bound volume', 'en', 'paper', 'online');
        foreach ([$holder, $series, $first, $third, $digital, $paper] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $editor);
        }

        $doc = fn ($r) => $this->getJson(route('api.record', $r->id))->assertOk()->json();
        $ids = fn ($v) => array_column(isset($v['@id']) ? [$v] : (array) $v, '@id');

        $s = $doc($series);
        $this->assertSame([$first->iri, $third->iri], $ids($s['rico:directlyIncludes']), 'the unpublished one is not named');
        $f = $doc($first);
        $this->assertSame([$series->iri], $ids($f['rico:isDirectlyIncludedIn']));
        // Arranged first, then the unpublished second, then the third: the public sees the first precede the third.
        $this->assertSame([$third->iri], $ids($f['rico:directlyPrecedesInSequence']));
        $this->assertArrayNotHasKey('rico:directlyFollowsInSequence', $f);
        $this->assertSame([$first->iri], $ids($doc($third)['rico:directlyFollowsInSequence']));
        $this->assertSame([$digital->iri], $ids($f['rico:hasOrHadDigitalInstantiation']));
        $this->assertSame([$paper->iri], $ids($f['rico:hasOrHadAnalogueInstantiation']));
        $this->assertEqualsCanonicalizing([$digital->iri, $paper->iri], $ids($f['rico:hasOrHadInstantiation']), 'the broad property stays');
        $this->assertSame([$first->iri], $ids($this->getJson(route('api.instantiation', $digital->id))->assertOk()->json('rico:isOrWasDigitalInstantiationOf')));
        $this->assertSame([$first->iri], $ids($this->getJson(route('api.instantiation', $paper->id))->assertOk()->json('rico:isOrWasAnalogueInstantiationOf')));
        // An item is a rico:Record, not a record set: it is not said to include.
        $this->assertArrayNotHasKey('rico:directlyIncludes', $f);
    }
}
