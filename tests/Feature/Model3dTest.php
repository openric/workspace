<?php

// OpenRiC Workspace - 3D objects (roadmap phase 21, 3D only), after Heratio's ahg-3d-model: an STL, an OBJ in a ZIP and
// a GLB each get a GLB viewing copy and a thumbnail from Blender (where it is installed) and what they are made of; a
// Gaussian splat is recognised; a model is shown as its institution and then the model set it; a hotspot is a draft
// until the record is published, then is shown in the reader's language and is an annotation at a point in the model's
// IIIF manifest; a saved view is its reader's own unless published by who may change the description; the model's
// significant properties are in PREMIS; another institution's model does not exist.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\InstantiationService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Exchange\Premis;
use App\Files\Models3d;
use App\Files\ModelViews;
use App\Jobs\MakeTurntable;
use App\Models\Instantiation;
use App\Models\Institution;
use App\Models\ModelHotspot;
use App\Models\RecordResource;
use App\Models\User;
use App\Search\SearchIndex;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use ZipArchive;

class Model3dTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private Institution $institution;

    private User $editor;

    private User $reader;

    private RecordResource $item;

    private string $store;

    private string $work;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-21-'.bin2hex(random_bytes(6));
        $this->work = $this->store.'-in';
        mkdir($this->work, 0700, true);
        config(['workspace.object_store' => $this->store]);
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Museum', 'base_iri' => 'https://museum.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $accounts = app(AccountService::class);
        $this->editor = $accounts->create($this->institution, 'Editor', 'editor@museum.example.org', 'correct horse battery staple');
        $accounts->grantTemplateRole($this->editor, 'editor');
        $this->reader = $accounts->create($this->institution, 'Reader', 'reader@museum.example.org', 'correct horse battery staple');
        $this->actingAs($this->editor);
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Museum', 'en', 'RM');
        $this->item = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'A carved box', 'en', null, $holder, 'B1');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->store).' '.escapeshellarg($this->work));
        parent::tearDown();
    }

    /** An ASCII STL of a unit cube: 12 triangles, 36 vertices. */
    private function cube(): string
    {
        $v = [[0, 0, 0], [1, 0, 0], [1, 1, 0], [0, 1, 0], [0, 0, 1], [1, 0, 1], [1, 1, 1], [0, 1, 1]];
        $faces = [[0, 2, 1], [0, 3, 2], [4, 5, 6], [4, 6, 7], [0, 1, 5], [0, 5, 4], [1, 2, 6], [1, 6, 5], [2, 3, 7], [2, 7, 6], [3, 0, 4], [3, 4, 7]];
        $out = "solid cube\n";
        foreach ($faces as $f) {
            $out .= "facet normal 0 0 0\n outer loop\n".implode('', array_map(fn ($i) => '  vertex '.implode(' ', $v[$i])."\n", $f))." endloop\nendfacet\n";
        }

        return $out."endsolid cube\n";
    }

    private function upload(string $name, string $content): Instantiation
    {
        file_put_contents($path = $this->work.'/'.$name, $content);

        return app(InstantiationService::class)->upload($this->item, $path, $name, 'en', 'The box, scanned', null, 'online', $this->editor)->fresh(['files.use']);
    }

    private function blender(): void
    {
        if (app(Models3d::class)->blender() === null) {
            $this->markTestSkipped('Blender is not on this machine');
        }
    }

    public function test_an_stl_and_an_obj_in_a_zip_become_glb_models_with_thumbnails_and_what_they_are_made_of(): void
    {
        $this->blender();
        $stl = $this->upload('box.stl', $this->cube());
        $this->assertSame('model/gltf-binary', $stl->file('reference')?->mime_type, 'a GLB made by Blender');
        $this->assertSame('image/png', $stl->file('thumbnail')?->mime_type, 'a thumbnail rendered by Blender');
        $this->assertSame(['stl', 12], [$stl->file('master')->model_properties['format'], $stl->file('master')->model_properties['face_count'] ?? null]);
        $this->assertContains('3D faces', array_keys(Premis::modelProperties($stl->file('master'))));

        $zip = new ZipArchive;
        $zip->open($this->work.'/box.zip', ZipArchive::CREATE);
        $zip->addFromString('box/box.obj', "mtllib box.mtl\nv 0 0 0\nv 1 0 0\nv 1 1 0\nv 0 1 0\nusemtl wood\nf 1 2 3\nf 1 3 4\n");
        $zip->addFromString('box/box.mtl', "newmtl wood\nKd 0.6 0.4 0.2\n");
        $zip->close();
        $obj = $this->upload('box.zip', (string) file_get_contents($this->work.'/box.zip'));
        $this->assertSame('model/gltf-binary', $obj->file('reference')?->mime_type, 'the OBJ the ZIP holds, with its materials');
        $this->assertSame(['zip', 2], [$obj->file('master')->model_properties['format'], $obj->file('master')->model_properties['face_count'] ?? null]);
    }

    public function test_a_gaussian_splat_is_recognised_and_not_converted(): void
    {
        $ply = "ply\nformat binary_little_endian 1.0\nelement vertex 1\nproperty float x\nproperty float f_dc_0\nend_header\n".pack('g2', 0, 0);
        file_put_contents($this->work.'/scene.ply', $ply);
        $this->assertSame('splat', app(Models3d::class)->format($this->work.'/scene.ply', 'scene.ply', 'application/octet-stream'));
        file_put_contents($this->work.'/mesh.ply', "ply\nformat ascii 1.0\nelement vertex 3\nproperty float x\nend_header\n0\n1\n2\n");
        $this->assertSame('ply', app(Models3d::class)->format($this->work.'/mesh.ply', 'mesh.ply', 'text/plain'));
        $this->assertNull(app(Models3d::class)->format($this->work.'/mesh.ply', 'notes.txt', 'text/plain'));
    }

    public function test_a_model_is_shown_as_its_institution_and_then_the_model_set_it(): void
    {
        $model = $this->model();
        $this->assertSame(['0deg 75deg 105%', '#f5f5f5', 'floor'], [($s = app(ModelViews::class)->settings($model))['camera_orbit'], $s['background_color'], $s['ar_placement']]);
        $admin = app(AccountService::class)->create($this->institution, 'Admin', 'admin@museum.example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($admin, 'administrator');
        $this->actingAs($admin)->post(route('models.defaults.save'), ['background_color' => '#10373e', 'auto_rotate' => '1'])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->post(route('models.settings', $model), ['background_color' => '#ffffff', 'ar_placement' => 'wall', 'ar_enabled' => '1'])->assertSessionHasNoErrors();
        $s = app(ModelViews::class)->settings($model);
        $this->assertSame(['#ffffff', true, 'wall', true], [$s['background_color'], $s['auto_rotate'], $s['ar_placement'], $s['ar_enabled']], 'the model over the institution over Heratio\'s defaults');
        $this->actingAs($this->editor)->post(route('models.settings', $model), ['background_color' => 'red', 'ar_placement' => 'ceiling'])->assertSessionHasErrors(['background_color', 'ar_placement']);
        $this->actingAs($this->editor)->get(route('models.edit', $model))->assertOk()->assertSee('How it is shown')->assertSee('ar-placement="wall"', false);
    }

    public function test_a_hotspot_is_a_draft_until_the_record_is_published_then_shown_in_the_readers_language_and_in_iiif(): void
    {
        $model = $this->model();
        $this->actingAs($this->editor)->post(route('models.hotspots.store', $model), ['type' => 'damage', 'language' => 'en', 'title' => 'A crack', 'description' => 'Along the lid.',
            'position_x' => 0.5, 'position_y' => 1, 'position_z' => 0.25, 'normal_y' => 1])->assertSessionHasNoErrors();
        $hotspot = ModelHotspot::sole();
        $this->actingAs($this->editor)->post(route('models.hotspots.update', [$model, $hotspot]), ['type' => 'damage', 'language' => 'af', 'title' => "'n Kraak"])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->post(route('models.hotspots.store', $model), ['type' => 'nonsense', 'language' => 'en', 'title' => 'x', 'position_x' => 0, 'position_y' => 0, 'position_z' => 0])
            ->assertSessionHasErrors('type');
        $this->assertCount(1, app(ModelViews::class)->hotspots($model, true, ['en']));
        $this->assertCount(0, app(ModelViews::class)->hotspots($model, false, ['en']), 'a draft is not public');

        foreach ([$this->item, $model] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->editor);
        }
        app(SearchIndex::class)->flush();
        $this->assertSame("'n Kraak", app(ModelViews::class)->hotspots($model, false, ['af', 'en'])->sole()['text']->title);
        auth()->logout();
        $this->get(route('discover.models.embed', [$model, 'lang' => 'en']))->assertOk()->assertSee('A crack')->assertSee('data-position="0.5m 1m 0.25m"', false);
        $manifest = $this->getJson(route('iiif.model3d', $model))->assertOk()->json();
        $this->assertSame('Model', $manifest['items'][0]['items'][0]['items'][0]['body']['type']);
        $annotation = $manifest['annotations'][0]['items'][0];
        $this->assertEquals(['PointSelector', 0.5, 1.0, 0.25], [$annotation['target']['selector']['type'], $annotation['target']['selector']['x'], $annotation['target']['selector']['y'], $annotation['target']['selector']['z']]);
        $this->assertEqualsCanonicalizing(['en', 'af'], array_column($annotation['body'], 'language'));

        app(PublicationService::class)->apply($model, 'withdraw', null, false, $this->editor);
        $this->assertCount(0, app(ModelViews::class)->hotspots($model->fresh(), false, ['en']), 'withdrawn with its instantiation');
    }

    public function test_a_saved_view_is_its_readers_own_unless_published_by_staff(): void
    {
        $model = $this->model();
        app(PublicationService::class)->apply($this->item, 'publish', null, false, $this->editor);
        $this->actingAs($this->reader)->postJson(route('models.bookmarks.store', $model), ['name' => 'Lid', 'camera_orbit' => '0deg 30deg 2m', 'is_public' => true])->assertCreated();
        $this->actingAs($this->editor)->postJson(route('models.bookmarks.store', $model), ['name' => 'Front', 'camera_orbit' => '0deg 75deg 105%', 'is_public' => true])->assertCreated();
        $this->assertSame([['Front', true], ['Lid', false]], collect($this->actingAs($this->reader)->getJson(route('models.bookmarks', $model))->json('bookmarks'))->map(fn ($b) => [$b['name'], $b['is_public']])->all(),
            'a reader cannot publish a view');
        $this->assertSame(['Front'], array_column($this->actingAs($this->editor)->getJson(route('models.bookmarks', $model))->json('bookmarks'), 'name'), 'nor see another reader\'s');
        auth()->logout();
        $this->postJson(route('models.bookmarks.store', $model), ['name' => 'x', 'camera_orbit' => 'auto auto auto'])->assertUnauthorized();
    }

    public function test_a_turntable_is_made_on_the_files_queue_and_another_institutions_model_does_not_exist(): void
    {
        $model = $this->model();
        Queue::fake();
        $this->actingAs($this->editor)->post(route('models.turntable', $model))->assertRedirect();
        Queue::assertPushedOn('files', MakeTurntable::class);

        $other = Institution::create(['name' => 'Hill Archives', 'base_iri' => 'https://hill.example.org']);
        app(RoleService::class)->seedDefaults($other);
        $theirs = app(AccountService::class)->create($other, 'Theirs', 'admin@hill.example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($theirs, 'administrator');
        $this->actingAs($theirs)->get(route('models.edit', $model))->assertNotFound();
        $this->actingAs($theirs)->getJson(route('models.bookmarks', $model))->assertNotFound();
        $this->actingAs($this->reader)->get(route('models.edit', $model))->assertForbidden();
        auth()->logout();
        $this->get(route('discover.models.embed', $model))->assertNotFound();
        $this->get(route('iiif.model3d', $model))->assertNotFound();
    }

    /** A GLB model, uploaded as it is (its viewing copy is the file itself, with or without Blender). */
    private function model(): Instantiation
    {
        $json = json_encode(['asset' => ['version' => '2.0'], 'scenes' => [['nodes' => [0]]], 'nodes' => [['mesh' => 0]], 'meshes' => [['primitives' => [['attributes' => ['POSITION' => 0]]]]],
            'accessors' => [['bufferView' => 0, 'componentType' => 5126, 'count' => 3, 'type' => 'VEC3', 'min' => [0, 0, 0], 'max' => [1, 1, 0]]],
            'bufferViews' => [['buffer' => 0, 'byteLength' => 36]], 'buffers' => [['byteLength' => 36]]]);
        $json = str_pad($json, (int) ceil(strlen($json) / 4) * 4, ' ');
        $bin = pack('g9', 0, 0, 0, 1, 0, 0, 1, 1, 0);
        $glb = 'glTF'.pack('VV', 2, 12 + 8 + strlen($json) + 8 + strlen($bin)).pack('V', strlen($json)).'JSON'.$json.pack('V', strlen($bin))."BIN\0".$bin;

        return $this->upload('box.glb', $glb);
    }
}
