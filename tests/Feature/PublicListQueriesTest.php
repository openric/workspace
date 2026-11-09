<?php

// OpenRiC Workspace - the public list decides what may be read without asking the database once per record, found by
// the capacity test (roadmap R1): the catalogue home had made 10,702 queries for the 980 demonstration records.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\AccessControl;
use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Api\PublicCatalogue;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\Permission;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\Role;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicListQueriesTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private Institution $institution;

    private User $editor;

    private Repository $holder;

    private RecordResource $fonds;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->editor = app(AccountService::class)->create($this->institution, 'Editor', 'editor@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->editor, 'editor');
        $this->holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $this->fonds = app(RecordService::class)->create($this->institution, self::LEVEL.'fonds', 'Riverside Parish Council records', 'en', null, $this->holder, 'RPC');
        foreach ([$this->holder, $this->fonds] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->editor);
        }
    }

    private function items(int $from, int $to): void
    {
        for ($n = $from; $n <= $to; $n++) {
            $item = app(RecordService::class)->create($this->institution, self::LEVEL.'item', "Letter $n", 'en', $this->fonds, null, (string) $n);
            app(PublicationService::class)->apply($item, 'publish', null, false, $this->editor);
        }
    }

    /** @return array{0: int, 1: int} how many records the public list holds, and how many queries it took */
    private function list(): array
    {
        app()->forgetScopedInstances();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $records = (new PublicCatalogue($this->institution))->visible('records');
        DB::disableQueryLog();

        return [$records->count(), count(DB::getQueryLog())];
    }

    public function test_the_public_list_asks_as_many_queries_for_twenty_five_records_as_for_five(): void
    {
        $this->items(1, 4);
        // The first asks once for what the classifications and community protocols keep for the whole process.
        $this->list();
        [$few, $queries] = $this->list();
        $this->items(5, 24);
        [$many, $more] = $this->list();

        $this->assertSame([5, 25], [$few, $many]);
        $this->assertSame($queries, $more, 'each record\'s checks are answered from rows asked once');
    }

    public function test_a_denial_on_the_fonds_still_hides_what_is_inside_it_and_is_seen_at_once(): void
    {
        $this->items(1, 2);
        $this->assertSame(3, $this->list()[0]);

        // The Public role is denied reading the fonds: it and both letters inside it leave the list, in the same request.
        $access = app(AccessControl::class);
        $letter = RecordResource::where('parent_id', $this->fonds->id)->firstOrFail();
        $this->assertTrue($access->allows(null, 'read', 'description', $letter, $this->institution));
        $public = Role::where('institution_id', $this->institution->id)->whereHas('template', fn ($q) => $q->where('iri', Vocab::role('public')))->sole();
        Permission::create(['institution_id' => $this->institution->id, 'role_id' => $public->id, 'subject_table' => 'record_resources', 'subject_id' => $this->fonds->id, 'is_grant' => false,
            'kind_id' => Concept::where('iri', Vocab::kind('description'))->value('id'), 'action_id' => Concept::where('iri', Vocab::permission('read'))->value('id')]);
        $this->assertFalse($access->allows(null, 'read', 'description', $letter, $this->institution), 'the cached rows were forgotten when the denial was saved');

        $this->assertSame(0, $this->list()[0]);
    }
}
