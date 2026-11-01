<?php

// OpenRiC Workspace - the analysis of research: snapshots, the timeline and map, field alerts, the RiC bridge, source
// assessments, entity resolution, searches across fonds, research reports and their peer review, and the use of the
// research services (roadmap phase 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\AgentService;
use App\Description\DateService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Mail\ResearchMail;
use App\Models\Activity;
use App\Models\Bibliography;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\Relation;
use App\Models\ResearchCrossFondsQuery;
use App\Models\ResearchEntityResolution;
use App\Models\ResearchFieldAlert;
use App\Models\ResearchProject;
use App\Models\ResearchReport;
use App\Models\ResearchSnapshot;
use App\Models\User;
use App\Research\Collections;
use App\Research\Projects;
use App\Research\RicBridge;
use App\Search\SearchIndex;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ResearchAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private string $store;

    private Institution $institution;

    private User $admin;

    private User $reader;

    private User $other;

    private RecordResource $fonds;

    private RecordResource $letter;

    private RecordResource $minutes;

    private ResearchProject $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        Mail::fake();
        $this->travelTo('2026-11-07 09:00');
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->admin = app(AccountService::class)->create($this->institution, 'Admin', 'admin@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->admin, 'administrator');
        $this->reader = app(AccountService::class)->create($this->institution, 'Rita', 'rita@example.org', 'correct horse battery staple');
        $this->other = app(AccountService::class)->create($this->institution, 'Olu', 'olu@example.org', 'correct horse battery staple');
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $this->fonds = app(RecordService::class)->create($this->institution, self::LEVEL.'fonds', 'Parish council records', 'en', null, $holder, 'PC');
        $this->letter = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Letter about the parish bridge', 'en', $this->fonds, null, '1');
        $this->minutes = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Minutes on the bridge tolls', 'en', $this->fonds, null, '2');
        app(DateService::class)->add($this->letter, ['property' => 'creation', 'normalized' => '1898-05-03']);
        $other = app(RecordService::class)->create($this->institution, self::LEVEL.'fonds', 'Harbour board records', 'en', null, $holder, 'HB');
        app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Bridge over the harbour mouth', 'en', $other, null, '1');
        foreach ([$holder, $this->fonds, $this->letter, $this->minutes] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->admin);
        }
        app(SearchIndex::class)->flush();
        $this->project = app(Projects::class)->create($this->reader, ['title' => 'The parish bridge', 'visibility' => 'collaborators', 'start_date' => '2026-01-01', 'expected_end_date' => '2027-06-30',
            'description' => 'How the iron bridge was paid for.']);
        $member = app(Projects::class)->invite($this->reader, $this->project, 'olu@example.org', 'contributor');
        app(Projects::class)->answer($this->other, $member->fresh(['status']), true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->store)) {
            exec('rm -rf '.escapeshellarg($this->store));
        }
        parent::tearDown();
    }

    public function test_snapshots_keep_the_records_as_they_were_and_compare_them(): void
    {
        $collection = app(Collections::class)->save($this->reader, ['name' => 'Bridge sources']);
        app(Collections::class)->add($this->reader, $collection, $this->letter);
        app(Projects::class)->assign($this->reader, $collection, $this->project);
        $this->actingAs($this->admin)->post(route('research.analysis.snapshots.step', [$this->project, 'take']), [])->assertSessionHasErrors('research');
        $this->actingAs($this->other)->post(route('research.analysis.snapshots.step', [$this->project, 'take']), ['title' => 'Before'])->assertSessionHasNoErrors();
        $before = ResearchSnapshot::where('title', 'Before')->sole();
        $this->assertSame([1, ['public' => 1, 'embargoed' => 0]], [$before->item_count, $before->rights_state]);
        $this->assertSame(64, strlen($before->hash_sha256));

        // A record changed, another added: the next snapshot shows what changed and what came.
        app(RecordService::class)->describe($this->letter, ['scope-and-content' => 'Names the engineer.'], 'en');
        app(Collections::class)->add($this->reader, $collection, $this->minutes);
        $this->actingAs($this->other)->post(route('research.analysis.snapshots.step', [$this->project, 'take']), ['title' => 'After'])->assertSessionHasNoErrors();
        $after = ResearchSnapshot::where('title', 'After')->sole();
        $this->assertNotSame($before->hash_sha256, $after->hash_sha256);
        $this->actingAs($this->reader)->get(route('research.analysis.snapshot', [$after, 'with' => $before->id]))->assertOk()
            ->assertSeeInOrder(['Came', 'Minutes on the bridge tolls', 'Went', 'Changed', 'Letter about the parish bridge']);

        $this->actingAs($this->other)->post(route('research.analysis.snapshots.step', [$this->project, 'freeze', $before]))->assertSessionHasNoErrors();
        $this->assertSame('snapshot:'.substr($before->hash_sha256, 0, 16), $before->fresh()->citation_id);
        $this->actingAs($this->other)->post(route('research.analysis.snapshots.step', [$this->project, 'retake', $before]))->assertSessionHasErrors('research');
        $this->assertSame(1, $before->items()->count());
    }

    public function test_the_timeline_map_and_field_alerts(): void
    {
        $collection = app(Collections::class)->save($this->reader, ['name' => 'Bridge sources']);
        app(Collections::class)->add($this->reader, $collection, $this->letter);
        app(Collections::class)->add($this->reader, $collection, $this->minutes);
        app(Projects::class)->assign($this->reader, $collection, $this->project);
        $plot = fn (string $kind, array $data) => $this->actingAs($this->other)->post(route('research.analysis.plot', [$this->project, $kind]), $data);
        $plot('from-collection', ['collection_id' => $collection->id])->assertSessionHas('status', '1 event was added.');
        $plot('from-collection', ['collection_id' => $collection->id])->assertSessionHas('status', 'No event was added.');
        $plot('events', ['label' => 'Toll abolished', 'date_start' => '1910-01-01', 'date_end' => '1909-01-01'])->assertSessionHasErrors('research');
        $plot('events', ['label' => 'Toll abolished', 'date_start' => '1910-01-01', 'color' => '#aa3300'])->assertSessionHasNoErrors();
        $events = $this->actingAs($this->reader)->getJson(route('research.analysis.timeline', $this->project))->assertOk()->json();
        $this->assertSame([['Letter about the parish bridge', '1898-05-03', 'creation'], ['Toll abolished', '1910-01-01', 'event']], array_map(fn ($e) => [$e['label'], $e['start'], $e['type']], $events));

        $plot('points', ['label' => 'The bridge', 'latitude' => 95, 'longitude' => 18.4])->assertSessionHasErrors('research');
        $plot('points', ['label' => 'The bridge', 'latitude' => -33.9249, 'longitude' => 18.4241, 'place_name' => 'Riverside'])->assertSessionHasNoErrors();
        $geo = $this->actingAs($this->reader)->getJson(route('research.analysis.map', $this->project))->assertOk()->json();
        $this->assertSame([18.4241, -33.9249], $geo['features'][0]['geometry']['coordinates']);

        // Field alerts: the DOIs of the project's bibliographies watched, and Crossref and OpenAlex asked about them.
        $this->actingAs($this->reader)->post(route('research.bibliographies.store'), ['name' => 'Thesis sources', 'citation_style' => 'chicago'])->assertSessionHasNoErrors();
        $bibliography = Bibliography::sole();
        $this->actingAs($this->reader)->post(route('research.bibliographies.step', [$bibliography, 'entry']), ['entry_type' => 'article', 'title' => 'Bridges of the Cape', 'doi' => 'https://doi.org/10.1234/Bridges.1'])
            ->assertSessionHasNoErrors();
        app(Projects::class)->assign($this->reader, $bibliography, $this->project);
        $this->actingAs($this->other)->post(route('research.analysis.alerts.step', [$this->project, 'seed']))->assertSessionHas('status', '1 work is now watched.');
        $this->actingAs($this->other)->post(route('research.analysis.alerts.step', [$this->project, 'watch']), ['doi' => 'not a doi'])->assertSessionHasErrors('research');
        Http::fake([
            'api.crossref.org/works/*' => Http::response(['message' => ['title' => ['Bridges of the Cape'], 'updated-by' => [['type' => 'correction', 'DOI' => '10.1234/bridges.1.corr']]]]),
            'api.openalex.org/works/doi:*' => Http::response(['id' => 'https://openalex.org/W123', 'title' => 'Bridges of the Cape', 'is_retracted' => true]),
            'api.openalex.org/works?*' => Http::response(['results' => [['title' => 'Iron bridges revisited', 'doi' => 'https://doi.org/10.5555/iron']]]),
        ]);
        $this->actingAs($this->other)->post(route('research.analysis.alerts.step', [$this->project, 'scan']))->assertSessionHas('status', '3 alerts were raised.');
        $this->actingAs($this->other)->post(route('research.analysis.alerts.step', [$this->project, 'scan']))->assertSessionHas('status', 'Nothing new.');
        $this->assertSame(['new-related', 'retraction', 'update'], ResearchFieldAlert::with('alertType')->get()->pluck('alertType.notation')->sort()->values()->all());
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.crossref.org/works/10.1234%2Fbridges.1'));
        $this->actingAs($this->reader)->get(route('research.analysis.alerts', $this->project))->assertOk()->assertSee('Iron bridges revisited')->assertSee('This cited work is retracted.');
        $this->actingAs($this->reader)->post(route('research.analysis.alerts.step', [$this->project, 'read']))->assertSessionHasNoErrors();
        $this->assertSame(0, ResearchFieldAlert::where('is_read', false)->count());
    }

    public function test_assessments_entity_resolution_search_across_fonds_and_the_ric_bridge(): void
    {
        $this->actingAs($this->reader)->post(route('research.assess.store', $this->letter), ['source_kind' => 'primary', 'source_form' => 'scan', 'completeness' => 'partial', 'trust_score' => 101])
            ->assertSessionHasErrors();
        $this->actingAs($this->reader)->post(route('research.assess.store', $this->letter), ['source_kind' => 'primary', 'source_form' => 'scan', 'completeness' => 'partial', 'trust_score' => 80,
            'rationale' => 'Written by the clerk at the time.'])->assertSessionHasNoErrors();
        $this->actingAs($this->other)->get(route('research.assess', $this->letter))->assertOk()->assertSee('Written by the clerk at the time.')->assertSee('trust 80 of 100');

        $smit = app(AgentService::class)->create($this->institution, 'person', 'Jan Smit', 'en', null, $this->admin);
        $smith = app(AgentService::class)->create($this->institution, 'person', 'J. Smith', 'en', null, $this->admin);
        $this->actingAs($this->reader)->post(route('research.resolutions.step', ['propose']), ['a' => $smit->iri, 'b' => $smit->iri])->assertSessionHasErrors('research');
        $this->actingAs($this->reader)->post(route('research.resolutions.step', ['propose']), ['a' => $smit->iri, 'b' => $this->letter->iri])->assertSessionHasErrors('research');
        $this->actingAs($this->reader)->post(route('research.resolutions.step', ['propose']), ['a' => $smit->iri, 'b' => $smith->iri, 'confidence' => 0.8, 'match_method' => 'Same signature',
            'evidence' => "Letter of 1898\nMinutes of 1899"])->assertSessionHasNoErrors();
        $this->actingAs($this->reader)->post(route('research.resolutions.step', ['propose']), ['a' => $smith->iri, 'b' => $smit->iri])->assertSessionHasErrors('research');
        $resolution = ResearchEntityResolution::sole();
        $this->assertSame(['Letter of 1898', 'Minutes of 1899'], $resolution->evidence);
        $this->actingAs($this->reader)->post(route('research.resolutions.step', ['accept', $resolution]))->assertSessionHasErrors('research');
        $this->actingAs($this->admin)->post(route('research.resolutions.step', ['accept', $resolution]), ['notes' => 'Checked the signatures'])->assertSessionHasNoErrors();
        $this->assertSame(['accepted', $this->admin->id], [$resolution->fresh()->status->notation, $resolution->fresh()->resolver_id]);
        $this->actingAs($this->admin)->get(route('research.resolutions', ['status' => 'accepted']))->assertOk()->assertSee(route('agents.merge-form', $smith), false);

        // A search kept to the fonds chosen, logged.
        $page = $this->actingAs($this->reader)->get(route('research.across', ['q' => 'bridge', 'fonds' => [$this->fonds->id]]))->assertOk();
        $page->assertSee('Letter about the parish bridge')->assertDontSee('Bridge over the harbour mouth')->assertSee('2 results');
        $this->assertSame(2, ResearchCrossFondsQuery::sole()->results_count);

        // The project described in the catalogue: by who may create authority records, once it is public; done again, it is updated, not made twice.
        app(Projects::class)->saveOutput($this->reader, $this->project, ['title' => 'Iron over the river', 'output_type' => 'journal-article', 'status' => 'published', 'venue' => 'Historia']);
        $this->actingAs($this->admin)->post(route('research.analysis.describe', $this->project))->assertSessionHasErrors('research');
        app(Projects::class)->update($this->reader, $this->project, ['title' => 'The parish bridge', 'visibility' => 'public', 'start_date' => '2026-01-01', 'expected_end_date' => '2027-06-30',
            'description' => 'How the iron bridge was paid for.']);
        $this->actingAs($this->reader)->post(route('research.analysis.describe', $this->project))->assertSessionHasErrors('research');
        $this->actingAs($this->admin)->post(route('research.analysis.describe', $this->project), ['performer_iri' => $smit->iri])->assertRedirect();
        $this->actingAs($this->admin)->post(route('research.analysis.describe', $this->project), ['performer_iri' => $smit->iri])->assertRedirect();
        $this->assertSame(2, Activity::count());
        $activity = app(RicBridge::class)->of($this->project->fresh());
        $this->assertSame('The parish bridge', $activity->described('activity-name', ['en']));
        $this->assertSame('2026-01-01/2027-06-30', $activity->dates()->sole()->normalized_value);
        $relations = Relation::where('source_id', $activity->id)->with('relationType')->get()->pluck('relationType.iri')->map(fn ($i) => basename($i))->sort()->values()->all();
        $this->assertSame(['performance', 'subevent'], $relations);
    }

    public function test_reports_with_their_sections_and_peer_review_and_the_analytics(): void
    {
        $collection = app(Collections::class)->save($this->reader, ['name' => 'Bridge sources']);
        app(Collections::class)->add($this->reader, $collection, $this->letter);
        app(Projects::class)->assign($this->reader, $collection, $this->project);
        $this->actingAs($this->reader)->post(route('research.reports.store'), ['title' => 'Paying for the bridge', 'template_type' => 'finding-aid', 'project_id' => $this->project->id])->assertSessionHasNoErrors();
        $report = ResearchReport::sole();
        $this->assertSame(['title-page', 'toc', 'heading', 'text', 'text', 'collection-list', 'text'], $report->sections()->with('sectionType')->get()->pluck('sectionType.notation')->all());
        $this->actingAs($this->reader)->post(route('research.reports.step', [$report, 'fill']))->assertSessionHasNoErrors();
        $this->assertSame($collection->id, $report->sections()->whereNotNull('collection_id')->value('collection_id'));
        $this->assertSame('How the iron bridge was paid for.', $report->sections()->whereNotNull('content')->value('content'));
        $last = $report->sections()->reorder('sort_order', 'desc')->first();
        $this->actingAs($this->reader)->post(route('research.reports.section', [$last, 'up']))->assertSessionHasNoErrors();
        $this->assertSame($last->id, $report->sections()->reorder('sort_order', 'desc')->skip(1)->first()->id);
        $this->actingAs($this->reader)->get(route('research.reports.show', [$report, 'print' => 1]))->assertOk()->assertSee('Paying for the bridge')->assertSee('RA PC/1 Letter about the parish bridge');
        $this->actingAs($this->admin)->get(route('research.reports.show', $report))->assertNotFound();

        // A peer reviewer from the institution, who may then read it; the author is told.
        $this->actingAs($this->reader)->post(route('research.reports.step', [$report, 'review']), ['email' => 'rita@example.org'])->assertSessionHasErrors('research');
        $this->actingAs($this->reader)->post(route('research.reports.step', [$report, 'review']), ['email' => 'admin@example.org'])->assertSessionHasNoErrors();
        Mail::assertQueued(ResearchMail::class, fn ($m) => $m->hasTo('admin@example.org'));
        $review = $report->peerReviews()->sole();
        $this->actingAs($this->admin)->get(route('research.reports.show', $report))->assertOk();
        $this->actingAs($this->other)->post(route('research.reports.peer-review', [$review, 'complete']), ['feedback' => 'x'])->assertSessionHasErrors('research');
        $this->actingAs($this->admin)->post(route('research.reports.peer-review', [$review, 'complete']), ['feedback' => 'Cite the toll books.', 'rating' => 6])->assertSessionHasErrors();
        $this->actingAs($this->admin)->post(route('research.reports.peer-review', [$review, 'complete']), ['feedback' => 'Cite the toll books.', 'rating' => 4])->assertSessionHasNoErrors();
        $this->assertSame(['completed', 4], [$review->fresh()->status->notation, $review->fresh()->rating]);
        Mail::assertQueued(ResearchMail::class, fn ($m) => $m->hasTo('rita@example.org') && str_contains(json_encode($m->lines), 'has reviewed'));
        $this->actingAs($this->admin)->post(route('research.comments.store', ['report', $report->id]), ['content' => 'See my review.'])->assertSessionHasNoErrors();
        $this->actingAs($this->other)->get(route('research.reports.show', $report))->assertOk()->assertSee('See my review.');

        $this->actingAs($this->reader)->get(route('research.analytics'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('research.analytics', ['from' => '2026-11-01', 'to' => '2026-11-30']))->assertOk()->assertSee('Records collected by researchers')
            ->assertSee('Letter about the parish bridge');
    }
}
