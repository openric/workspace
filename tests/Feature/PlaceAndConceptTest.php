<?php

// OpenRiC Workspace - places, the institution's own vocabularies and access points (ADR 0006, decision 6).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\PlaceService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Concept;
use App\Models\ConceptAltLabel;
use App\Models\Institution;
use App\Models\Place;
use App\Models\RecordResource;
use App\Models\Relation;
use App\Models\Repository;
use App\Models\User;
use App\Models\Vocabulary;
use App\Relations\RelationService;
use App\Vocabulary\ConceptService;
use App\Vocabulary\LocalSkos;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class PlaceAndConceptTest extends TestCase
{
    use RefreshDatabase;

    private const FONDS = 'https://workspace.openric.org/vocab/level-of-description/fonds';

    private Institution $institution;

    private Repository $holder;

    private RecordResource $fonds;

    private User $archivist;

    private User $contributor;

    private ConceptService $concepts;

    private PlaceService $places;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        $accounts = app(AccountService::class);
        $this->concepts = app(ConceptService::class);
        $this->places = app(PlaceService::class);
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->archivist = $accounts->create($this->institution, 'An Archivist', 'archivist@example.org', 'correct horse battery staple');
        $accounts->grantTemplateRole($this->archivist, 'administrator');
        $this->contributor = $accounts->create($this->institution, 'A Contributor', 'contributor@example.org', 'correct horse battery staple');
        $accounts->grantTemplateRole($this->contributor, 'contributor');
        $this->holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'ZA-RA');
        $this->fonds = app(RecordService::class)->create($this->institution, self::FONDS, 'Riverside Parish Council records', 'en', null, $this->holder, 'RPC');
    }

    public function test_a_vocabulary_and_its_concepts_have_the_institutions_iris_labels_and_notes(): void
    {
        $this->actingAs($this->archivist)->post('/vocabularies', ['kind' => 'subject', 'title' => 'Riverside subjects', 'language' => 'en'])->assertRedirect();
        $vocabulary = Vocabulary::where('institution_id', $this->institution->id)->sole();
        $this->assertSame("https://archives.example.org/vocabulary/{$vocabulary->id}", $vocabulary->iri);
        $this->assertSame('subject', $vocabulary->kind->notation);

        $this->actingAs($this->archivist)->post("/vocabularies/{$vocabulary->id}/concepts", [
            'label' => 'Local government', 'language' => 'en', 'alt_labels' => "Municipal government\nCouncils", 'hidden_labels' => 'Local govt',
            'scope_note' => 'Councils and their committees.', 'source_note' => 'LCSH', 'notation' => 'LG', 'narrower' => "Ratepayers\nCommittees",
        ])->assertRedirect();
        $concept = Concept::where('notation', 'LG')->sole();
        $this->assertSame("https://archives.example.org/concept/{$concept->id}", $concept->iri);
        $this->assertSame($this->institution->id, $concept->institution_id);
        $this->assertSame(['Councils', 'Municipal government'], $concept->altLabels->where('is_hidden', false)->pluck('label')->all());
        $this->assertSame(['Committees', 'Ratepayers'], $concept->narrower()->get()->map(fn ($c) => $c->anyLabel(['en']))->sort()->values()->all());
        $this->assertSame('LCSH', $concept->labels->sole()->source_note);

        // Search finds it by an alternative label; a hidden label is never shown to a reader.
        $this->actingAs($this->contributor)->get("/vocabularies/{$vocabulary->id}?q=municipal")->assertOk()->assertSee('Local government')->assertDontSee('Ratepayers');
        $this->actingAs($this->contributor)->get("/concepts/{$concept->id}")->assertOk()->assertSee('Use for:')->assertDontSee('Local govt');

        // The concept and its labels are audited; a bundled import is not.
        $this->assertTrue(DB::table('audit_log')->where(['subject_table' => 'concepts', 'subject_id' => $concept->id])->whereNotNull('after->labels')->exists());
        $this->assertSame(0, DB::table('audit_log')->where('subject_table', 'concepts')->whereNotIn('subject_id', Concept::whereNotNull('institution_id')->pluck('id'))->count());

        // A translation adds a language; emptying the last label is refused.
        $this->actingAs($this->archivist)->post("/concepts/{$concept->id}", ['language' => 'af', 'label' => 'Plaaslike regering', 'notation' => 'LG'])->assertRedirect(route('concepts.show', $concept));
        $this->assertSame('Plaaslike regering', $concept->fresh()->label(['af']));
        $this->actingAs($this->archivist)->post("/concepts/{$concept->id}", ['language' => 'af', 'label' => '', 'notation' => 'LG']);
        $this->actingAs($this->archivist)->post("/concepts/{$concept->id}", ['language' => 'en', 'label' => '', 'notation' => 'LG'])->assertSessionHasErrors('label');
        $this->assertSame('Local government', $concept->fresh()->label(['en']));
    }

    public function test_reading_is_open_to_the_institution_and_changing_needs_the_vocabulary_permissions(): void
    {
        $vocabulary = $this->concepts->createScheme($this->institution, 'subject', 'Subjects', 'en');
        $concept = $this->concepts->create($vocabulary, ['label' => 'Water', 'language' => 'en']);
        $place = $this->places->create($this->institution, 'Riverside', 'en');
        foreach (['/vocabularies', "/vocabularies/{$vocabulary->id}", "/concepts/{$concept->id}", '/places', "/places/{$place->id}"] as $page) {
            $this->actingAs($this->contributor)->get($page)->assertOk();
        }
        $this->actingAs($this->contributor)->post("/vocabularies/{$vocabulary->id}/concepts", ['label' => 'Fire', 'language' => 'en'])->assertForbidden();
        $this->actingAs($this->contributor)->post('/places', ['name' => 'Elsewhere', 'language' => 'en'])->assertForbidden();
        $this->actingAs($this->contributor)->post("/concepts/{$concept->id}/deprecate")->assertForbidden();

        $other = Institution::create(['name' => 'Other Archive', 'base_iri' => 'https://other.example.org']);
        app(RoleService::class)->seedDefaults($other);
        $stranger = app(AccountService::class)->create($other, 'A Stranger', 'stranger@example.org', 'correct horse battery staple');
        $this->actingAs($stranger)->get("/concepts/{$concept->id}")->assertNotFound();
        $this->actingAs($stranger)->get("/places/{$place->id}")->assertNotFound();
        $this->actingAs($stranger)->get("/vocabularies/{$vocabulary->id}")->assertNotFound();
        // A bundled vocabulary is not an institution's.
        $this->actingAs($this->archivist)->get('/vocabularies/'.Vocabulary::whereNull('institution_id')->value('id'))->assertNotFound();
    }

    public function test_the_database_keeps_concepts_whole(): void
    {
        $subjects = $this->concepts->createScheme($this->institution, 'subject', 'Subjects', 'en');
        $more = $this->concepts->createScheme($this->institution, 'subject', 'More subjects', 'en');
        $occupations = $this->concepts->createScheme($this->institution, 'occupation-type', 'Occupations', 'en');
        $water = $this->concepts->create($subjects, ['label' => 'Water', 'language' => 'en']);
        $rivers = $this->concepts->create($subjects, ['label' => 'Rivers', 'language' => 'en', 'broader' => $water->id]);
        $clerk = $this->concepts->create($occupations, ['label' => 'Clerk', 'language' => 'en']);

        $refused = function (callable $write, string $why) {
            try {
                DB::transaction($write);
                $this->fail($why);
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        };
        $refused(fn () => DB::table('concepts')->where('id', $water->id)->update(['broader_id' => $rivers->id]), 'a cycle of broader concepts');
        $refused(fn () => DB::table('concepts')->where('id', $water->id)->update(['broader_id' => $clerk->id]), 'a broader concept in another vocabulary');
        $refused(fn () => DB::table('concepts')->where('id', $water->id)->delete(), 'deleting a concept');
        $refused(fn () => DB::table('concepts')->where('id', $clerk->id)->update(['vocabulary_id' => $subjects->id]), 'a move to a vocabulary of another kind');
        $refused(fn () => DB::table('concepts')->where('id', $water->id)->update(['institution_id' => null]), 'leaving the institution');

        // A move to a vocabulary of the same kind takes the concepts below it along.
        $this->assertSame(2, $this->concepts->move($water, $more));
        $this->assertSame($more->id, $rivers->fresh()->vocabulary_id);
        $this->assertSame($water->id, $rivers->fresh()->broader_id);

        // A relation cannot point at a bundled concept.
        $refused(fn () => DB::table('relations')->insert(['id' => (string) Str::uuid7(), 'institution_id' => $this->institution->id, 'iri' => 'https://archives.example.org/relation/x',
            'relation_type_id' => Concept::where('iri', 'https://workspace.openric.org/vocab/relation-type/subject')->value('id'),
            'source_table' => 'record_resources', 'source_id' => $this->fonds->id, 'target_table' => 'concepts',
            'target_id' => Concept::where('iri', 'https://workspace.openric.org/vocab/name-type/authorised')->value('id')]), 'a relation to a bundled concept');
    }

    public function test_a_unit_takes_subject_and_place_access_points_as_relations(): void
    {
        $subjects = $this->concepts->createScheme($this->institution, 'subject', 'Subjects', 'en');
        $water = $this->concepts->create($subjects, ['label' => 'Water supply', 'language' => 'en']);
        $occupations = $this->concepts->createScheme($this->institution, 'occupation-type', 'Occupations', 'en');
        $clerk = $this->concepts->create($occupations, ['label' => 'Water bailiff', 'language' => 'en']);
        $riverside = $this->places->create($this->institution, 'Riverside', 'en');

        // Only subject concepts are offered as subjects.
        $this->actingAs($this->archivist)->get("/records/{$this->fonds->id}/relations/new?relate=subject|source&q=water")
            ->assertOk()->assertSee('Water supply')->assertDontSee('Water bailiff');
        $this->actingAs($this->archivist)->post("/records/{$this->fonds->id}/relations", ['relate' => 'subject|source', 'other' => 'concept:'.$water->id])
            ->assertRedirect(route('records.show', $this->fonds));
        $this->actingAs($this->archivist)->post("/records/{$this->fonds->id}/relations", ['relate' => 'subject|source', 'other' => 'concept:'.$clerk->id])
            ->assertSessionHasErrors('relations');
        $this->actingAs($this->archivist)->post("/records/{$this->fonds->id}/relations", ['relate' => 'place|target', 'other' => 'place:'.$riverside->id])->assertRedirect();

        $subject = Relation::whereHas('relationType', fn ($q) => $q->where('notation', 'subject'))->sole();
        $this->assertSame(['record_resources', 'concepts'], [$subject->source_table, $subject->target_table], 'rico:hasOrHadSubject');
        $place = Relation::whereHas('relationType', fn ($q) => $q->where('notation', 'place'))->sole();
        $this->assertSame(['places', 'record_resources'], [$place->source_table, $place->target_table], 'rico:isPlaceAssociatedWith');
        $this->assertSame('accepted', $subject->standing());

        $this->actingAs($this->contributor)->get("/records/{$this->fonds->id}")->assertSee('Subject access points')
            ->assertSee('Is or was about Water supply (accepted)')->assertSee('Is associated with the place Riverside (accepted)');
        $this->actingAs($this->contributor)->get("/concepts/{$water->id}")->assertSee('Is or was the subject of Riverside Parish Council records');
        $this->actingAs($this->contributor)->get("/places/{$riverside->id}")->assertSee('Is associated with Riverside Parish Council records');
    }

    public function test_a_subject_or_place_not_yet_known_is_made_on_the_spot(): void
    {
        $this->actingAs($this->archivist)->get("/records/{$this->fonds->id}/relations/new?relate=subject|source&q=Floods")
            ->assertOk()->assertSee('Make a new concept in Subjects')->assertSee('Make a new place');
        $this->assertSame(0, Vocabulary::whereNotNull('institution_id')->count(), 'looking makes nothing');
        $this->actingAs($this->archivist)->post("/records/{$this->fonds->id}/relations", ['relate' => 'subject|source', 'other' => 'new:subject',
            'new_name' => 'Floods', 'new_language' => 'en'])->assertRedirect(route('records.show', $this->fonds));
        $concept = Concept::whereNotNull('institution_id')->sole();
        $this->assertSame(['Floods', 'Subjects'], [$concept->anyLabel(['en']), $concept->vocabulary->title]);
        $this->assertSame($concept->id, Relation::sole()->target_id);

        $this->actingAs($this->archivist)->post("/records/{$this->fonds->id}/relations", ['relate' => 'place|target', 'other' => 'new:place',
            'new_name' => 'Riverside', 'new_language' => 'en'])->assertRedirect();
        $this->assertSame('Riverside', Place::sole()->described('place-name', ['en']));

        // A contributor may relate, but not make vocabulary.
        $this->actingAs($this->contributor)->post("/records/{$this->fonds->id}/relations", ['relate' => 'subject|source', 'other' => 'new:subject',
            'new_name' => 'Drought', 'new_language' => 'en'])->assertForbidden();
    }

    public function test_merging_moves_everything_to_the_concept_kept_and_deprecating_keeps_the_concept(): void
    {
        $subjects = $this->concepts->createScheme($this->institution, 'subject', 'Subjects', 'en');
        $kept = $this->concepts->create($subjects, ['label' => 'Floods', 'language' => 'en']);
        $duplicate = $this->concepts->create($subjects, ['label' => 'Flooding', 'language' => 'en', 'alt_labels' => 'Inundation']);
        $child = $this->concepts->create($subjects, ['label' => 'Flash floods', 'language' => 'en', 'broader' => $duplicate->id]);
        $this->concepts->addMatch($duplicate, 'exact', 'https://id.loc.gov/authorities/subjects/sh85049386', 'Floods', 'LCSH');
        $relations = app(RelationService::class);
        $relations->create($this->fonds, 'subject', $duplicate, [], $this->archivist);
        $second = app(RecordService::class)->create($this->institution, self::FONDS, 'Flood relief committee', 'en', null, $this->holder, 'FRC');
        $relations->create($second, 'subject', $duplicate, [], $this->archivist);
        $relations->create($second, 'subject', $kept, [], $this->archivist);

        $this->actingAs($this->archivist)->post("/concepts/{$duplicate->id}/deprecate")->assertSessionHasErrors('concept');
        $this->actingAs($this->archivist)->post("/concepts/{$duplicate->id}/merge", ['into' => $kept->id])->assertRedirect(route('concepts.show', $kept));

        $duplicate->refresh();
        $this->assertNotNull($duplicate->deprecated_at);
        $this->assertSame($kept->id, $duplicate->replaced_by_id);
        $this->assertSame($kept->id, $child->fresh()->broader_id);
        $this->assertSame(['Flooding', 'Inundation'], ConceptAltLabel::where('concept_id', $kept->id)->orderBy('label')->pluck('label')->all());
        $this->assertSame('https://id.loc.gov/authorities/subjects/sh85049386', $kept->matches()->sole()->target_iri);
        $this->assertSame(2, Relation::where('target_id', $kept->id)->count(), 'the second unit is not made a subject twice');
        $this->assertSame(0, Relation::where('target_id', $duplicate->id)->count());
        $this->actingAs($this->archivist)->get("/concepts/{$duplicate->id}")->assertOk()->assertSee('Replaced by');

        // A deprecated concept is no longer offered, and is never deleted.
        $this->concepts->deprecate($child);
        $this->actingAs($this->archivist)->get("/records/{$this->fonds->id}/relations/new?relate=subject|source&q=flash")->assertDontSee('Flash floods, concept');
        $this->expectException(InvalidArgumentException::class);
        $relations->create($this->fonds, 'subject', $child->fresh(), [], $this->archivist);
    }

    public function test_skos_export_carries_rico_classes_and_import_never_duplicates(): void
    {
        $subjects = $this->concepts->createScheme($this->institution, 'subject', 'Subjects', 'en', 'What the records are about.');
        $water = $this->concepts->create($subjects, ['label' => 'Water', 'language' => 'en', 'alt_labels' => 'Hydrology', 'scope_note' => 'Supply and use.']);
        $rivers = $this->concepts->create($subjects, ['label' => 'Rivers', 'language' => 'en', 'broader' => $water->id]);
        $this->concepts->relate($water, $this->concepts->create($subjects, ['label' => 'Irrigation', 'language' => 'en']));
        $this->concepts->addMatch($water, 'close', 'http://vocab.getty.edu/aat/300266045');

        $turtle = $this->actingAs($this->contributor)->get("/vocabularies/{$subjects->id}/export/turtle")->assertOk()
            ->assertHeader('Content-Type', 'text/turtle; charset=utf-8')->getContent();
        foreach (['skos:ConceptScheme', 'rico:Concept', 'skos:prefLabel "Water"@en', 'skos:altLabel "Hydrology"@en', 'skos:closeMatch', 'skos:related', 'skos:broader', 'skos:hasTopConcept'] as $expected) {
            $this->assertStringContainsString($expected, str_replace('<https://www.ica.org/standards/RiC/ontology#Concept>', 'rico:Concept', $turtle), $expected);
        }
        $json = json_decode($this->actingAs($this->contributor)->get("/vocabularies/{$subjects->id}/export/jsonld")->assertOk()->getContent(), true);
        $node = collect($json)->firstWhere('@id', $water->iri);
        $this->assertContains('https://www.ica.org/standards/RiC/ontology#Concept', $node['@type']);
        foreach (['rdfxml', 'ntriples'] as $format) {
            $this->actingAs($this->contributor)->get("/vocabularies/{$subjects->id}/export/$format")->assertOk();
        }

        // Into another vocabulary: new IRIs, the old ones as exact matches; again: updated, not duplicated.
        $copy = $this->concepts->createScheme($this->institution, 'subject', 'Copy', 'en');
        $skos = app(LocalSkos::class);
        $this->assertSame(['created' => 3, 'updated' => 0], $skos->import($copy, $turtle, 'turtle', $this->archivist));
        $this->assertSame(['created' => 0, 'updated' => 3], $skos->import($copy, $turtle, 'turtle', $this->archivist));
        $copied = $copy->concepts()->get()->keyBy(fn ($c) => $c->anyLabel(['en']));
        $this->assertCount(3, $copied);
        $this->assertStringStartsWith('https://archives.example.org/concept/', $copied['Water']->iri);
        $this->assertNotSame($water->iri, $copied['Water']->iri);
        $this->assertTrue($copied['Water']->matches()->where('target_iri', $water->iri)->exists());
        $this->assertSame($copied['Water']->id, $copied['Rivers']->broader_id);
        $this->assertSame(['Irrigation'], $copied['Water']->related()->map(fn ($c) => $c->anyLabel(['en']))->all());
        $this->assertSame('Hydrology', $copied['Water']->altLabels->sole()->label);

        // The same file back into its own vocabulary changes nothing.
        $this->assertSame(['created' => 0, 'updated' => 3], $skos->import($subjects, $turtle, 'turtle', $this->archivist));
        $this->assertSame(3, $subjects->concepts()->count());
        $this->assertSame($rivers->id, Concept::where('iri', $rivers->iri)->value('id'));
    }

    public function test_a_place_has_names_a_type_coordinates_and_the_place_it_lies_in(): void
    {
        $this->actingAs($this->archivist)->post('/places', ['name' => 'Western Cape', 'language' => 'en'])->assertRedirect();
        $province = Place::sole();
        $this->assertSame("https://archives.example.org/place/{$province->id}", $province->iri);
        $this->actingAs($this->archivist)->post('/places', ['name' => 'Riverside', 'language' => 'en', 'parent' => $province->id]);
        $town = Place::where('parent_id', $province->id)->sole();

        $types = $this->concepts->createScheme($this->institution, 'place-type', 'Place types', 'en');
        $townType = $this->concepts->create($types, ['label' => 'Town', 'language' => 'en']);
        $subjects = $this->concepts->createScheme($this->institution, 'subject', 'Subjects', 'en');
        $notAType = $this->concepts->create($subjects, ['label' => 'Towns', 'language' => 'en']);

        $this->actingAs($this->archivist)->post("/places/{$town->id}", ['language' => 'en', 'values' => ['place-name' => 'Riverside',
            'place-other-name' => "Rivierkant\nRiverside Junction", 'place-history' => 'Laid out in 1902.'], 'place_type' => $townType->id,
            'latitude' => '-33.9249', 'longitude' => '18.4241', 'altitude' => '15'])->assertRedirect(route('places.show', $town));
        $town->refresh();
        $this->assertSame($townType->id, $town->place_type_id);
        $this->assertSame(['Riverside Junction', 'Rivierkant'], $town->namesOfType('other')->pluck('value')->sort()->values()->all());
        $this->assertSame('-33.924900', (string) $town->latitude);
        $this->actingAs($this->archivist)->get("/places/{$town->id}")->assertOk()->assertSee('Western Cape')->assertSee('Laid out in 1902.')->assertSee('openstreetmap.org');
        // A reader sees published values only.
        $this->actingAs($this->contributor)->get("/places/{$town->id}")->assertOk()->assertDontSee('Laid out in 1902.');
        $this->actingAs($this->contributor)->get('/places?view=tree')->assertOk()->assertSeeInOrder(['Western Cape', 'Riverside']);
        $this->actingAs($this->contributor)->get('/places?q=rivierkant')->assertOk()->assertSee('Riverside');

        foreach ([['latitude' => '91', 'longitude' => '0'], ['latitude' => '-33', 'longitude' => ''], ['latitude' => '', 'longitude' => '', 'altitude' => '15'], ['place_type' => $notAType->id]] as $wrong) {
            $this->actingAs($this->archivist)->post("/places/{$town->id}", ['language' => 'en', 'values' => ['place-name' => 'Riverside']] + $wrong)->assertSessionHasErrors('values');
        }
        $refused = fn (array $change) => $this->assertThrows(fn () => DB::transaction(fn () => DB::table('places')->where('id', $town->id)->update($change)), QueryException::class);
        $refused(['place_type_id' => $notAType->id]);
        $refused(['latitude' => 100, 'longitude' => 0]);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('places')->where('id', $province->id)->update(['parent_id' => $town->id])), QueryException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('places')->where('id', $town->id)->delete()), QueryException::class);

        // The province cannot move into the town it contains, and the move page does not offer it.
        $this->actingAs($this->archivist)->get("/places/{$province->id}/move?q=river")->assertOk()->assertDontSee('value="'.$town->id.'"', false);
        $this->actingAs($this->archivist)->post("/places/{$province->id}/move", ['parent' => $town->id])->assertSessionHasErrors('parent');
        $this->actingAs($this->archivist)->post("/places/{$town->id}/move", ['parent' => 'top'])->assertRedirect();
        $this->assertNull($town->fresh()->parent_id);
    }

    public function test_a_place_containing_places_is_not_deleted_and_a_deleted_one_keeps_its_iri(): void
    {
        $province = $this->places->create($this->institution, 'Western Cape', 'en');
        $town = $this->places->create($this->institution, 'Riverside', 'en', $province);
        app(RelationService::class)->create($town, 'place', $this->fonds, [], $this->archivist);

        $this->actingAs($this->archivist)->get("/places/{$province->id}/delete")->assertOk()->assertSee('It has 1 place inside it')->assertDontSee('Delete Western Cape</button>', false);
        $this->actingAs($this->archivist)->post("/places/{$province->id}/delete")->assertSessionHasErrors('delete');
        $this->actingAs($this->archivist)->get("/places/{$town->id}/delete")->assertOk()->assertSee('1 relation');
        $this->actingAs($this->contributor)->post("/places/{$town->id}/delete")->assertForbidden();
        $this->actingAs($this->archivist)->post("/places/{$town->id}/delete")->assertRedirect(route('places.index'));

        $this->assertNotNull($town->fresh()->deleted_at);
        $this->assertSame($town->iri, $town->fresh()->iri);
        $this->assertSame(0, Relation::count(), 'its relations go with it');
        $this->actingAs($this->archivist)->get("/places/{$town->id}")->assertNotFound();
        $this->actingAs($this->archivist)->get('/places')->assertSee('Western Cape')->assertDontSee(route('places.show', $town));
        $this->assertTrue(DB::table('audit_log')->where(['subject_table' => 'places', 'subject_id' => $town->id])->whereNotNull('after->deleted_at')->exists());
    }

    public function test_a_former_containment_is_a_dated_relation(): void
    {
        $cape = $this->places->create($this->institution, 'Cape Colony', 'en');
        $province = $this->places->create($this->institution, 'Western Cape', 'en');
        $farm = $this->places->create($this->institution, 'Rietvlei', 'en', $province);
        $this->actingAs($this->archivist)->get("/places/{$farm->id}/relations/new?relate=place-containment|target&q=cape")
            ->assertOk()->assertSee('Cape Colony')->assertSee('Western Cape');
        $this->actingAs($this->archivist)->post("/places/{$farm->id}/relations", ['relate' => 'place-containment|target', 'other' => 'place:'.$cape->id,
            'from' => '1806', 'to' => '1910', 'state' => 'past'])->assertRedirect(route('places.show', $farm));
        $relation = Relation::sole();
        $this->assertSame([$cape->id, $farm->id], [$relation->source_id, $relation->target_id], 'rico:containsOrContained');
        $this->actingAs($this->contributor)->get("/places/{$farm->id}")->assertSee('Containment over time')->assertSee('Lies or lay in Cape Colony, 1806 to 1910 (accepted)');
        $this->actingAs($this->contributor)->get("/places/{$cape->id}")->assertSee('Contains or contained Rietvlei, 1806 to 1910');
    }

    public function test_a_duplicate_place_merges_into_the_place_kept_with_its_relations_names_and_places_inside(): void
    {
        $places = app(PlaceService::class);
        $relations = app(RelationService::class);
        $kept = $places->create($this->institution, 'rue Ganneron (Paris, France)', 'fr');
        $duplicate = $places->create($this->institution, 'Ganneron (rue)', 'fr');
        $inside = $places->create($this->institution, 'Ganneron, number 12', 'fr', $duplicate);
        $other = app(RecordService::class)->create($this->institution, self::FONDS, 'Notary deeds', 'en', null, $this->holder, 'ND');
        $relations->create($duplicate, 'place', $this->fonds, [], $this->archivist);
        $relations->create($kept, 'place', $this->fonds, [], $this->archivist);
        $relations->create($duplicate, 'place', $other, [], $this->archivist);

        $places->merge($duplicate, $kept, $this->archivist);

        $this->assertSame(1, Relation::where(['source_table' => 'places', 'source_id' => $kept->id, 'target_id' => $this->fonds->id])->count(), 'not made twice');
        $this->assertTrue(Relation::where(['source_id' => $kept->id, 'target_id' => $other->id])->exists(), 'moved');
        $this->assertFalse(Relation::where('source_id', $duplicate->id)->orWhere('target_id', $duplicate->id)->exists());
        $this->assertSame($kept->id, $inside->fresh()->parent_id);
        $this->assertSame(['Ganneron (rue)'], $kept->names()->whereHas('nameType', fn ($q) => $q->where('notation', 'other'))->pluck('value')->all());
        $this->assertSame($kept->id, $duplicate->fresh()->merged_into_id);
        $this->assertNotNull($duplicate->fresh()->deleted_at);
        $this->actingAs($this->archivist)->get("/places/{$duplicate->id}")->assertRedirect(route('places.show', $kept));

        // Not into itself, a deleted place, or a place inside it.
        $this->expectException(InvalidArgumentException::class);
        $places->merge($kept, $inside, $this->archivist);
    }
}
