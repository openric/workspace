<?php

// OpenRiC Workspace - the RiC acceptance scenarios (roadmap phase 23b), from the RiC standards gap assessment and the
// design review of 1 and 2 November 2026: each is built in one institution, published, exported as RiC-O by the
// public API and imported into another, and must come through with its entities, typed and dated relations, evidence
// and languages, or be listed in the import's report; nothing is lost in silence.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Api\JsonLd;
use App\Description\ActivityService;
use App\Description\AgentService;
use App\Description\DateService;
use App\Description\InstantiationService;
use App\Description\NameService;
use App\Description\PlaceService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Description\RuleService;
use App\Models\Activity;
use App\Models\Agent;
use App\Models\Concept;
use App\Models\Import;
use App\Models\Institution;
use App\Models\Place;
use App\Models\RecordResource;
use App\Models\Relation;
use App\Models\Repository;
use App\Models\Rule;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Relations\RelationService;
use App\Support\Vocab;
use App\Vocabulary\SkosImporter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Imagick;
use ImagickPixel;
use Tests\TestCase;

class RicScenariosTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://archives.example.org/api/ric/v1';

    private Institution $river;

    private Institution $hill;

    private User $editor;

    private User $theirs;

    private Repository $holder;

    private Repository $theirHolder;

    private string $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        Mail::fake();
        $this->loadBundledVocabularies();
        foreach (['role-type', 'rule-type', 'activity-type'] as $fixture) {
            app(SkosImporter::class)->import(file_get_contents(base_path("tests/Fixtures/$fixture.ttl")));
        }
        [$this->river, $this->editor] = $this->institution('Riverside Archives', 'https://archives.example.org', 'editor@example.org');
        [$this->hill, $this->theirs] = $this->institution('Hillside Archives', 'https://hills.example.org', 'hills@example.org');
        $this->holder = app(RepositoryService::class)->create($this->river, 'Riverside Archives', 'en', 'RA');
        $this->theirHolder = app(RepositoryService::class)->create($this->hill, 'Hillside Archives', 'en', 'HA');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->store)) {
            exec('rm -rf '.escapeshellarg($this->store));
        }
        parent::tearDown();
    }

    /** @return array{0: Institution, 1: User} */
    private function institution(string $name, string $base, string $email): array
    {
        $institution = Institution::create(['name' => $name, 'base_iri' => $base]);
        app(RoleService::class)->seedDefaults($institution);
        $editor = app(AccountService::class)->create($institution, 'Editor', $email, 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($editor, 'administrator');

        return [$institution, $editor];
    }

    private function publish(Model ...$entities): void
    {
        foreach ($entities as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->editor);
        }
    }

    /** What the public API says of these entities, as one RiC-O graph, as a harvester would gather it. */
    private function exported(Model ...$entities): string
    {
        $paths = [RecordResource::class => 'records', Agent::class => 'agents', Repository::class => 'repositories', Place::class => 'places', Rule::class => 'rules', Activity::class => 'activities'];
        $graph = collect($entities)->map(fn (Model $e) => $this->getJson(self::API.'/'.$paths[$e::class].'/'.$e->getKey())->assertOk()->json())
            ->map(fn (array $d) => collect($d)->except('@context')->all())->values()->all();

        return json_encode(['@context' => JsonLd::CONTEXT, '@graph' => $graph], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Import the graph into the other institution, committed, and return its report. */
    private function importElsewhere(string $graph): array
    {
        $location = $this->actingAs($this->theirs)->post('https://hills.example.org/imports', ['file' => UploadedFile::fake()->createWithContent('graph.jsonld', $graph),
            'format' => 'ric-o', 'language' => 'en', 'repository' => $this->theirHolder->id])->assertRedirect()->assertSessionHasNoErrors()->headers->get('Location');
        $import = Import::findOrFail(basename($location));
        $this->actingAs($this->theirs)->post("https://hills.example.org/imports/{$import->id}/commit")->assertRedirect();

        return $import->fresh()->report;
    }

    /** The entity of this class in the other institution with this authorised name. */
    private function there(string $class, string $name): Model
    {
        return $class::where('institution_id', $this->hill->id)->get()->sole(fn (Model $e) => EntityKinds::label($e) === $name);
    }

    /** Its relations there, as [type, other end's name, from, to] from the entity's side. */
    private function relationsThere(Model $entity): array
    {
        return app(RelationService::class)->of($entity)->map(fn (Relation $r) => [$r->kind(), EntityKinds::label($r->other), $r->fromSource ? 'from' : 'to',
            $r->datedAs('beginning')?->normalized_value, $r->datedAs('end')?->normalized_value])->sortBy(fn ($r) => implode('|', $r))->values()->all();
    }

    public function test_s1_a_creator_whose_context_changes_comes_through_with_successors_positions_activities_and_mandate(): void
    {
        $agents = app(AgentService::class);
        $relations = app(RelationService::class);
        $dates = app(DateService::class);
        $board = $agents->create($this->river, 'corporate-body', 'Riverside Town Board', 'en');
        $council = $agents->create($this->river, 'corporate-body', 'Riverside Town Council', 'en');
        $clerk = $agents->create($this->river, 'position', 'Town Clerk of Riverside', 'en');
        $botha = $agents->create($this->river, 'person', 'Botha, Jan', 'en');
        $smit = $agents->create($this->river, 'person', 'Smit, Anna', 'en');
        $fonds = app(RecordService::class)->create($this->river, Vocab::BASE.'level-of-description/fonds', 'Riverside local authority records', 'en', null, $this->holder, 'RLA');
        $ordinance = app(RuleService::class)->create($this->river, 'Local Government Ordinance 17 of 1939', 'en', Concept::where('iri', Rule::TYPES.'mandate')->value('id'));
        $minutes = app(ActivityService::class)->create($this->river, 'Keeping the council minutes', 'en', Concept::where('iri', Activity::TYPES.'production')->value('id'));

        $relations->create($fonds, 'creation', $board, [], $this->editor);
        $relations->create($fonds, 'creation', $council, [], $this->editor);
        $relations->create($board, 'agent-temporal', $council, ['from' => '1939-07-01', 'certainty' => 'certain'], $this->editor);
        $relations->create($botha, 'position-holding', $clerk, ['from' => '1902', 'to' => '1930'], $this->editor);
        $relations->create($smit, 'position-holding', $clerk, ['from' => '1930', 'to' => '1960'], $this->editor);
        $relations->create($clerk, 'position-in-group', $council, [], $this->editor);
        $relations->create($ordinance, 'mandate', $council, ['from' => '1939-07-01'], $this->editor);
        $relations->create($minutes, 'performance', $clerk, [], $this->editor);
        $relations->create($minutes, 'result', $fonds, [], $this->editor);
        $dates->add($board, ['property' => 'existence', 'normalized' => '1902/1939']);
        $dates->add($minutes, ['property' => 'beginning', 'normalized' => '1902']);
        $this->publish($this->holder, $board, $council, $clerk, $botha, $smit, $fonds, $ordinance, $minutes);

        $report = $this->importElsewhere($this->exported($this->holder, $board, $council, $clerk, $botha, $smit, $fonds, $ordinance, $minutes));
        $this->assertSame(0, $report['actions']['rejected'] ?? 0, json_encode($report['issues']));

        $this->assertSame('position', $this->there(Agent::class, 'Town Clerk of Riverside')->agentType->notation);
        $this->assertSame([
            ['agent-temporal', 'Riverside Town Council', 'from', '1939-07-01', null],
            ['creation', 'Riverside local authority records', 'to', null, null],
        ], $this->relationsThere($this->there(Agent::class, 'Riverside Town Board')));
        $this->assertSame([
            ['performance', 'Keeping the council minutes', 'to', null, null],
            ['position-holding', 'Botha, Jan', 'to', '1902', '1930'],
            ['position-holding', 'Smit, Anna', 'to', '1930', '1960'],
            ['position-in-group', 'Riverside Town Council', 'from', null, null],
        ], $this->relationsThere($this->there(Agent::class, 'Town Clerk of Riverside')));
        $mandate = $this->there(Rule::class, 'Local Government Ordinance 17 of 1939');
        $this->assertTrue($mandate->isMandate());
        $this->assertSame([['mandate', 'Riverside Town Council', 'from', '1939-07-01', null]], $this->relationsThere($mandate));
        $this->assertSame('certain', Relation::where('institution_id', $this->hill->id)->whereHas('relationType', fn ($q) => $q->where('notation', 'agent-temporal'))->sole()->certainty?->notation);
        $this->assertSame([['performance', 'Town Clerk of Riverside', 'from', null, null], ['result', 'Riverside local authority records', 'from', null, null]],
            $this->relationsThere($this->there(Activity::class, 'Keeping the council minutes')));
    }

    public function test_s2_one_record_with_several_instantiations_keeps_each_with_its_derivation_fixity_and_status(): void
    {
        $instantiations = app(InstantiationService::class);
        $letter = app(RecordService::class)->create($this->river, Vocab::BASE.'level-of-description/item', 'Letter of 3 May 1902', 'en', null, $this->holder, 'L1');
        $paper = $instantiations->create($letter, 'The letter on paper', 'en', 'paper', 'on-request', $this->editor);
        $lost = $instantiations->create($letter, 'Carbon copy kept by the clerk', 'en', 'paper', 'lost', $this->editor);
        $image = new Imagick;
        $image->newImage(60, 40, new ImagickPixel('#d8c9a3'));
        $image->setImageFormat('tiff');
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, $image->getImageBlob());
        $master = $instantiations->upload($letter, $path, 'letter-master.tif', 'en', 'Master scan', null, 'online', $this->editor);
        $relations = app(RelationService::class);
        $relations->create($paper, 'derivation', $master, ['from' => '2024-03-01'], $this->editor);
        $relations->create($paper, 'derivation', $lost, [], $this->editor);
        $this->publish($this->holder, $letter);

        // Each carrier is its own entity with its own IRI; the master's file keeps its SHA-256; the lost copy stays described.
        $this->assertCount(3, collect([$paper, $lost, $master])->pluck('iri')->unique());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $master->files()->first()->sha256);
        $this->assertSame('lost', $lost->fresh()->availability->notation);
        $this->assertSame(0, $lost->files()->count());
        // The derivations are graph edges any client can follow (Graph Traversal), dated where they are.
        $rows = collect($this->getJson(self::API.'/relations?limit=200')->assertOk()->json('data'))->where('rico_predicate', 'rico:hasOrHadDerivedInstantiation');
        $this->assertEqualsCanonicalizing([[$paper->id, $master->id, '2024-03-01'], [$paper->id, $lost->id, null]],
            $rows->map(fn ($r) => [$r['subject_id'], $r['object_id'], $r['start_date'] ?? null])->values()->all());
        $this->assertSame(['Instantiation', 'Instantiation'], [$rows->first()['domain_class'], $rows->first()['range_class']]);
    }

    public function test_s3_a_contested_creator_keeps_both_claims_their_sources_and_the_review(): void
    {
        $agents = app(AgentService::class);
        $relations = app(RelationService::class);
        $records = app(RecordService::class);
        $fonds = $records->create($this->river, Vocab::BASE.'level-of-description/fonds', 'Riverside water scheme records', 'en', null, $this->holder, 'RWS');
        $minutes = $records->create($this->river, Vocab::BASE.'level-of-description/item', 'Minutes of 4 June 1931', 'en', $fonds, null, '1');
        $letter = $records->create($this->river, Vocab::BASE.'level-of-description/item', 'Letter of the Board, 1932', 'en', $fonds, null, '2');
        $council = $agents->create($this->river, 'corporate-body', 'Riverside Town Council', 'en');
        $board = $agents->create($this->river, 'corporate-body', 'Riverside Water Board', 'en');
        $engineer = $agents->create($this->river, 'person', 'Venter, Piet', 'en');
        $byCouncil = $relations->create($fonds, 'creation', $council, [], $this->editor);
        $relations->addEvidence($byCouncil, $minutes, $this->editor);
        $byBoard = $relations->create($fonds, 'creation', $board, [], $this->editor);
        $relations->addEvidence($byBoard, $letter, $this->editor);
        // A second archivist reads the letter otherwise: the claim stands disputed, both kept.
        $second = app(AccountService::class)->create($this->river, 'Second archivist', 'second@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($second, 'editor');
        $relations->assert($byBoard, $second, false, 'The letter is signed for the Council; the Board did not yet exist.', 'en');
        // A model proposed the engineer; a reviewer rejects it, and it is kept as rejected.
        $guess = $relations->create($fonds, 'creation', $engineer, [], null, ['origin' => 'model', 'label' => 'name finder 0.3']);
        $relations->review($guess->assertions()->sole(), false, $this->editor);
        $this->publish($this->holder, $fonds, $minutes, $letter, $council, $board, $engineer);

        $this->assertSame(['accepted', 'disputed', 'rejected'], [$byCouncil->fresh()->standing(), $byBoard->fresh()->standing(), $guess->fresh()->standing()]);
        $this->assertSame(2, $byBoard->assertions()->count(), 'both claims about the Board are kept, with their bases');
        // The public reads the disputed claim as disputed, with its source, and nothing of the rejected guess.
        $doc = $this->getJson(self::API.'/records/'.$fonds->id)->assertOk()->json();
        $qualified = collect($doc['rico:thingIsSourceOfRelation'] ?? [])->keyBy(fn ($r) => $r['rico:relationHasTarget']['@id']);
        $this->assertSame('disputed', $qualified[$board->iri]['rico:relationCertainty']);
        $this->assertSame([['@id' => $letter->iri]], $qualified[$board->iri]['rico:isEvidencedBy']);
        $this->assertSame([['@id' => $minutes->iri]], $qualified[$council->iri]['rico:isEvidencedBy']);
        $this->assertStringNotContainsString($engineer->iri, json_encode($doc));
        $this->assertNotContains($engineer->iri, array_column($this->getJson(self::API.'/graph?uri='.urlencode($fonds->iri))->json('openric:nodes'), 'id'));

        // Elsewhere, the dispute arrives for review, not as a settled fact.
        $report = $this->importElsewhere($this->exported($this->holder, $fonds, $minutes, $letter, $council, $board));
        $this->assertContains(['certainty', 'disputed'], collect($report['issues'])->map(fn ($i) => [$i['field'], $i['value']])->all());
        $fondsThere = $this->there(RecordResource::class, 'Riverside water scheme records');
        $this->assertSame(['Letter of the Board, 1932'], Relation::where(['source_id' => $fondsThere->id])->whereHas('relationType', fn ($q) => $q->where('notation', 'creation'))
            ->get()->first(fn ($r) => EntityKinds::label($r->other ?? Agent::find($r->target_id)) === 'Riverside Water Board')->evidence->map(fn ($e) => EntityKinds::label($e->record))->all());
    }

    public function test_s4_a_record_in_its_tree_and_in_other_contexts_is_found_and_followed_from_each(): void
    {
        $records = app(RecordService::class);
        $relations = app(RelationService::class);
        $fonds = $records->create($this->river, Vocab::BASE.'level-of-description/fonds', 'Riverside Town Council', 'en', null, $this->holder, 'RTC');
        $photo = $records->create($this->river, Vocab::BASE.'level-of-description/item', 'Photograph of the railway bridge', 'en', $fonds, null, '7');
        $report = $records->create($this->river, Vocab::BASE.'level-of-description/item', 'Engineer\'s report on the bridge', 'en', null, $this->holder, 'ER1');
        $opening = app(ActivityService::class)->create($this->river, 'Opening of the railway bridge', 'en');
        app(DateService::class)->add($opening, ['property' => 'associated', 'normalized' => '1904-03-12']);
        $photographer = app(AgentService::class)->create($this->river, 'person', 'Lategan, Hendrik', 'en');
        $bridge = app(PlaceService::class)->create($this->river, 'Riverside railway bridge', 'en');
        $relations->create($photo, 'documentation', $opening, [], $this->editor);
        $relations->create($photo, 'creation', $photographer, [], $this->editor);
        $relations->create($photo, 'associated-record', $report, [], $this->editor);
        $relations->create($bridge, 'place', $photo, [], $this->editor);
        $this->publish($this->holder, $fonds, $photo, $report, $opening, $photographer, $bridge);

        // One identity, many contexts: the tree, the activity it documents, its maker, a record outside its tree, a place.
        $graph = $this->getJson(self::API.'/graph?uri='.urlencode($photo->iri))->assertOk()->json();
        $this->assertEqualsCanonicalizing([$photo->iri, $fonds->iri, $report->iri, $opening->iri, $photographer->iri, $bridge->iri, $this->holder->iri], array_column($graph['openric:nodes'], 'id'));
        foreach ([$opening, $photographer, $bridge, $report] as $context) {
            $from = $this->getJson(self::API.'/relations-for/'.$context->id)->assertOk()->json();
            $this->assertContains($photo->id, array_column([...$from['incoming'], ...$from['outgoing']], 'target_id'), EntityKinds::label($context).' leads back to the photograph');
        }
        $this->assertSame($fonds->id, $this->getJson(self::API.'/hierarchy/'.$photo->id)->json('parent.id'));
        $this->getJson(self::API.'/records?q=railway')->assertOk()->assertJsonPath('openric:total', 1);
        // The ISAD(G) layout is labelled for what it is: a compatibility view of the RiC description (design review section 14).
        $this->actingAs($this->editor)->get(route('records.show', $photo))->assertOk()->assertSee('Compatibility view')->assertSee('ISAD(G)');
        $this->actingAs($this->editor)->get(route('records.edit', $photo))->assertOk()->assertSee('Compatibility view');
    }

    public function test_s5_an_exchange_keeps_identifiers_inverses_languages_and_evidence_and_lists_what_it_cannot_carry(): void
    {
        $records = app(RecordService::class);
        $fonds = $records->create($this->river, Vocab::BASE.'level-of-description/fonds', 'Riverside Parish Council records', 'en', null, $this->holder, 'RPC');
        $records->describe($fonds, ['title' => 'Rekords van die Riverside-gemeenteraad', 'scope-and-content' => 'Notules en briewe.'], 'af');
        $minutes = $records->create($this->river, Vocab::BASE.'level-of-description/series', 'Minute books', 'en', $fonds, null, '1');
        $council = app(AgentService::class)->create($this->river, 'corporate-body', 'Riverside Parish Council', 'en');
        app(NameService::class)->add($council, ['type' => 'parallel', 'value' => 'Riverside-gemeenteraad', 'language' => 'af'], $this->editor);
        $creation = app(RelationService::class)->create($fonds, 'creation', $council, ['from' => '1902'], $this->editor);
        app(RelationService::class)->addEvidence($creation, $minutes, $this->editor);
        $this->publish($this->holder, $fonds, $minutes, $council);

        $report = $this->importElsewhere($this->exported($this->holder, $fonds, $minutes, $council));
        $fondsThere = $this->there(RecordResource::class, 'Riverside Parish Council records');
        $councilThere = $this->there(Agent::class, 'Riverside Parish Council');
        // Identifier (the reference code as exported, the source holder's code first) and languages.
        $this->assertSame(['RA RPC', 'Rekords van die Riverside-gemeenteraad', 'Notules en briewe.', 'Riverside-gemeenteraad'],
            [$fondsThere->identifier, $fondsThere->described('title', ['af']), $fondsThere->described('scope-and-content', ['af']), $councilThere->namesOfType('parallel')->first()?->value]);
        // The relation, read from both ends, made once, with its date and evidence; its inverse reads from the other end.
        $made = Relation::where('institution_id', $this->hill->id)->whereHas('relationType', fn ($q) => $q->where('notation', 'creation'))->sole();
        $this->assertSame([$fondsThere->id, $councilThere->id, '1902', ['Minute books']],
            [$made->source_id, $made->target_id, $made->datedAs('beginning')?->normalized_value, $made->evidence->map(fn ($e) => EntityKinds::label($e->record))->all()]);
        // What it could not carry is listed: the import names every property it has no place for, never drops one in silence.
        $this->assertSame([], collect($report['issues'])->where('kind', 'rejected')->values()->all());
        $this->assertArrayHasKey('relations', $report['counts']);
    }

    public function test_design_a_a_place_keeps_its_names_over_time_its_coordinates_and_location_through_an_exchange(): void
    {
        $pretoria = app(PlaceService::class)->create($this->river, 'Pretoria', 'en');
        app(PlaceService::class)->updateFacts($pretoria, null, '-25.7479', '28.2293', '1339', $this->editor);
        app(PlaceService::class)->describe($pretoria, ['place-location' => 'On the Apies River, north of the Magaliesberg.'], 'en', $this->editor);
        $names = app(NameService::class);
        $first = $pretoria->names()->sole();
        app(DateService::class)->add($first, ['property' => 'used-from', 'normalized' => '1855'], $this->editor);
        $tshwane = $names->add($pretoria, ['type' => 'other', 'value' => 'Tshwane', 'language' => 'en'], $this->editor);
        app(DateService::class)->add($tshwane, ['property' => 'used-from', 'normalized' => '2005'], $this->editor);
        $this->publish($pretoria);

        $doc = $this->getJson(self::API.'/places/'.$pretoria->id)->assertOk()->json();
        $this->assertEquals([-25.7479, 28.2293, 1339], [$doc['rico:hasOrHadCoordinates']['rico:latitude'], $doc['rico:hasOrHadCoordinates']['rico:longitude'], $doc['rico:hasOrHadCoordinates']['rico:altitude']]);
        $this->assertSame(['Pretoria' => '1855', 'Tshwane' => '2005'], collect($doc['rico:hasOrHadPlaceName'])->mapWithKeys(fn ($n) => [$n['rico:textualValue']['@value'] => $n['rico:usedFromDate']['@value']])->all());
        $this->getJson(self::API.'/places?q=Tshwane')->assertOk()->assertJsonPath('openric:total', 1);

        $this->importElsewhere($this->exported($pretoria));
        $there = $this->there(Place::class, 'Pretoria');
        $this->assertSame([-25.7479, 28.2293, 1339.0, 'On the Apies River, north of the Magaliesberg.'],
            [(float) $there->latitude, (float) $there->longitude, (float) $there->altitude, $there->described('place-location', ['en'])]);
        $this->assertSame(['Pretoria' => '1855', 'Tshwane' => '2005'], $there->names()->with('dates.property')->get()
            ->mapWithKeys(fn ($n) => [$n->value => $n->dates->first(fn ($d) => $d->property->notation === 'used-from')?->normalized_value])->all());
    }

    public function test_design_b_a_repository_that_moves_and_changes_its_parent_body_keeps_each_period(): void
    {
        $relations = app(RelationService::class);
        $old = app(PlaceService::class)->create($this->river, 'Old Town Hall, Church Street', 'en');
        $new = app(PlaceService::class)->create($this->river, 'Riverside Civic Centre', 'en');
        $council = app(AgentService::class)->create($this->river, 'corporate-body', 'Riverside Town Council', 'en');
        $metro = app(AgentService::class)->create($this->river, 'corporate-body', 'Riverside Metropolitan Municipality', 'en');
        $relations->create($this->holder, 'premises', $old, ['from' => '1960', 'to' => '1985'], $this->editor);
        $relations->create($this->holder, 'premises', $new, ['from' => '1985'], $this->editor);
        $relations->create($council, 'agent-hierarchical', $this->holder, ['from' => '1960', 'to' => '2000'], $this->editor);
        $relations->create($metro, 'agent-hierarchical', $this->holder, ['from' => '2000'], $this->editor);
        $this->publish($this->holder, $old, $new, $council, $metro);

        $doc = $this->getJson(self::API.'/repositories/'.$this->holder->id)->assertOk()->json();
        $this->assertEqualsCanonicalizing([$old->iri, $new->iri], array_column($doc['rico:hasOrHadLocation'], '@id'));
        $this->assertEqualsCanonicalizing([$council->iri, $metro->iri], array_column($doc['rico:isOrWasSubordinateTo'], '@id'));

        $this->importElsewhere($this->exported($this->holder, $old, $new, $council, $metro));
        $this->assertSame([
            ['agent-hierarchical', 'Riverside Metropolitan Municipality', 'to', '2000', null],
            ['agent-hierarchical', 'Riverside Town Council', 'to', '1960', '2000'],
            ['premises', 'Old Town Hall, Church Street', 'from', '1960', '1985'],
            ['premises', 'Riverside Civic Centre', 'from', '1985', null],
        ], $this->relationsThere($this->there(Repository::class, 'Riverside Archives')));
    }

    public function test_design_e_one_record_in_two_record_sets_keeps_both_memberships_and_each_order(): void
    {
        $records = app(RecordService::class);
        $series = fn (string $title, string $code) => $records->create($this->river, Vocab::BASE.'level-of-description/series', $title, 'en', null, $this->holder, $code);
        $minutes = $series('Council minutes', 'CM');
        $bridge = $series('Railway bridge papers', 'RB');
        $photo = $records->create($this->river, Vocab::BASE.'level-of-description/item', 'Photograph of the railway bridge', 'en', $minutes, null, '7');
        $plan = $records->create($this->river, Vocab::BASE.'level-of-description/item', 'Plan of the railway bridge', 'en', $minutes, null, '8');

        // Made from the unit's page: in the bridge papers as well, after the plan.
        $this->actingAs($this->editor)->post(route('records.memberships.store', $plan), ['record_set' => $bridge->iri])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->post(route('records.memberships.store', $photo), ['record_set' => $bridge->iri])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->post(route('records.memberships.store', $photo), ['record_set' => $minutes->iri])->assertSessionHasErrors('record_set');
        $this->actingAs($this->editor)->post(route('records.memberships.store', $minutes), ['record_set' => $photo->iri])->assertSessionHasErrors('record_set');
        $proxy = $photo->memberships()->sole();
        $this->actingAs($this->editor)->post(route('records.memberships.step', [$proxy, 'up']))->assertSessionHasNoErrors();
        $this->assertSame(['Photograph of the railway bridge', 'Plan of the railway bridge'], $bridge->memberProxies()->get()->map(fn ($m) => $m->record->described('title', ['en']))->all());
        $this->actingAs($this->editor)->get(route('records.show', $photo))->assertOk()->assertSee('Railway bridge papers')->assertSee($proxy->iri);
        // Its reference code and what it inherits still come from where it is arranged.
        $this->assertSame($minutes->id, $photo->fresh()->parent_id);
        $this->publish($this->holder, $minutes, $bridge, $photo, $plan);

        $doc = $this->getJson(self::API.'/records/'.$photo->id)->assertOk()->json();
        $this->assertEqualsCanonicalizing([$minutes->iri, $bridge->iri], array_column($doc['rico:isOrWasIncludedIn'], '@id'));
        $set = $this->getJson(self::API.'/records/'.$bridge->id)->assertOk()->json();
        $this->assertSame([$photo->iri, $plan->iri], array_map(fn ($p) => $p['rico:proxyFor']['@id'], $set['rico:includesProxy']));
        $this->assertSame($set['rico:includesProxy'][1]['@id'], $set['rico:includesProxy'][0]['rico:proxyDirectlyPrecedesProxyInSequence']['@id']);
        $this->get($proxy->iri, ['Accept' => 'application/ld+json'])->assertRedirect(route('api.record', $bridge->id));

        $this->importElsewhere($this->exported($this->holder, $minutes, $bridge, $photo, $plan));
        $photoThere = $this->there(RecordResource::class, 'Photograph of the railway bridge');
        $this->assertSame('Council minutes', EntityKinds::label($photoThere->parent));
        $bridgeThere = $this->there(RecordResource::class, 'Railway bridge papers');
        $this->assertSame(['Photograph of the railway bridge', 'Plan of the railway bridge'], $bridgeThere->memberProxies()->get()->map(fn ($m) => EntityKinds::label($m->record))->all());

        // Taken out: its proxy's IRI says it is gone, not that it never was.
        $this->actingAs($this->editor)->post(route('records.memberships.step', [$proxy, 'remove']))->assertSessionHasNoErrors();
        $this->assertSame(0, $photo->memberships()->count());
        $this->get($proxy->iri, ['Accept' => 'application/ld+json'])->assertStatus(410);
    }
}
