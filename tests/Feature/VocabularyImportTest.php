<?php

// OpenRiC Workspace - tests for the SKOS vocabulary import and the concept guards.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The fixture is the published OpenRiC role-type vocabulary
// (https://openric.org/vocab/role-type.ttl, CC BY 4.0), copied at spec v0.53.0.

namespace Tests\Feature;

use App\Models\Concept;
use App\Vocabulary\SkosImporter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class VocabularyImportTest extends TestCase
{
    use RefreshDatabase;

    private const RT = 'https://openric.org/vocab/role-type/';

    private function fixture(): string
    {
        return file_get_contents(base_path('tests/Fixtures/role-type.ttl'));
    }

    public function test_imports_every_concept_with_labels_and_hierarchy(): void
    {
        $result = app(SkosImporter::class)->import($this->fixture());

        $this->assertSame(11, $result->created);
        $this->assertSame('OpenRiC Role-Type Vocabulary', $result->vocabulary->title);
        $bride = Concept::where('iri', self::RT.'bride')->with('labels')->firstOrFail();
        $this->assertSame('bride', $bride->notation);
        $this->assertSame('Bride', $bride->label(['af', 'en']));
        $this->assertSame(self::RT.'spouse', $bride->broader->iri);
        $this->assertNotNull($bride->labels->first()->definition);
    }

    public function test_a_second_import_changes_nothing(): void
    {
        $importer = app(SkosImporter::class);
        $importer->import($this->fixture());
        $ids = Concept::pluck('id', 'iri')->all();

        $result = $importer->import($this->fixture());

        $this->assertSame(0, $result->created);
        $this->assertSame(11, $result->unchanged);
        $this->assertSame($ids, Concept::pluck('id', 'iri')->all());
    }

    public function test_a_new_broader_concept_counts_as_an_update(): void
    {
        $importer = app(SkosImporter::class);
        $importer->import($this->fixture());

        $result = $importer->import($this->fixture()."\nrt:witness <http://www.w3.org/2004/02/skos/core#broader> rt:spouse .\n");

        $this->assertSame(1, $result->updated);
        $this->assertSame(10, $result->unchanged);
    }

    public function test_a_concept_dropped_from_the_source_is_kept_and_reported(): void
    {
        $importer = app(SkosImporter::class);
        $importer->import($this->fixture());

        $withoutCarer = preg_replace('/\nrt:carer a skos:Concept.*?\.\n/s', "\n", $this->fixture());
        $withoutCarer = str_replace(', rt:carer', '', $withoutCarer);
        $result = $importer->import($withoutCarer);

        $this->assertSame([self::RT.'carer'], $result->missing);
        $this->assertTrue(Concept::where('iri', self::RT.'carer')->exists());
    }

    public function test_a_deprecated_concept_is_marked_with_its_replacement(): void
    {
        $importer = app(SkosImporter::class);
        $ttl = $this->fixture()."\nrt:groom <http://www.w3.org/2002/07/owl#deprecated> true ;\n    <http://purl.org/dc/terms/isReplacedBy> rt:spouse .\n";

        $importer->import($ttl);

        $groom = Concept::where('iri', self::RT.'groom')->firstOrFail();
        $this->assertNotNull($groom->deprecated_at);
        $this->assertSame(self::RT.'spouse', $groom->replacedBy->iri);
        $this->assertFalse(Concept::current()->where('iri', self::RT.'groom')->exists());
    }

    public function test_the_database_refuses_to_delete_a_concept(): void
    {
        app(SkosImporter::class)->import($this->fixture());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('cannot be deleted');
        Concept::where('iri', self::RT.'witness')->firstOrFail()->delete();
    }

    public function test_the_database_refuses_to_change_a_concept_iri(): void
    {
        app(SkosImporter::class)->import($this->fixture());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('cannot change');
        Concept::where('iri', self::RT.'witness')->update(['iri' => self::RT.'observer']);
    }

    public function test_a_source_without_exactly_one_scheme_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(SkosImporter::class)->import('@prefix skos: <http://www.w3.org/2004/02/skos/core#> . <https://example.org/x> a skos:Concept .');
    }

    public function test_a_changed_label_changes_that_label_only(): void
    {
        $importer = app(SkosImporter::class);
        $importer->import($this->fixture());
        $before = DB::table('concept_labels')->orderBy('concept_id')->orderBy('language')->pluck('pref_label', 'concept_id')->all();
        $concept = Concept::where('iri', 'https://openric.org/vocab/role-type/editor')->sole();
        $old = $concept->label(['en']);
        $importer->import(str_replace('"'.$old.'"@en', '"Editor, changed"@en', $this->fixture()));

        $after = DB::table('concept_labels')->orderBy('concept_id')->orderBy('language')->pluck('pref_label', 'concept_id')->all();
        $this->assertSame('Editor, changed', $after[$concept->id]);
        unset($before[$concept->id], $after[$concept->id]);
        $this->assertSame($before, $after, 'no other label is touched');
    }
}
