<?php

// OpenRiC Workspace - the record set's name-first explorer (roadmap phase 27): its data in Heratio's ExplorerDataService
// shape, from the catalogue's relations and the names proposed from the text (reviewed only, unless the set shows the
// rest), spellings grouped, general words hidden, too-rare names left out, drafts never; the page, the transcript to whoever
// may have the reference copy, the settings for who may describe the set, and names taken in from a CSV.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Api\PublicCatalogue;
use App\Description\AgentService;
use App\Description\InstantiationService;
use App\Description\PlaceService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Discover\ExplorerData;
use App\Models\Agent;
use App\Models\Concept;
use App\Models\ExplorerSetting;
use App\Models\Institution;
use App\Models\Proposal;
use App\Models\RecordResource;
use App\Models\User;
use App\Proposals\ProposalService;
use App\Relations\RelationService;
use App\Support\Vocab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExplorerTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private string $store;

    private Institution $institution;

    private RecordResource $set;

    private RecordResource $first;

    private RecordResource $second;

    private RecordResource $draft;

    private Agent $smuts;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        $this->loadBundledVocabularies();
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->editor = app(AccountService::class)->create($this->institution, 'Editor', 'editor@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->editor, 'editor');
        $records = app(RecordService::class);
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Museum', 'en', 'RM');
        $this->set = $records->create($this->institution, self::LEVEL.'file', 'Funeral scrapbook, 1950', 'en', null, $holder, 'FS');
        $this->first = $records->create($this->institution, self::LEVEL.'item', 'Page 1: A nation mourns', 'en', $this->set, null, 'FS-P01');
        $this->second = $records->create($this->institution, self::LEVEL.'item', 'Page 2: The last salute', 'en', $this->set, null, 'FS-P02');
        $this->draft = $records->create($this->institution, self::LEVEL.'item', 'Page 3: not yet described', 'en', $this->set, null, 'FS-P03');
        $records->describe($this->first, ['scope-and-content' => str_repeat('Cuttings on the funeral. ', 40)], 'en');
        $this->smuts = app(AgentService::class)->create($this->institution, 'person', 'Smuts, Jan Christiaan (1870-1950)', 'en');
        $pretoria = app(PlaceService::class)->create($this->institution, 'Pretoria', 'en');
        $relations = app(RelationService::class);
        foreach ([$this->first, $this->second, $this->draft] as $page) {
            $relations->create($page, 'subject', $this->smuts, [], $this->editor);
        }
        $relations->create($pretoria, 'place', $this->second, [], $this->editor);
        $text = tempnam(sys_get_temp_dir(), 'page').'.txt';
        file_put_contents($text, "THE STAR, SEPTEMBER 12, 1950\nPassing of a statesman.");
        app(InstantiationService::class)->upload($this->first, $text, 'page-01.txt', 'en', 'Transcript of page 1');
        $publication = app(PublicationService::class);
        foreach ([$holder, $this->set, $this->first, $this->second, $this->smuts, $pretoria] as $entity) {
            $publication->apply($entity, 'publish', null, $entity instanceof RecordResource, $this->editor);
        }
        $publication->apply($this->draft, 'withdraw', null, false, $this->editor);
        app(RecordService::class)->describe($this->draft, ['scope-and-content' => 'Draft.'], 'en');

        // Names found in the text: one accepted on each page in two spellings, one waiting for review, one rejected.
        $proposals = app(ProposalService::class);
        $proposals->propose($this->first, $this->editor, null, ['entities' => [['type' => 'ORG', 'value' => 'The Star'], ['type' => 'PERSON', 'value' => 'Ouma', 'confidence' => 0.9]], 'source' => 'test']);
        $proposals->propose($this->second, $this->editor, null, ['entities' => [['type' => 'ORG', 'value' => 'Star'], ['type' => 'PERSON', 'value' => 'Nobody', 'confidence' => 0.2],
            ['type' => 'GPE', 'value' => 'Atlantis']], 'source' => 'test']);
        $status = fn (string $s) => Concept::where('iri', Vocab::ASSERTION_STATUSES.$s)->value('id');
        Proposal::whereIn('value', ['The Star', 'Star'])->update(['status_id' => $status('accepted')]);
        Proposal::where('value', 'Atlantis')->update(['status_id' => $status('rejected')]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->store)) {
            exec('rm -rf '.escapeshellarg($this->store));
        }
        parent::tearDown();
    }

    private function data(?ExplorerSetting $settings = null): array
    {
        return app(ExplorerData::class)->build(new PublicCatalogue($this->institution), $this->set->fresh(), $settings);
    }

    private function entity(array $data, string $id): ?array
    {
        return collect($data['entities'])->firstWhere('id', $id);
    }

    public function test_the_data_has_heratios_shape_from_the_relations_and_the_reviewed_names_and_never_a_draft(): void
    {
        $data = $this->data();
        $this->assertSame('Funeral scrapbook, 1950', $data['site']['title']);
        $ids = array_column($data['records'], 'id');
        $this->assertContains($this->first->id, $ids);
        $this->assertNotContains($this->draft->id, $ids);
        $first = collect($data['records'])->firstWhere('id', $this->first->id);
        $this->assertSame(['id', 'title', 'ref', 'date', 'url', 'textUrl', 'text', 'ents'], array_keys($first));
        $this->assertSame(603, mb_strlen($first['text']));
        $this->assertStringEndsWith('...', $first['text']);

        // The authority record, on the two published pages only, with its page; the place.
        $smuts = $this->entity($data, 'name:smutsjanchristiaan18701950');
        $this->assertSame(['id', 'type', 'name', 'variants', 'recs', 'url'], array_keys($smuts));
        $this->assertEqualsCanonicalizing([$this->first->id, $this->second->id], $smuts['recs']);
        $this->assertSame(route('discover.agent', $this->smuts->id), $smuts['url']);
        $this->assertSame([$this->second->id], $this->entity($data, 'place:pretoria')['recs']);

        // "The Star" and "Star" are one name, accepted; the waiting and the rejected names are not there.
        $star = $this->entity($data, 'name:star');
        $this->assertCount(2, $star['recs']);
        $this->assertEqualsCanonicalizing(['The Star', 'Star'], $star['variants']);
        $this->assertNull($this->entity($data, 'name:ouma'));
        $this->assertNull($this->entity($data, 'place:atlantis'));
        $this->assertContains('name:star', collect($data['records'])->firstWhere('id', $this->second->id)['ents']);
    }

    public function test_the_settings_show_unreviewed_names_above_a_confidence_hide_general_words_and_leave_out_rare_names(): void
    {
        $settings = new ExplorerSetting(['institution_id' => $this->institution->id, 'record_resource_id' => $this->set->id, 'min_mentions' => 1, 'unreviewed' => true, 'min_confidence' => 0.5]);
        $data = $this->data($settings);
        $this->assertNotNull($this->entity($data, 'name:ouma'));
        $this->assertNull($this->entity($data, 'name:nobody'));
        $this->assertNull($this->entity($data, 'place:atlantis'));

        $settings->fill(['min_confidence' => null, 'hide' => "pretoria\nSomewhere", 'min_mentions' => 2]);
        $data = $this->data($settings);
        $this->assertNull($this->entity($data, 'name:nobody'));
        $this->assertNull($this->entity($data, 'place:pretoria'));
        $this->assertNotNull($this->entity($data, 'name:star'));

        $settings->fill(['min_mentions' => 1]);
        $data = $this->data($settings);
        $this->assertTrue($this->entity($data, 'place:pretoria')['hidden']);
        $this->assertNotNull($this->entity($data, 'name:nobody'));
    }

    public function test_the_page_and_the_transcript_are_public_and_a_draft_set_is_not(): void
    {
        $page = $this->get(route('discover.explorer', $this->set->id))->assertOk()->assertSee('Funeral scrapbook, 1950')->assertSee('id="explorer-data"', false)
            ->assertDontSee('Page 3: not yet described')->assertDontSee('Explorer settings (staff)');
        $first = collect(json_decode(preg_replace('/^.*id="explorer-data">(.*?)<\/script>.*$/s', '$1', $page->getContent()), true)['records'])->firstWhere('id', $this->first->id);
        $this->get($first['textUrl'])->assertOk()->assertHeader('Content-Type', 'text/plain; charset=utf-8')->assertSee('Passing of a statesman.');
        $this->get(route('discover.explorer', $this->draft->id))->assertNotFound();
        $this->get(route('discover.record', $this->set->id))->assertSee(route('discover.explorer', $this->set->id));
        $this->get(route('discover.record', $this->first->id))->assertDontSee(route('discover.explorer', $this->first->id));
    }

    public function test_who_may_describe_the_set_keeps_its_settings_and_a_reader_may_not(): void
    {
        $this->actingAs($this->editor)->get(route('discover.explorer', $this->set->id))->assertSee('Explorer settings (staff)');
        $this->actingAs($this->editor)->post(route('explorer.settings', $this->set->id), ['hide' => 'Pretoria', 'min_mentions' => 2, 'unreviewed' => '1', 'min_confidence' => '0.4'])
            ->assertRedirect(route('discover.explorer', $this->set->id));
        $saved = ExplorerSetting::for($this->set);
        $this->assertSame(['Pretoria', 2, true, 0.4], [$saved->hide, $saved->min_mentions, $saved->unreviewed, $saved->min_confidence]);

        // An account with no role may describe nothing.
        $reader = app(AccountService::class)->create($this->institution, 'Reader', 'reader@example.org', 'correct horse battery staple');
        $this->actingAs($reader)->post(route('explorer.settings', $this->set->id), ['min_mentions' => 1])->assertForbidden();
    }

    public function test_names_are_taken_in_from_a_csv_as_proposals_once(): void
    {
        $csv = tempnam(sys_get_temp_dir(), 'names');
        file_put_contents($csv, "record,type,value,confidence\nFS-P01,PERSON,Isie Smuts,0.8\n{$this->second->iri},LOC,Groote Kerk,\nFS-P09,PERSON,Not here,\nFS-P02,DATE,1950,\n");
        $this->artisan('workspace:explorer:names', ['file' => $csv, '--as' => 'editor@example.org', '--source' => 'qwen3'])
            ->expectsOutputToContain('2 names proposed, 0 already there, 2 not taken in.')->assertSuccessful();
        $this->assertSame('place', Proposal::where('value', 'Groote Kerk')->first()->kind->notation);
        $this->assertSame(0.8, (float) Proposal::where('value', 'Isie Smuts')->value('confidence'));
        $this->artisan('workspace:explorer:names', ['file' => $csv, '--as' => 'editor@example.org'])->expectsOutputToContain('0 names proposed, 2 already there')->assertSuccessful();
    }
}
