<?php

// OpenRiC Workspace - the clipboard, as Heratio's: gather, remove, save under an ID, load, export; only what the reader may see.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\AgentService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClipboardTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private Institution $institution;

    private User $editor;

    private RecordResource $published;

    private RecordResource $draft;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        $this->institution = Institution::create(['name' => 'Riverside Parish Council Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $accounts = app(AccountService::class);
        $this->editor = $accounts->create($this->institution, 'An Editor', 'editor@example.org', 'correct horse battery staple');
        $accounts->grantTemplateRole($this->editor, 'editor');
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $records = app(RecordService::class);
        $this->published = $records->create($this->institution, self::LEVEL.'fonds', 'Council minutes', 'en', null, $holder, 'CM');
        $this->draft = $records->create($this->institution, self::LEVEL.'fonds', 'Closed personnel files', 'en', null, $holder, 'PF');
        foreach ([$holder, $this->published] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->editor);
        }
    }

    public function test_a_signed_in_reader_gathers_saves_loads_and_exports(): void
    {
        $agent = app(AgentService::class)->create($this->institution, 'person', 'Botha, Jan', 'en');
        $this->actingAs($this->editor)->get("/records/{$this->published->id}")->assertSee('Add to clipboard');
        $this->actingAs($this->editor)->post('/clipboard/add', ['type' => 'records', 'id' => $this->published->id])->assertRedirect();
        $this->actingAs($this->editor)->post('/clipboard/add', ['type' => 'records', 'id' => $this->draft->id]);
        $this->actingAs($this->editor)->post('/clipboard/add', ['type' => 'agents', 'id' => $agent->id]);
        $this->actingAs($this->editor)->get("/records/{$this->published->id}")->assertSee('On the clipboard: remove')->assertSee('Clipboard (3)');
        $this->actingAs($this->editor)->get('/clipboard')->assertOk()->assertSee('Archival descriptions (2)')->assertSee('Closed personnel files')->assertSee('Botha, Jan');

        $csv = $this->actingAs($this->editor)->get('/clipboard/export')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=utf-8')->getContent();
        $this->assertStringStartsWith("Name,Type,ID,URL\n", $csv);
        $this->assertStringContainsString('"Botha, Jan",agents,'.$agent->id, $csv);

        $this->actingAs($this->editor)->post('/clipboard/save')->assertRedirect(route('clipboard.index'))->assertSessionHas('clipboard_id');
        $id = session('clipboard_id');
        $this->assertMatchesRegularExpression('/^\d{7}$/', $id);
        $this->actingAs($this->editor)->post('/clipboard/remove', ['type' => 'agents', 'id' => $agent->id]);
        $this->actingAs($this->editor)->post('/clipboard/clear');
        $this->actingAs($this->editor)->post('/clipboard/load', ['clipboard_id' => $id, 'mode' => 'replace'])->assertSessionHas('status', "Clipboard $id loaded: 3 entries.");
        $this->actingAs($this->editor)->get('/clipboard')->assertSee('Botha, Jan');
        $this->actingAs($this->editor)->post('/clipboard/load', ['clipboard_id' => '0000000'])->assertSessionHasErrors('clipboard_id');

        // A new session of the same reader starts with their latest saved clipboard.
        $this->flushSession();
        $this->actingAs($this->editor)->get('/clipboard')->assertSee('Council minutes')->assertSee('Botha, Jan');
    }

    public function test_a_visitor_gathers_only_what_the_public_catalogue_shows(): void
    {
        $this->get("/discover/records/{$this->published->id}")->assertSee('Add to clipboard');
        $this->post('/clipboard/add', ['type' => 'records', 'id' => $this->published->id])->assertSessionHas('status', 'Added to the clipboard.');
        $this->post('/clipboard/add', ['type' => 'records', 'id' => $this->draft->id])->assertSessionHas('status', 'That cannot go on the clipboard.');
        $page = $this->get('/clipboard')->assertOk()->assertSee('Council minutes')->assertDontSee('Closed personnel files');
        $page->assertSee(route('discover.record', $this->published->id), false);

        // A clipboard saved by staff loads for a visitor without what the public may not see.
        $this->actingAs($this->editor)->post('/clipboard/add', ['type' => 'records', 'id' => $this->draft->id]);
        $this->actingAs($this->editor)->post('/clipboard/save');
        $id = session('clipboard_id');
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post('/clipboard/load', ['clipboard_id' => $id]);
        $this->get('/clipboard')->assertDontSee('Closed personnel files');
        $this->post('/clipboard/add', ['type' => 'records', 'id' => 'not-a-uuid'])->assertSessionHasErrors('id');
    }
}
