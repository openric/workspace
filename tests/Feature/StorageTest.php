<?php

// OpenRiC Workspace - physical storage: containers, storage places and movements (openricx:Container, extension ontology 1.6.0).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\PlaceService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Description\StorageService;
use App\Exchange\DescriptionCsv;
use App\Exchange\Ead;
use App\Exchange\ExportView;
use App\Models\Concept;
use App\Models\Container;
use App\Models\Import;
use App\Models\Institution;
use App\Models\Name;
use App\Models\Place;
use App\Models\RecordResource;
use App\Models\Relation;
use App\Models\Repository;
use App\Models\User;
use App\Relations\RelationService;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Tests\TestCase;

class StorageTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private const PLACE_TYPE = 'https://workspace.openric.org/vocab/storage-place-type/';

    private Institution $institution;

    private User $editor;

    private Repository $holder;

    private RecordResource $fonds;

    private RecordResource $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->editor = $this->account('Editor', 'editor');
        $this->holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $records = app(RecordService::class);
        $this->fonds = $records->create($this->institution, self::LEVEL.'fonds', 'Riverside Parish Council records', 'en', null, $this->holder, 'RPC');
        $this->file = $records->create($this->institution, self::LEVEL.'file', 'Minutes, 1902 to 1910', 'en', $this->fonds, null, '1');
        $records->describe($this->file, ['conditions-governing-access' => 'Open.'], 'en');
    }

    private function account(string $name, string $role): User
    {
        $user = app(AccountService::class)->create($this->institution, $name, strtolower($name).'@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($user, $role);

        return $user;
    }

    private function storagePlace(string $name, string $type, ?Place $parent = null): Place
    {
        $this->actingAs($this->editor)->post(route('storage.places.store'), ['name' => $name, 'language' => 'en',
            'type' => Concept::where('iri', self::PLACE_TYPE.$type)->value('id'), 'parent' => $parent?->id])->assertRedirect()->assertSessionHasNoErrors();

        return Place::whereIn('id', Name::where(['subject_table' => 'places', 'value' => $name])->select('subject_id'))->latest('created_at')->firstOrFail();
    }

    private function container(string $name, ?Place $place = null, ?string $barcode = null): Container
    {
        $this->actingAs($this->editor)->post(route('containers.store'), ['name' => $name, 'language' => 'en', 'type' => Concept::where('iri', Container::TYPES.'box')->value('id'),
            'barcode' => $barcode, 'place' => $place?->id])->assertRedirect()->assertSessionHasNoErrors();

        return Container::whereIn('id', Name::where(['subject_table' => 'containers', 'value' => $name])->select('subject_id'))->firstOrFail();
    }

    public function test_containers_are_kept_moved_and_taken_out_with_their_history_as_dated_relations(): void
    {
        $building = $this->storagePlace('Main building', 'building');
        $strongroom = $this->storagePlace('Strongroom 2', 'strongroom', $building);
        $shelfA = $this->storagePlace('Shelf A', 'shelf', $strongroom);
        $shelfB = $this->storagePlace('Shelf B', 'shelf', $strongroom);
        $box = $this->container('Box 12', $shelfA, 'RA-000012');
        $storage = app(StorageService::class);
        $this->assertSame('Main building, Strongroom 2, Shelf A', $storage->path($storage->place($box)));

        // A move ends one location and starts the next; the note says why.
        $this->post(route('containers.move', $box), ['place' => $shelfB->id, 'note' => 'Shelf A is being repaired.'])->assertRedirect(route('containers.show', $box));
        $this->post(route('containers.move', $box), ['place' => $shelfB->id])->assertSessionHasErrors('move');
        $locations = $storage->locations($box->fresh());
        $this->assertCount(2, $locations);
        $this->assertSame([$shelfA->id, $shelfB->id], $locations->pluck('target_id')->all());
        $this->assertNotNull($locations[0]->datedAs('end'));
        $this->assertNull($locations[1]->datedAs('end'));
        $this->assertSame('Shelf A is being repaired.', $locations[1]->description);
        $this->assertTrue($locations->every(fn (Relation $r) => $r->standing() === 'accepted'));
        $this->assertSame('rico:hasOrHadLocation', $locations[0]->ricTerm());
        $this->get(route('containers.show', $box))->assertOk()->assertSee('Main building, Strongroom 2, Shelf B')->assertSee('Shelf A is being repaired.')
            ->assertSee('openricx:Container')->assertDontSee('Publication');

        // Out of storage, then placed again from the storage place's page, with others, as a bulk move.
        $this->post(route('containers.move', $box), ['place' => ''])->assertRedirect();
        $this->assertNull($storage->place($box->fresh()));
        $other = $this->container('Box 13');
        $this->assertEqualsCanonicalizing([$box->id, $other->id], $storage->unplaced($this->institution->id)->pluck('id')->all());
        $this->post(route('storage.place.move', $shelfA), ['containers' => [$box->id, $other->id], 'note' => 'Shelved after cataloguing.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertCount(2, $storage->containersIn($shelfA));
        $this->assertCount(2, $storage->containersIn($building, true), 'kept below the building');
        $this->get(route('storage.place', $strongroom))->assertOk()->assertSee('2 more in the places inside it.');
        $page = $this->get(route('storage.place', $shelfA))->assertOk()->assertSee('Box 12')->assertSee('Shelved after cataloguing.')->getContent();
        $this->assertLessThan(strpos($page, 'to '.now()->toDateString()), strpos($page, 'until now'), 'what came and went, newest first');
        $this->get(route('storage.place', $shelfB))->assertOk()->assertSee('Shelf A is being repaired.');

        // Capacity is summed per unit, never across units.
        $this->post(route('containers.update', $box), ['language' => 'en', 'values' => [], 'capacity' => '10', 'capacity_used' => '4', 'capacity_unit' => 'files', 'barcode' => 'RA-000012'])->assertSessionHasNoErrors();
        $this->post(route('containers.update', $other), ['language' => 'en', 'values' => [], 'capacity' => '2', 'capacity_used' => '1', 'capacity_unit' => 'linear-metres'])->assertSessionHasNoErrors();
        $this->assertEquals(['Files' => ['capacity' => 10.0, 'used' => 4.0], 'Linear metres' => ['capacity' => 2.0, 'used' => 1.0]], $storage->capacity($building));
        $this->post(route('containers.update', $box), ['language' => 'en', 'values' => [], 'capacity' => '10'])->assertSessionHasErrors('values');
        $this->post(route('containers.update', $box), ['language' => 'en', 'values' => [], 'humidity_min' => '60', 'humidity_max' => '40'])->assertSessionHasErrors('values');

        // A storage place that keeps or kept containers keeps its record of what came and went.
        $this->actingAs($this->account('Administrator', 'administrator'))->get(route('deletion.confirm', ['places', $shelfB->id]))->assertOk()->assertSee('what came and went would be lost');
    }

    public function test_only_storage_places_keep_containers_one_at_a_time_and_they_stay_apart_from_other_places(): void
    {
        $shelf = $this->storagePlace('Shelf A', 'shelf');
        $other = $this->storagePlace('Shelf B', 'shelf');
        $town = app(PlaceService::class)->create($this->institution, 'Riverside', 'en');
        $box = $this->container('Box 12', $shelf);
        $relations = app(RelationService::class);
        $refused = function (callable $make, string $why) {
            try {
                $make();
                $this->fail("not refused: $why");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($why, $e->getMessage());
            }
        };
        $refused(fn () => $relations->create($box, 'location', $town, [], $this->editor), 'kept in a storage place');
        // A second current location through the relation screen is refused too: a move ends the first.
        $refused(fn () => $relations->create($box, 'location', $other, [], $this->editor), 'kept somewhere already');

        // Storage places are not offered as places records are about, nor listed with them.
        $names = fn ($found) => $found->map(fn ($p) => $p->described('place-name', ['en']))->sort()->values()->all();
        $this->assertSame(['Riverside'], $names($relations->candidates($this->editor, ['place'], 'i', null, [], 'place')));
        $this->assertSame(['Shelf A', 'Shelf B'], $names($relations->candidates($this->editor, ['place'], 'Shelf', null, [], 'location')));
        $this->actingAs($this->editor)->get(route('places.index'))->assertOk()->assertSee('Riverside')->assertDontSee('Shelf A');
    }

    public function test_what_a_unit_is_kept_in_shows_on_its_page_box_list_and_holdings_and_never_to_the_public(): void
    {
        $shelf = $this->storagePlace('Shelf A', 'shelf');
        $box = $this->container('Box 12', $shelf, 'RA-000012');
        $this->actingAs($this->editor)->post(route('relations.store', ['records', $this->file->id]), ['relate' => 'storage|source', 'other' => 'container:'.$box->id])
            ->assertRedirect()->assertSessionHasNoErrors();
        $relation = Relation::whereHas('relationType', fn ($q) => $q->where('notation', 'storage'))->sole();
        $this->assertSame('openricx:isOrWasStoredIn', $relation->ricTerm());
        $this->get(route('records.show', $this->file))->assertOk()->assertSee('Physical storage')->assertSee('Box 12');
        $this->get(route('containers.box-list', $box))->assertOk()->assertSee('RA RPC/1')->assertSee('Minutes, 1902 to 1910')->assertSee('Open.')->assertSee('Riverside Parish Council records');
        $csv = $this->get(route('containers.holdings'))->assertOk()->streamedContent();
        $this->assertStringContainsString('"Box 12",Box,,RA-000012,"Shelf A",', $csv);
        $this->get(route('containers.scan', ['barcode' => 'RA-000012']))->assertRedirect(route('containers.show', $box));
        $this->get(route('containers.scan', ['barcode' => 'nothing']))->assertRedirect()->assertSessionHasErrors('barcode');
        $this->post(route('containers.store'), ['name' => 'Box 14', 'language' => 'en', 'barcode' => 'RA-000012'])->assertSessionHasErrors('container');

        // Staff exports carry the container and where it is; the public's carry nothing of storage.
        $staff = new ExportView($this->editor, true);
        $ead = app(Ead::class)->export($this->fonds->fresh(), $staff);
        $this->assertStringContainsString('<container type="Box">Box 12</container>', $ead);
        $this->assertStringContainsString('<physloc>Shelf A</physloc>', $ead);
        foreach ([false => 'ead2002.xsd', true => 'ead3.xsd'] as $three => $schema) {
            $document = new DOMDocument;
            $document->loadXML(app(Ead::class)->export($this->fonds->fresh(), $staff, (bool) $three));
            $this->assertTrue(@$document->schemaValidate(resource_path("schemas/$schema")), "valid against $schema");
        }
        $this->assertStringContainsString('Box 12', app(DescriptionCsv::class)->export([$this->file->fresh()], $staff));
        $public = new ExportView(null, false, null, $this->institution);
        $this->assertSame([], $public->storage($this->file->fresh()));
    }

    public function test_who_may_see_and_change_storage(): void
    {
        $box = $this->container('Box 12');
        $contributor = $this->account('Contributor', 'contributor');
        $this->actingAs($contributor)->get(route('containers.show', $box))->assertOk()->assertDontSee('Edit description');
        $this->actingAs($contributor)->get(route('containers.create'))->assertForbidden();
        $this->actingAs($contributor)->post(route('containers.move', $box), ['place' => ''])->assertForbidden();
        $translator = $this->account('Translator', 'translator');
        $this->actingAs($translator)->get(route('containers.index'))->assertForbidden();
        $this->actingAs($translator)->get(route('records.show', $this->file))->assertOk()->assertDontSee('Physical storage');
        $this->actingAs($this->editor)->get(route('containers.index'))->assertOk()->assertSee('Box 12');
    }

    public function test_atom_physical_objects_become_containers_with_their_location_as_written_and_again_without_duplicates(): void
    {
        $csv = "legacyId,parentId,title,levelOfDescription,physicalObjectName,physicalObjectLocation,physicalObjectType,culture\n"
            ."1058,,Flower 2,File,Main vault|Box 7,Floor 1|NULL,Box|Map drawer,en\n";
        $import = function () use ($csv) {
            $location = $this->actingAs($this->editor)->post('/imports', ['file' => UploadedFile::fake()->createWithContent('a.csv', $csv), 'format' => 'csv',
                'source' => 'AtoM', 'repository' => $this->holder->id, 'language' => 'en', 'create_terms' => '1'])->assertRedirect()->assertSessionHasNoErrors()->headers->get('Location');
            $import = Import::findOrFail(basename($location));
            $this->post("/imports/{$import->id}/commit");

            return $import->fresh();
        };
        $first = $import();
        $this->assertSame(2, $first->report['counts']['containers']);
        $vault = Container::whereIn('id', Name::where('value', 'Main vault')->select('subject_id'))->sole();
        $this->assertSame('Box', $vault->containerType->anyLabel(['en']));
        $this->assertSame('Floor 1', $vault->described('container-location-as-written', ['en']));
        $drawer = Container::whereIn('id', Name::where('value', 'Box 7')->select('subject_id'))->sole();
        $this->assertSame('Map drawer', $drawer->containerType->anyLabel(['en']));
        $this->assertNull($drawer->described('container-location-as-written', ['en']));
        $this->assertSame(2, Relation::whereHas('relationType', fn ($q) => $q->where('notation', 'storage'))->count());
        $this->assertNotContains('physicalObjectName', array_column(array_filter($first->report['issues'], fn ($i) => $i['kind'] === 'left-out'), 'field'));

        $again = $import();
        $this->assertSame(0, $again->report['counts']['containers']);
        $this->assertSame(2, Container::count());
        $this->assertSame(2, Relation::whereHas('relationType', fn ($q) => $q->where('notation', 'storage'))->count());
    }
}
